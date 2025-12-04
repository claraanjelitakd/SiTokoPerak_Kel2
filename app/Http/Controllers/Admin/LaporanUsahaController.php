<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;      // ⬅️ untuk query builder
use App\Models\Usaha;       
use App\Models\User;          
use App\Models\KategoriProduk;

class LaporanUsahaController extends Controller
{
    /**
     * Dashboard laporan utama
     * URL: /admin/laporan_usaha
     * Route name: admin.laporan.index
     */
    public function index(Request $request)
    {
        // ---------- 1. DATA FILTER (TAHUN / BULAN / USAHA / KATEGORI / USER) ----------
        // contoh: 5 tahun terakhir
        $currentYear = now()->year;
        $startYear   = $currentYear - 5;

        $tahunList = range($startYear, $currentYear);   // [2019, 2020, 2021, ...]
        rsort($tahunList); // urut dari terbesar ke kecil

        $bulanList = [
            1  => 'Januari',
            2  => 'Februari',
            3  => 'Maret',
            4  => 'April',
            5  => 'Mei',
            6  => 'Juni',
            7  => 'Juli',
            8  => 'Agustus',
            9  => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $usahaList    = Usaha::all();
        $kategoriList = KategoriProduk::all();
        $userList     = User::all();

        // ---------- 2. BASE QUERY UNTUK ORDER & ITEM ----------
        /**
         * ASUMSI STRUKTUR:
         * - orders: id, user_id, total, created_at
         * - order_items: id, order_id, produk_id, qty, price
         * - produk: id, nama_produk, kategori_id
         * - usaha_produk: usaha_id, produk_id
         * - usaha: id, nama_usaha
         * - kategori_produk: id, nama_kategori_produk
         * - users: id, username
         *
         * Kalau di DB kamu beda, tinggal sesuaikan nama kolom/join-nya.
         */
        $base = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('produk', 'order_items.produk_id', '=', 'produk.id')
            ->leftJoin('usaha_produk', 'produk.id', '=', 'usaha_produk.produk_id')
            ->leftJoin('usaha', 'usaha_produk.usaha_id', '=', 'usaha.id')
            ->leftJoin('kategori_produk', 'produk.kategori_id', '=', 'kategori_produk.id') // sesuaikan kalau beda
            ->leftJoin('users', 'orders.user_id', '=', 'users.id');

        // ---------- 3. APLIKASI FILTER DARI FORM ----------
        if ($request->filled('tahun')) {
            $base->whereYear('orders.created_at', $request->tahun);
        }

        if ($request->filled('bulan')) {
            $base->whereMonth('orders.created_at', $request->bulan);
        }

        if ($request->filled('usaha_id')) {
            $base->where('usaha.id', $request->usaha_id);
        }

        if ($request->filled('kategori_id')) {
            $base->where('kategori_produk.id', $request->kategori_id);
        }

        if ($request->filled('user_id')) {
            $base->where('users.id', $request->user_id);
        }

        // supaya bisa dipakai berkali-kali
        $baseQuery = clone $base;

        // ---------- 4. METRIC ATAS ----------
        // Total transaksi (jumlah order unik)
        $totalTransaksi = (clone $baseQuery)
            ->distinct('orders.id')
            ->count('orders.id');

        // Total pendapatan (SUM(qty * price))
        $totalPendapatan = (clone $baseQuery)
            ->selectRaw('SUM(order_items.qty * order_items.price) as total') // sesuaikan nama kolom
            ->value('total') ?? 0;

        // ---------- 5. PENDAPATAN TOP 3 USAHA (BAR CHART) ----------
        $pendapatanPerUsaha = (clone $baseQuery)
            ->selectRaw('COALESCE(usaha.nama_usaha, "Tanpa Usaha") as nama_usaha')
            ->selectRaw('SUM(order_items.qty * order_items.price) as total')
            ->groupBy('usaha.id', 'usaha.nama_usaha')
            ->orderByDesc('total')
            ->limit(3)
            ->get();

        $pendapatanChart = [
            'labels' => $pendapatanPerUsaha->pluck('nama_usaha'),
            'data'   => $pendapatanPerUsaha->pluck('total'),
        ];

        // ---------- 6. TOP PRODUK TERLARIS (METRIC + CHART) ----------
        // Produk terlaris (metric text)
        $topProdukRow = (clone $baseQuery)
            ->selectRaw('produk.nama_produk, SUM(order_items.qty) as total_qty')
            ->groupBy('produk.id', 'produk.nama_produk')
            ->orderByDesc('total_qty')
            ->first();

        $topProduk = $topProdukRow->nama_produk ?? null;

        // Chart top 3 produk terlaris
        $produkTerlaris = (clone $baseQuery)
            ->selectRaw('produk.nama_produk, SUM(order_items.qty) as total_qty')
            ->groupBy('produk.id', 'produk.nama_produk')
            ->orderByDesc('total_qty')
            ->limit(3)
            ->get();

        $produkTerlarisChart = [
            'labels' => $produkTerlaris->pluck('nama_produk'),
            'data'   => $produkTerlaris->pluck('total_qty'),
        ];

        // ---------- 7. TOP USER AKTIF (METRIC + CHART) ----------
        // Metric: user dengan transaksi terbanyak
        $userAktifRow = (clone $baseQuery)
            ->selectRaw('users.username, COUNT(DISTINCT orders.id) as total_transaksi')
            ->groupBy('users.id', 'users.username')
            ->orderByDesc('total_transaksi')
            ->first();

        $userAktif = $userAktifRow->username ?? null;

        // Chart: top 3 user aktif
        $userAktifList = (clone $baseQuery)
            ->selectRaw('users.username, COUNT(DISTINCT orders.id) as total_transaksi')
            ->groupBy('users.id', 'users.username')
            ->orderByDesc('total_transaksi')
            ->limit(3)
            ->get();

        $transaksiUserChart = [
            'labels' => $userAktifList->pluck('username'),
            'data'   => $userAktifList->pluck('total_transaksi'),
        ];

        // ---------- 8. TOP 3 KATEGORI PRODUK (DONUT CHART) ----------
        $kategoriTerjual = (clone $baseQuery)
            ->selectRaw('kategori_produk.nama_kategori_produk, SUM(order_items.qty) as total_qty')
            ->groupBy('kategori_produk.id', 'kategori_produk.nama_kategori_produk')
            ->orderByDesc('total_qty')
            ->limit(3)
            ->get();

        $kategoriChart = [
            'labels' => $kategoriTerjual->pluck('nama_kategori_produk'),
            'data'   => $kategoriTerjual->pluck('total_qty'),
        ];

        // ---------- 9. CHART FAVORITE & VIEWS (UNTUK SEMENTARA KOSONG) ----------
        // Karena kita belum tahu struktur tabel like/favorite & views-mu,
        // untuk sementara dikosongkan saja. Chart.js di Blade sudah handle kondisi "no data".
        $produkFavoriteChart = [
            'labels' => [],
            'data'   => [],
        ];

        $produkViewChart = [
            'labels' => [],
            'data'   => [],
        ];

        // ---------- 10. RETURN KE VIEW ----------
        return view('admin.laporan_usaha.laporan', compact(
            'tahunList',
            'bulanList',
            'usahaList',
            'kategoriList',
            'userList',
            'totalTransaksi',
            'totalPendapatan',
            'pendapatanChart',
            'produkTerlarisChart',
            'produkFavoriteChart',
            'produkViewChart',
            'transaksiUserChart',
            'kategoriChart',
            'topProduk',
            'userAktif',
        ));
    }

    /**
     * Laporan transaksi
     * URL: /admin/laporan_usaha/transaksi
     * Route name: admin.laporan.transaksi
     */
    public function transaksi()
    {
        return view('admin.laporan_usaha.transaksi');
    }

    /**
     * Laporan pendapatan usaha
     * URL: /admin/laporan_usaha/pendapatan-usaha
     * Route name: admin.laporan.pendapatan-usaha
     */
    public function pendapatanUsaha()
    {
        return view('admin.laporan_usaha.pendapatan_usaha');
    }

    /**
     * Laporan produk terlaris
     * URL: /admin/laporan_usaha/produk-terlaris
     * Route name: admin.laporan.produk-terlaris
     */
    public function produkTerlaris()
    {
        return view('admin.laporan_usaha.produk_terlaris');
    }

    /**
     * Laporan produk slow moving
     * URL: /admin/laporan_usaha/produk-slow-moving
     * Route name: admin.laporan.produk-slow-moving
     */
    public function produkSlowMoving()
    {
        return view('admin.laporan_usaha.produk_slow_moving');
    }

    /**
     * Laporan transaksi per user
     * URL: /admin/laporan_usaha/transaksi-user
     * Route name: admin.laporan.transaksi-user
     */
    public function transaksiUser()
    {
        return view('admin.laporan_usaha.transaksi_user');
    }

    /**
     * Laporan kategori produk
     * URL: /admin/laporan_usaha/kategori-produk
     * Route name: admin.laporan.kategori-produk
     */
    public function kategoriProduk()
    {
        return view('admin.laporan_usaha.kategori_produk');
    }

    /**
     * Laporan produk favorit
     * URL: /admin/laporan_usaha/produk-favorite
     * Route name: admin.laporan.produk-favorite
     */
    public function produkFavorite()
    {
        return view('admin.laporan_usaha.produk_favorite');
    }

    /**
     * Laporan produk berdasarkan views
     * URL: /admin/laporan_usaha/produk-views
     * Route name: admin.laporan.produk-views
     */
    public function produkViews()
    {
        return view('admin.laporan_usaha.produk_views');
    }
}

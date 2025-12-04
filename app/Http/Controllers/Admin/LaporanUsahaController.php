<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Usaha;
use App\Models\User;
use App\Models\KategoriProduk;
use App\Models\Produk;
use Carbon\Carbon;

class LaporanUsahaController extends Controller
{
    /**
     * Dashboard laporan utama
     * URL: /admin/laporan-usaha
     * Route name: admin.laporan.index
     */
    public function index(Request $request)
    {
        // ---------- 1. DATA FILTER (TAHUN / BULAN / USAHA / KATEGORI / USER) ----------
        $currentYear = now()->year;
        $startYear = $currentYear - 5;

        $tahunList = range($startYear, $currentYear);
        rsort($tahunList);

        $bulanList = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $usahaList = Usaha::all();
        $kategoriList = KategoriProduk::all();
        $userList = User::all();

        // ---------- 2. BASE QUERY UTAMA (TANPA USAHA) ----------
        $base = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('produk', 'order_items.produk_id', '=', 'produk.id')
            ->leftJoin('kategori_produk', 'produk.kategori_produk_id', '=', 'kategori_produk.id')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id');

        // ---------- 3. APLIKASI FILTER (kecuali usaha) ----------
        if ($request->filled('tahun')) {
            $base->whereYear('orders.created_at', $request->tahun);
        }

        if ($request->filled('bulan')) {
            $base->whereMonth('orders.created_at', $request->bulan);
        }

        if ($request->filled('kategori_id')) {
            $base->where('kategori_produk.id', $request->kategori_id);
        }

        if ($request->filled('user_id')) {
            $base->where('users.id', $request->user_id);
        }

        // Filter usaha: baru JOIN ke usaha kalau memang difilter
        if ($request->filled('usaha_id')) {
            $base->join('usaha_produk', 'order_items.usaha_produk_id', '=', 'usaha_produk.id')
                ->join('usaha', 'usaha_produk.usaha_id', '=', 'usaha.id')
                ->where('usaha.id', $request->usaha_id);
        }

        $baseQuery = clone $base;

        // ---------- 4. METRIC ATAS ----------
        $totalTransaksi = (clone $baseQuery)
            ->distinct('orders.id')
            ->count('orders.id');

        $totalPendapatan = (clone $baseQuery)
            ->selectRaw('SUM(order_items.quantity * order_items.price_at_purchase) as total')
            ->value('total') ?? 0;

        // ---------- 5. PENDAPATAN PER USAHA ----------
        $baseUsaha = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('produk', 'order_items.produk_id', '=', 'produk.id')
            ->join('usaha_produk', 'order_items.usaha_produk_id', '=', 'usaha_produk.id')
            ->join('usaha', 'usaha_produk.usaha_id', '=', 'usaha.id')
            ->leftJoin('kategori_produk', 'produk.kategori_produk_id', '=', 'kategori_produk.id')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id');

        if ($request->filled('tahun')) {
            $baseUsaha->whereYear('orders.created_at', $request->tahun);
        }
        if ($request->filled('bulan')) {
            $baseUsaha->whereMonth('orders.created_at', $request->bulan);
        }
        if ($request->filled('kategori_id')) {
            $baseUsaha->where('kategori_produk.id', $request->kategori_id);
        }
        if ($request->filled('user_id')) {
            $baseUsaha->where('users.id', $request->user_id);
        }
        if ($request->filled('usaha_id')) {
            $baseUsaha->where('usaha.id', $request->usaha_id);
        }

        $pendapatanPerUsaha = (clone $baseUsaha)
            ->selectRaw('usaha.nama_usaha, SUM(order_items.quantity * order_items.price_at_purchase) as total')
            ->groupBy('usaha.id', 'usaha.nama_usaha')
            ->orderByDesc('total')
            ->limit(3)
            ->get();

        $pendapatanChart = [
            'labels' => $pendapatanPerUsaha->pluck('nama_usaha'),
            'data' => $pendapatanPerUsaha->pluck('total'),
        ];

        // ---------- 6. TOP PRODUK ----------
        $topProdukRow = (clone $baseQuery)
            ->selectRaw('produk.nama_produk, SUM(order_items.quantity) as total_qty')
            ->groupBy('produk.id', 'produk.nama_produk')
            ->orderByDesc('total_qty')
            ->first();

        $topProduk = $topProdukRow->nama_produk ?? null;

        $produkTerlaris = (clone $baseQuery)
            ->selectRaw('produk.nama_produk, SUM(order_items.quantity) as total_qty')
            ->groupBy('produk.id', 'produk.nama_produk')
            ->orderByDesc('total_qty')
            ->limit(3)
            ->get();

        $produkTerlarisChart = [
            'labels' => $produkTerlaris->pluck('nama_produk'),
            'data' => $produkTerlaris->pluck('total_qty'),
        ];

        // ---------- 7. TOP USER ----------
        $userAktifRow = (clone $baseQuery)
            ->selectRaw('users.username, COUNT(DISTINCT orders.id) as total_transaksi')
            ->groupBy('users.id', 'users.username')
            ->orderByDesc('total_transaksi')
            ->first();

        $userAktif = $userAktifRow->username ?? null;

        $userAktifList = (clone $baseQuery)
            ->selectRaw('users.username, COUNT(DISTINCT orders.id) as total_transaksi')
            ->groupBy('users.id', 'users.username')
            ->orderByDesc('total_transaksi')
            ->limit(3)
            ->get();

        $transaksiUserChart = [
            'labels' => $userAktifList->pluck('username'),
            'data' => $userAktifList->pluck('total_transaksi'),
        ];

        // ---------- 8. TOP KATEGORI ----------
        $kategoriTerjual = (clone $baseQuery)
            ->selectRaw('kategori_produk.nama_kategori_produk, SUM(order_items.quantity) as total_qty')
            ->groupBy('kategori_produk.id', 'kategori_produk.nama_kategori_produk')
            ->orderByDesc('total_qty')
            ->limit(3)
            ->get();

        $kategoriChart = [
            'labels' => $kategoriTerjual->pluck('nama_kategori_produk'),
            'data' => $kategoriTerjual->pluck('total_qty'),
        ];

        // ---------- 9. PRODUK FAVORITE & VIEWS ----------
        $produkFavorite = DB::table('produk_likes as pl')
            ->join('produk as p', 'p.id', '=', 'pl.produk_id')
            ->selectRaw('p.nama_produk, COUNT(pl.id) as total_like')
            ->groupBy('p.id', 'p.nama_produk')
            ->orderByDesc('total_like')
            ->limit(3)
            ->get();

        $produkFavoriteChart = [
            'labels' => $produkFavorite->pluck('nama_produk'),
            'data' => $produkFavorite->pluck('total_like'),
        ];

        $produkViews = DB::table('produk_views as pv')
            ->join('produk as p', 'p.id', '=', 'pv.produk_id')
            ->selectRaw('p.nama_produk, COUNT(pv.id) as total_view')
            ->groupBy('p.id', 'p.nama_produk')
            ->orderByDesc('total_view')
            ->limit(3)
            ->get();

        $produkViewChart = [
            'labels' => $produkViews->pluck('nama_produk'),
            'data' => $produkViews->pluck('total_view'),
        ];

        // ---------- 10. RETURN ----------
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
     * Laporan Kategori Produk
     * route: admin.laporan.kategoriProduk
     * view: admin.laporan_usaha.kategori_produk
     */
    public function kategoriProduk(Request $request)
    {
        $usahaList = Usaha::all();

        $query = DB::table('kategori_produk as k')
            ->leftJoin('produk as p', 'p.kategori_produk_id', '=', 'k.id')
            ->leftJoin('order_items as oi', 'oi.produk_id', '=', 'p.id')
            ->leftJoin('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('usaha_produk as up', 'up.id', '=', 'oi.usaha_produk_id')
            ->leftJoin('usaha as u', 'u.id', '=', 'up.usaha_id');

        if ($request->filled('usaha_id')) {
            $query->where('u.id', $request->usaha_id);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('o.created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('o.created_at', '<=', $request->end_date);
        }

        $laporan = $query
            ->groupBy('k.id', 'k.nama_kategori_produk')
            ->selectRaw('
                k.nama_kategori_produk,
                COUNT(DISTINCT p.id) as total_produk,
                COALESCE(SUM(oi.quantity), 0) as total_terjual
            ')
            ->orderBy('k.nama_kategori_produk')
            ->get();

        return view('admin.laporan_usaha.kategori_produk', compact('usahaList', 'laporan'));
    }

    /**
     * Laporan Pendapatan Per Usaha
     * route: admin.laporan.pendapatanUsaha
     * view: admin.laporan_usaha.pendapatan_usaha
     */
    public function pendapatanUsaha(Request $request)
    {
        $usahaList = Usaha::all();
        $kategoriList = KategoriProduk::all();

        $query = DB::table('orders as o')
            ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('usaha_produk as up', 'up.id', '=', 'oi.usaha_produk_id')
            ->join('usaha as u', 'u.id', '=', 'up.usaha_id')
            ->join('produk as p', 'p.id', '=', 'up.produk_id')
            ->leftJoin('kategori_produk as k', 'k.id', '=', 'p.kategori_produk_id');

        if ($request->filled('usaha_id')) {
            $query->where('u.id', $request->usaha_id);
        }
        if ($request->filled('kategori_id')) {
            $query->where('k.id', $request->kategori_id);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('o.created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('o.created_at', '<=', $request->end_date);
        }

        $laporan = $query
            ->groupBy('u.id', 'u.nama_usaha')
            ->selectRaw('
                u.nama_usaha,
                COUNT(DISTINCT o.id) as total_transaksi,
                SUM(oi.quantity * oi.price_at_purchase) as total_penjualan,
                SUM(oi.quantity * oi.price_at_purchase) / NULLIF(COUNT(DISTINCT o.id), 0) as rata_rata_transaksi,
                MAX(o.created_at) as transaksi_terakhir
            ')
            ->orderByDesc('total_penjualan')
            ->get();

        $totalUsaha = $laporan->count();
        $totalTransaksi = (int) $laporan->sum('total_transaksi');
        $totalPendapatan = (int) $laporan->sum('total_penjualan');
        $avgTransaksiGlobal = $totalTransaksi > 0
            ? (int) floor($totalPendapatan / $totalTransaksi)
            : 0;

        return view('admin.laporan_usaha.pendapatan_usaha', compact(
            'usahaList',
            'kategoriList',
            'laporan',
            'totalUsaha',
            'totalTransaksi',
            'totalPendapatan',
            'avgTransaksiGlobal',
        ));
    }

    /**
     * Laporan Produk Favorite (Like)
     * route: admin.laporan.produkFavorite
     * view: admin.laporan_usaha.produk_favorite
     */
    public function produkFavorite(Request $request)
    {
        $usahaList = Usaha::all();

        $query = DB::table('produk as p')
            ->leftJoin('produk_likes as pl', 'pl.produk_id', '=', 'p.id')
            ->leftJoin('usaha_produk as up', 'up.produk_id', '=', 'p.id')
            ->leftJoin('usaha as u', 'u.id', '=', 'up.usaha_id');

        if ($request->filled('usaha_id')) {
            $query->where('u.id', $request->usaha_id);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('pl.created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('pl.created_at', '<=', $request->end_date);
        }

        $laporan = $query
            ->groupBy('p.id', 'p.nama_produk')
            ->selectRaw('p.nama_produk, COUNT(pl.id) as total_like')
            ->orderByDesc('total_like')
            ->get();

        // Total produk di sistem (ringkasan)
        $totalProduk = Produk::count();

        return view('admin.laporan_usaha.produk_favorite', compact(
            'usahaList',
            'laporan',
            'totalProduk',
        ));
    }

    /**
     * Laporan Produk Slow Moving
     * route: admin.laporan.produkSlowMoving
     * view: admin.laporan_usaha.produk_slow_moving
     */
    public function produkSlowMoving(Request $request)
    {
        $usahaList = Usaha::all();
        $kategoriList = KategoriProduk::all();

        $threshold = 5;

        $start = $request->input('start_date');
        $end = $request->input('end_date');

        if (!$start && !$end) {
            $end = Carbon::now()->toDateString();
            $start = Carbon::now()->subDays(30)->toDateString();
        } else {
            if (!$start) {
                $start = $end;
            }
            if (!$end) {
                $end = $start;
            }
        }

        $query = DB::table('produk as p')
            ->leftJoin('kategori_produk as k', 'k.id', '=', 'p.kategori_produk_id')
            ->leftJoin('usaha_produk as up', 'up.produk_id', '=', 'p.id')
            ->leftJoin('usaha as u', 'u.id', '=', 'up.usaha_id')
            ->leftJoin('order_items as oi', function ($join) use ($start, $end) {
                $join->on('oi.usaha_produk_id', '=', 'up.id')
                    ->whereDate('oi.created_at', '>=', $start)
                    ->whereDate('oi.created_at', '<=', $end);
            });

        if ($request->filled('usaha_id')) {
            $query->where('u.id', $request->usaha_id);
        }
        if ($request->filled('kategori_id')) {
            $query->where('k.id', $request->kategori_id);
        }

        $laporan = $query
            ->groupBy('p.id', 'p.nama_produk', 'u.id', 'u.nama_usaha')
            ->selectRaw('
                u.nama_usaha,
                p.nama_produk,
                COALESCE(SUM(oi.quantity), 0) as total_terjual,
                MAX(oi.created_at) as transaksi_terakhir
            ')
            ->havingRaw('COALESCE(SUM(oi.quantity), 0) < ?', [$threshold])
            ->orderBy('total_terjual', 'asc')
            ->orderBy('p.nama_produk')
            ->get();

        $totalProdukSlow = $laporan->count();
        $totalQtyTerjual = (int) $laporan->sum('total_terjual');

        return view('admin.laporan_usaha.produk_slow_moving', compact(
            'usahaList',
            'kategoriList',
            'laporan',
            'start',
            'end',
            'threshold',
            'totalProdukSlow',
            'totalQtyTerjual',
        ));
    }

    /**
     * Laporan Produk Terlaris
     * route: admin.laporan.produkTerlaris
     * view: admin.laporan_usaha.produk_terlaris
     */
    public function produkTerlaris(Request $request)
    {
        $usahaList = Usaha::all();
        $kategoriList = KategoriProduk::all();

        $query = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('usaha_produk as up', 'up.id', '=', 'oi.usaha_produk_id')
            ->join('usaha as us', 'us.id', '=', 'up.usaha_id')
            ->join('produk as p', 'p.id', '=', 'oi.produk_id');

        if ($request->filled('usaha_id')) {
            $query->where('us.id', $request->usaha_id);
        }
        if ($request->filled('kategori_id')) {
            $query->where('p.kategori_produk_id', $request->kategori_id);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('o.created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('o.created_at', '<=', $request->end_date);
        }

        $laporan = $query
            ->groupBy('p.id', 'p.nama_produk')
            ->selectRaw('p.nama_produk, SUM(oi.quantity) as total_terjual')
            ->orderByDesc('total_terjual')
            ->get();

        $totalProduk = $laporan->count();
        $totalTerjual = (int) $laporan->sum('total_terjual');
        $topRow = $laporan->first();
        $chartData = $laporan->take(10)->values();

        return view('admin.laporan_usaha.produk_terlaris', compact(
            'usahaList',
            'kategoriList',
            'laporan',
            'totalProduk',
            'totalTerjual',
            'topRow',
            'chartData',
        ));
    }

    /**
     * Laporan Views Produk
     * route: admin.laporan.produkViews
     * view: admin.laporan_usaha.produk_views
     */
    public function produkViews(Request $request)
    {
        $usahaList = Usaha::all();

        // Total produk (optionally filter by usaha)
        $produkBase = DB::table('produk as p')
            ->leftJoin('usaha_produk as up', 'up.produk_id', '=', 'p.id')
            ->leftJoin('usaha as u', 'u.id', '=', 'up.usaha_id');

        if ($request->filled('usaha_id')) {
            $produkBase->where('u.id', $request->usaha_id);
        }

        $totalProduk = $produkBase->distinct('p.id')->count('p.id');

        // Views per produk
        $viewsQuery = DB::table('produk as p')
            ->join('produk_views as pv', 'pv.produk_id', '=', 'p.id')
            ->leftJoin('usaha_produk as up', 'up.produk_id', '=', 'p.id')
            ->leftJoin('usaha as u', 'u.id', '=', 'up.usaha_id');

        if ($request->filled('usaha_id')) {
            $viewsQuery->where('u.id', $request->usaha_id);
        }
        if ($request->filled('start_date')) {
            $viewsQuery->whereDate('pv.created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $viewsQuery->whereDate('pv.created_at', '<=', $request->end_date);
        }

        $produkViews = $viewsQuery
            ->groupBy('p.id', 'p.nama_produk')
            ->selectRaw('p.nama_produk, COUNT(pv.id) as total_views')
            ->orderByDesc('total_views')
            ->get();

        $produkDenganViews = $produkViews->count();
        $totalViews = (int) $produkViews->sum('total_views');

        return view('admin.laporan_usaha.produk_views', compact(
            'usahaList',
            'totalProduk',
            'produkDenganViews',
            'totalViews',
            'produkViews',
        ));
    }

    /**
     * Laporan Transaksi Per User
     * route: admin.laporan.transaksiUser
     * view: admin.laporan_usaha.transaksi_user
     */
    public function transaksiUser(Request $request)
    {
        $usahaList = Usaha::all();
        $kategoriList = KategoriProduk::all();

        $query = DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('usaha_produk as up', 'up.id', '=', 'oi.usaha_produk_id')
            ->join('usaha as us', 'us.id', '=', 'up.usaha_id')
            ->join('produk as p', 'p.id', '=', 'oi.produk_id');

        if ($request->filled('usaha_id')) {
            $query->where('us.id', $request->usaha_id);
        }
        if ($request->filled('kategori_id')) {
            $query->where('p.kategori_produk_id', $request->kategori_id);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('o.created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('o.created_at', '<=', $request->end_date);
        }

        $laporan = $query
            ->groupBy('u.id', 'u.username')
            ->selectRaw('
                u.username,
                COUNT(DISTINCT o.id) as total_transaksi,
                SUM(oi.quantity * oi.price_at_purchase) as total_belanja
            ')
            ->orderByDesc('total_belanja')
            ->get();

        $totalUser = $laporan->count();
        $totalTransaksi = (int) $laporan->sum('total_transaksi');
        $totalBelanja = (int) $laporan->sum('total_belanja');

        return view('admin.laporan_usaha.transaksi_user', compact(
            'usahaList',
            'kategoriList',
            'laporan',
            'totalUser',
            'totalTransaksi',
            'totalBelanja',
        ));
    }

    /**
     * Laporan Semua Transaksi
     * route: admin.laporan.transaksi
     * view: admin.laporan_usaha.transaksi
     */
    public function transaksi(Request $request)
    {
        $usahaList = Usaha::all();
        $kategoriList = KategoriProduk::all();
        $userList = User::all();

        $statusList = ['baru', 'dibayar', 'diproses', 'dikirim', 'selesai', 'dibatalkan'];

        $base = DB::table('orders as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->leftJoin('usaha_produk as up', 'up.id', '=', 'oi.usaha_produk_id')
            ->leftJoin('usaha as us', 'us.id', '=', 'up.usaha_id')
            ->leftJoin('produk as p', 'p.id', '=', 'oi.produk_id')
            ->leftJoin('kategori_produk as k', 'k.id', '=', 'p.kategori_produk_id');

        if ($request->filled('usaha_id')) {
            $base->where('us.id', $request->usaha_id);
        }
        if ($request->filled('kategori_id')) {
            $base->where('k.id', $request->kategori_id);
        }
        if ($request->filled('user_id')) {
            $base->where('u.id', $request->user_id);
        }
        if ($request->filled('status')) {
            $base->where('o.status', $request->status);
        }
        if ($request->filled('start_date')) {
            $base->whereDate('o.created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $base->whereDate('o.created_at', '<=', $request->end_date);
        }

        $transaksi = $base
            ->groupBy('o.id', 'u.username', 'o.customer_name', 'o.total_amount', 'o.status', 'o.created_at')
            ->selectRaw('
                o.id,
                COALESCE(u.username, o.customer_name) as username,
                o.total_amount as total,
                DATE_FORMAT(o.created_at, "%d-%m-%Y %H:%i") as tanggal_transaksi,
                o.status
            ')
            ->orderByDesc('o.created_at')
            ->get();

        $totalTransaksi = $transaksi->count();
        $totalNominal = (int) $transaksi->sum('total');

        return view('admin.laporan_usaha.transaksi', compact(
            'usahaList',
            'kategoriList',
            'userList',
            'statusList',
            'transaksi',
            'totalTransaksi',
            'totalNominal',
        ));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class LaporanUsahaController extends Controller
{
    /**
     * Dashboard laporan utama
     * URL: /admin/laporan_usaha
     * Route name: admin.laporan.index
     */
    public function index()
    {
        return view('admin.laporan_usaha.laporan');
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

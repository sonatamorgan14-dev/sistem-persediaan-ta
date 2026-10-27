<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor BAST tidak lagi diisi saat penerimaan dikonfirmasi, melainkan saat
 * BAST dicetak pertama kali. Nomornya berasal dari luar sistem (agenda
 * kantor), dan pada saat barang diterima nomor itu sering belum ada.
 * Lihat ADR 0042.
 *
 * Penerimaan lama tetap memegang nomornya. Keunikan nomor dijaga validasi,
 * bukan indeks unik, supaya migrasi ini tidak gagal pada data lama yang
 * mungkin sudah memuat nomor kembar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_receipts', function (Blueprint $table) {
            $table->string('bast_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_receipts', function (Blueprint $table) {
            $table->string('bast_number')->nullable(false)->change();
        });
    }
};

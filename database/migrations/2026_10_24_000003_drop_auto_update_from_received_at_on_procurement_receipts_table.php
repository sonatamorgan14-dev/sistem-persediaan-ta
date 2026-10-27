<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuang ON UPDATE CURRENT_TIMESTAMP yang dipasang MariaDB diam-diam pada
 * `procurement_receipts.received_at`.
 *
 * Dengan `explicit_defaults_for_timestamp = OFF` — bawaan MariaDB 10.4 di
 * XAMPP — kolom TIMESTAMP NOT NULL pertama tanpa DEFAULT eksplisit otomatis
 * menjadi `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. Selama baris
 * penerimaan tidak pernah diubah, itu tidak terasa. Sejak nomor BAST diisi
 * menyusul (ADR 0042), setiap pengisian nomor menimpa tanggal penerimaan
 * dengan waktu saat itu — padahal tanggal itulah yang tercetak terbilang di
 * BAST. Uji Pest memakai SQLite, yang tidak mengenal perilaku ini.
 *
 * DEFAULT eksplisit cukup untuk mencegahnya: kolom ber-DEFAULT tanpa klausa
 * ON UPDATE tidak diperbarui otomatis. Nilai bawaannya sendiri tidak pernah
 * dipakai, karena aplikasi selalu mengisi `received_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_receipts', function (Blueprint $table) {
            $table->timestamp('received_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_receipts', function (Blueprint $table) {
            $table->timestamp('received_at')->change();
        });
    }
};

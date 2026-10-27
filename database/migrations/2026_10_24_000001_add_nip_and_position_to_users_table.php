<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NIP dan jabatan pegawai, dicetak pada blok identitas BAST pengadaan.
     *
     * Nullable dan murni aditif: tidak semua akun mewakili pegawai yang
     * menandatangani dokumen, dan kolom kosong tercetak sebagai titik-titik
     * untuk diisi tangan. `users` adalah tabel bersama dengan sub sistem
     * Permintaan, jadi kolom ini menunggu persetujuan Alma. Lihat ADR 0042.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nip', 18)->nullable()->after('fullname');
            $table->string('position')->nullable()->after('nip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['nip', 'position']);
        });
    }
};

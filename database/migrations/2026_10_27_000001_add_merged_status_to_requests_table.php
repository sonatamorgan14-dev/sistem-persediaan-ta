<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan umum yang digabung tidak lagi dihapus, melainkan berstatus
 * `merged` dan menunjuk ke permintaan darurat hasil gabungannya, supaya
 * tetap tampil di menu Histori pada halaman Permintaan Umum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->enum('status', ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance', 'completed', 'rejected', 'merged'])
                ->default('submitted')
                ->change();
            $table->foreignUuid('merged_into_request_id')
                ->nullable()
                ->after('status')
                ->constrained('requests', 'request_id')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Sebelum migrasi ini, permintaan yang digabung dihapus.
        DB::table('requests')->where('status', 'merged')->delete();

        Schema::table('requests', function (Blueprint $table) {
            $table->dropForeign(['merged_into_request_id']);
            $table->dropColumn('merged_into_request_id');
            $table->enum('status', ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance', 'completed', 'rejected'])
                ->default('submitted')
                ->change();
        });
    }
};

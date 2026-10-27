<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status permintaan tepat sebelum ditolak, supaya Admin bisa mengembalikan
 * penolakan yang keliru ke tahap semula. Penolakan sebelum migrasi ini tidak
 * tercatat dan karenanya tidak bisa dikembalikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->string('rejected_from_status')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('rejected_from_status');
        });
    }
};

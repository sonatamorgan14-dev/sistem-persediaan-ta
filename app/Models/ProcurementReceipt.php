<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penerimaan barang pengadaan oleh gudang, beserta nomor BAST-nya.
 *
 * BAST-nya adalah serah terima internal dari Pelaksana Belanja kepada
 * Petugas Persediaan (`received_by`), bukan dari penyedia. `bast_number`
 * kosong sampai BAST dicetak pertama kali. Lihat ADR 0042.
 *
 * `received_by` sengaja terpisah dari `Procurement::user_id` (pengaju
 * realisasi) — siapa yang mengadakan bukan siapa yang mengonfirmasi diterima,
 * sesuai pemisahan tugas pada ADR 0008. Lihat ADR 0015.
 *
 * Mencatat **peristiwa** penerimaannya saja: siapa, kapan, nomor BAST berapa.
 * Berkas BAST bertanda tangan hidup di `procurement_documents` dan boleh
 * menyusul — penerimaan tidak menunggunya. Lihat ADR 0018.
 */
class ProcurementReceipt extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_receipt_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'procurement_id', 'bast_number', 'received_by', 'received_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(Procurement::class, 'procurement_id', 'procurement_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }
}

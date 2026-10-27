<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Request extends Model
{
    use HasFactory, HasUuids;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'request_id';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'date',
        'type',
        'status',
        'request_number',
        'bast_number',
        'nd_number',
        'merged_into_request_id',
        'rejected_from_status',
        // TODO: Tambahkan Description field di sini nanti ditambah juga di show request 
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'request_amount' => 'integer',
            'accept_amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_request_id', 'request_id');
    }

    // `details()` dan `procurementRequests()` dibuang 5 September 2026: keduanya
    // dibangun di atas premis yang keliru. `RequestDetail` ternyata bukan
    // rincian permintaan unit — ia salah nama dan salah arah FK sejak desain
    // ERD awal, dan sudah dipindah sepenuhnya ke sisi Pengadaan sebagai
    // App\Models\ProcurementRequestDetail (lihat ADR 0015). `requests` di sini
    // belum punya tabel rincian barangnya sendiri; itu keputusan yang perlu
    // diambil saat sub sistem Permintaan dirancang.

    public function requestDocuments(): HasMany
    {
        return $this->hasMany(RequestDocument::class, 'request_id', 'request_id');
    }


    public function item(): HasMany
    {
        return $this->hasMany(RequestDetail::class, 'request_id', 'request_id')
            ->with(['item', 'itemProposal']);
    }

    public function hasItemProposals(): bool
    {
        return $this->item()->whereNotNull('item_proposal_id')->exists();
    }

    /**
     * Status satu langkah sebelum status sekarang, tujuan Admin saat
     * mengembalikan status. Null berarti tidak bisa dikembalikan: permintaan
     * yang sudah selesai (stok sudah keluar dan BAST terbit), masih di tahap
     * awal, permintaan umum, atau ditolak sebelum status asalnya dicatat.
     */
    public function previousStatus(): ?string
    {
        if ($this->type === 'umum') {
            return null;
        }

        return match ($this->status) {
            'approved_by_head' => 'submitted',
            'processed_by_staff' => 'approved_by_head',
            'in_issuance' => 'processed_by_staff',
            'rejected' => $this->rejected_from_status,
            default => null,
        };
    }
}

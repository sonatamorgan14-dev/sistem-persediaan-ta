<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'user_id';

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
        'username',
        'email',
        'password',
        'role',
        'unit',
        'fullname',
        'nip',
        'position',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'role' => UserRole::class,
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Apakah pengguna memiliki salah satu dari peran yang diberikan.
     */
    public function hasRole(UserRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $role = $role instanceof UserRole ? $role : UserRole::tryFrom($role);

            if ($role !== null && $this->role === $role) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::Admin);
    }

    /**
     * Inisial nama lengkap, untuk avatar teks pada UI.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->fullname ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(mb_substr($parts[0] ?? 'U', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }

    /**
     * Apakah akun ini sudah mengunggah foto profil. Tanpa foto, UI memakai
     * avatar inisial sebagai gantinya (ADR 0038).
     */
    public function punyaFoto(): bool
    {
        return filled($this->avatar_path);
    }

    /**
     * URL foto profil lewat rute terautentikasi, atau null bila belum ada.
     *
     * Parameter `v` diturunkan dari path berkas, yang selalu nama acak baru
     * pada tiap unggahan — sehingga responsnya aman di-cache lama oleh
     * peramban tanpa risiko menampilkan foto yang sudah diganti.
     */
    public function urlFoto(): ?string
    {
        if (! $this->punyaFoto()) {
            return null;
        }

        return route('profile.avatar.show', [
            'user' => $this->user_id,
            'v' => substr(md5($this->avatar_path), 0, 8),
        ]);
    }

    /**
     * Relasi transaksional yang memakai onDelete restrict. Selama masih ada
     * isinya, akun tidak boleh dihapus dan sebaiknya dinonaktifkan saja.
     *
     * @var list<string>
     */
    public const RESTRICTED_RELATIONS = [
        'auditLogs' => 'log aktivitas',
        'stockMutations' => 'riwayat mutasi stok',
        'stockOpnames' => 'stock opname',
        'procurements' => 'pengadaan',
        'procurementRequests' => 'usulan pengadaan',
        'procurementReceipts' => 'penerimaan barang pengadaan',
        'reviewedProcurements' => 'persetujuan realisasi pengadaan',
        'cancelledProcurements' => 'pembatalan realisasi pengadaan',
        'purchasedProcurements' => 'belanja realisasi pengadaan',
        'itemProposals' => 'usulan barang baru',
        'decidedItemProposals' => 'keputusan usulan barang baru',
        'requestDocuments' => 'dokumen permintaan',
    ];

    /**
     * Nama relasi transaksional yang masih memiliki data untuk akun ini.
     *
     * @return array<int, string>
     */
    public function blockingRelations(): array
    {
        $blocking = [];

        foreach (self::RESTRICTED_RELATIONS as $relation => $label) {
            if ($this->{$relation}()->exists()) {
                $blocking[] = $label;
            }
        }

        return $blocking;
    }

    public function requests(): HasMany
    {
        return $this->hasMany(Request::class, 'user_id', 'user_id');
    }

    public function requestDocuments(): HasMany
    {
        return $this->hasMany(RequestDocument::class, 'user_id', 'user_id');
    }

    public function stockMutations(): HasMany
    {
        return $this->hasMany(StockMutation::class, 'user_id', 'user_id');
    }

    public function stockOpnames(): HasMany
    {
        return $this->hasMany(StockOpname::class, 'user_id', 'user_id');
    }

    public function procurements(): HasMany
    {
        return $this->hasMany(Procurement::class, 'user_id', 'user_id');
    }

    public function procurementRequests(): HasMany
    {
        return $this->hasMany(ProcurementRequest::class, 'user_id', 'user_id');
    }

    /**
     * Penerimaan barang yang dikonfirmasi akun ini. Berbeda dari
     * `procurements()` (realisasi yang diajukannya) — pemisahan tugas ADR 0008.
     */
    public function procurementReceipts(): HasMany
    {
        return $this->hasMany(ProcurementReceipt::class, 'received_by', 'user_id');
    }

    /**
     * Realisasi pengadaan yang diputuskan akun ini sebagai Kasubbag Umum dan TI,
     * disetujui maupun ditolak. Berbeda lagi dari `procurements()` dan
     * `procurementReceipts()` — tiga jabatan, tiga relasi (ADR 0030).
     */
    public function reviewedProcurements(): HasMany
    {
        return $this->hasMany(Procurement::class, 'reviewed_by', 'user_id');
    }

    /**
     * Realisasi pengadaan yang dibatalkan akun ini (ADR 0024).
     *
     * Terdaftar belakangan: FK `cancelled_by` sudah restrictOnDelete sejak awal,
     * tetapi relasinya luput dari RESTRICTED_RELATIONS sehingga menghapus akun
     * yang pernah membatalkan realisasi meledak jadi 500 alih-alih pesan
     * validasi ADR 0003.
     */
    public function cancelledProcurements(): HasMany
    {
        return $this->hasMany(Procurement::class, 'cancelled_by', 'user_id');
    }

    /**
     * Realisasi pengadaan yang ditandai sudah dibelanjakan oleh akun ini
     * (ADR 0035). Didaftarkan ke RESTRICTED_RELATIONS bersamaan dengan
     * kolomnya, supaya tidak mengulang kekeliruan `cancelledProcurements()`.
     */
    public function purchasedProcurements(): HasMany
    {
        return $this->hasMany(Procurement::class, 'purchased_by', 'user_id');
    }

    /**
     * Usulan barang baru yang diajukan akun ini (ADR 0032).
     */
    public function itemProposals(): HasMany
    {
        return $this->hasMany(ItemProposal::class, 'proposed_by', 'user_id');
    }

    /**
     * Usulan barang baru yang diputuskan akun ini sebagai Pengelola Gudang,
     * disetujui maupun ditolak. Berbeda dari `itemProposals()` — mengusulkan
     * dan memutuskan adalah dua jabatan yang berbeda (ADR 0032).
     */
    public function decidedItemProposals(): HasMany
    {
        return $this->hasMany(ItemProposal::class, 'decided_by', 'user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id', 'user_id');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\AvatarStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manajemen akun pengguna. Seluruh aksi khusus Admin; pembatasan dipasang
 * pada rute (middleware `role:admin`) dan diperiksa ulang lewat gate.
 */
class UserController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q', ''));
        $role = (string) $request->query('role', '');
        $status = (string) $request->query('status', '');

        $users = User::query()
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($query) use ($keyword) {
                    foreach (['fullname', 'username', 'email'] as $column) {
                        $query->orWhere($column, 'like', '%'.$keyword.'%');
                    }

                    // Kolom unit menyimpan nilai enum, jadi kata kunci
                    // dicocokkan ke labelnya.
                    $units = array_filter(
                        UnitKerja::cases(),
                        fn (UnitKerja $unit) => mb_stripos($unit->label(), $keyword) !== false,
                    );

                    $query->orWhereIn('unit', array_map(fn (UnitKerja $unit) => $unit->value, $units));
                });
            })
            ->when(UserRole::tryFrom($role), fn ($query, $role) => $query->where('role', $role))
            ->when($status === 'aktif', fn ($query) => $query->where('is_active', true))
            ->when($status === 'nonaktif', fn ($query) => $query->where('is_active', false))
            ->orderBy('fullname')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.users.index', [
            'title' => 'Manajemen Pengguna',
            'users' => $users,
            'keyword' => $keyword,
            'role' => $role,
            'status' => $status,
            'summary' => [
                'total' => User::count(),
                'aktif' => User::where('is_active', true)->count(),
                'nonaktif' => User::where('is_active', false)->count(),
                'admin' => User::where('role', UserRole::Admin)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('pages.users.create', [
            'title' => 'Tambah Pengguna',
            'user' => new User(['is_active' => true]),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create($request->validated());

        AuditLogger::record(
            user: $request->user(),
            activity: "Menambah akun pengguna: {$user->username}",
            module: AuditModule::Pengguna,
            event: AuditEvent::Tambah,
            subject: $user,
        );

        return redirect()
            ->route('users.index')
            ->with('status', "Akun {$user->username} berhasil dibuat.");
    }

    public function edit(User $user): View
    {
        return view('pages.users.edit', [
            'title' => 'Ubah Pengguna',
            'user' => $user,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        // Kata sandi hanya diganti bila diisi.
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->fill($data)->save();

        $activity = "Mengubah akun pengguna: {$user->username}";

        if (array_key_exists('password', $data)) {
            $activity .= ' (termasuk kata sandi)';
        }

        AuditLogger::record(
            user: $request->user(),
            activity: $activity,
            module: AuditModule::Pengguna,
            event: AuditEvent::Ubah,
            subject: $user,
        );

        return redirect()
            ->route('users.index')
            ->with('status', "Akun {$user->username} berhasil diperbarui.");
    }

    /**
     * Aktifkan atau nonaktifkan akun tanpa membuka formulir ubah.
     */
    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();

        $state = $user->is_active ? 'mengaktifkan' : 'menonaktifkan';

        AuditLogger::record(
            user: $request->user(),
            activity: ucfirst($state)." akun pengguna: {$user->username}",
            module: AuditModule::Pengguna,
            event: AuditEvent::UbahStatus,
            subject: $user,
        );

        return back()->with('status', "Akun {$user->username} berhasil di{$state}.");
    }

    /**
     * Hapus foto profil akun lain — moderasi, misalnya foto yang tidak pantas.
     *
     * Admin hanya dapat menghapus, tidak mengunggah: foto profil adalah
     * pilihan pribadi pemilik akun. Lihat ADR 0038.
     */
    public function destroyAvatar(Request $request, User $user): RedirectResponse
    {
        if (! $user->punyaFoto()) {
            return back()->with('error', "Akun {$user->username} tidak memiliki foto profil.");
        }

        $path = $user->avatar_path;
        $user->forceFill(['avatar_path' => null])->save();
        AvatarStorage::hapus($path);

        AuditLogger::record(
            user: $request->user(),
            activity: "Menghapus foto profil akun pengguna: {$user->username}",
            module: AuditModule::Pengguna,
            event: AuditEvent::Hapus,
            subject: $user,
        );

        return back()->with('status', "Foto profil {$user->username} berhasil dihapus.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        }

        // Akun yang sudah menghasilkan data transaksi tidak boleh hilang dari
        // jejak audit; arahkan Admin untuk menonaktifkannya saja.
        if ($blocking = $user->blockingRelations()) {
            return back()->with(
                'error',
                "Akun {$user->username} tidak dapat dihapus karena masih memiliki ".
                implode(', ', $blocking).'. Nonaktifkan akun ini sebagai gantinya.',
            );
        }

        $username = $user->username;
        $foto = $user->avatar_path;
        $user->delete();

        // Barisnya sudah tiada, jadi tidak ada lagi yang menunjuk berkas ini.
        AvatarStorage::hapus($foto);

        // Tanpa subjek: barisnya sudah tiada, dan menautkannya hanya akan
        // mengantar pembaca log ke halaman yang tidak ada.
        AuditLogger::record(
            user: $request->user(),
            activity: "Menghapus akun pengguna: {$username}",
            module: AuditModule::Pengguna,
            event: AuditEvent::Hapus,
        );

        return redirect()
            ->route('users.index')
            ->with('status', "Akun {$username} berhasil dihapus.");
    }
}

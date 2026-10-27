<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Gudang\ItemController;
use App\Http\Controllers\Gudang\ItemProposalController;
use App\Http\Controllers\Gudang\StockMutationController;
use App\Http\Controllers\Gudang\StockOpnameController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Pengadaan\ProcurementApprovalController;
use App\Http\Controllers\Pengadaan\ProcurementController;
use App\Http\Controllers\Pengadaan\ProcurementDocumentController;
use App\Http\Controllers\Pengadaan\ProcurementDraftController;
use App\Http\Controllers\Pengadaan\ProcurementPurchaseController;
use App\Http\Controllers\Pengadaan\ProcurementReceiptController;
use App\Http\Controllers\Pengadaan\ProcurementRequestController;
use App\Http\Controllers\Pengadaan\SupplierController;
use App\Http\Controllers\ProfileAvatarController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Request\ItemProposalController as RequestItemProposalController;
use App\Http\Controllers\Request\RequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute Tamu
|--------------------------------------------------------------------------
| Sistem ini tertutup: tidak ada pendaftaran mandiri. Akun dibuat oleh Admin.
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Rute Terautentikasi
|--------------------------------------------------------------------------
*/
Route::get('/verifikasi/nota-dinas/{requestDocument}/{token}', [RequestController::class, 'verifyNotaDinas'])
    ->whereUuid('requestDocument')
    ->name('request.nota-dinas.verify');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/dasbor');
    Route::get('/dasbor', [DashboardController::class, 'index'])->name('dashboard');

    // Profil milik sendiri, tanpa gate peran: setiap akun berhak atasnya dan
    // tidak ada parameter yang menunjuk akun lain. Swalayan terbatas pada
    // email, kata sandi, dan foto; sisanya wewenang Admin (ADR 0038).
    Route::get('/profil', [ProfileController::class, 'show'])->name('profile');
    Route::patch('/profil/email', [ProfileController::class, 'updateEmail'])->name('profile.email.update');
    Route::put('/profil/kata-sandi', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::post('/profil/foto', [ProfileAvatarController::class, 'update'])->name('profile.avatar.update');
    Route::delete('/profil/foto', [ProfileAvatarController::class, 'destroy'])->name('profile.avatar.destroy');
    // Foto siapa pun boleh dilihat pengguna yang sudah masuk — foto tampil di
    // daftar pengguna dan tempat lain. Disk privat menjaga dari tamu.
    Route::get('/profil/foto/{user}', [ProfileAvatarController::class, 'show'])
        ->whereUuid('user')
        ->name('profile.avatar.show');

    Route::get('/permintaan/create', [RequestController::class, 'create'])->name('request.create');

    Route::get('/permintaan-umum/create', [RequestController::class, 'createUmum'])->name('request.umum.create');
    Route::post('/permintaan-umum', [RequestController::class, 'storeUmum'])->name('request.umum.store');

    Route::middleware('role:pengelola_gudang,admin')->group(function () {
        Route::get('/permintaan-umum', [RequestController::class, 'umumIndex'])->name('request.umum.index');
        Route::get('/permintaan-umum/{request}/edit', [RequestController::class, 'editUmum'])->name('request.umum.edit');
        Route::put('/permintaan-umum/{request}', [RequestController::class, 'updateUmum'])->name('request.umum.update');
        Route::post('/permintaan-umum/{request}/reject', [RequestController::class, 'rejectUmum'])->name('request.umum.reject');
        Route::post('/permintaan-umum/merge', [RequestController::class, 'mergeUmum'])->name('request.umum.merge');
    });

    // Usulan barang baru dari sub sistem permintaan: pintu depan kedua ke
    // tabel `item_proposals` yang sama dengan milik Pengadaan (ADR 0032).
    // Siapa pun yang login boleh mengusulkan lewat sini — otorisasinya ada
    // di StoreItemProposalRequest, bukan di rute. Tidak ada rute keputusan
    // di sini: menolak memakai rute bersama `item-proposals.reject` (gate
    // `kelola-gudang`), menyetujui memakai `items.create` — sama seperti
    // pintu depan Pengadaan, supaya hanya ada satu jalur keputusan.
    Route::get('/permintaan/usulan-barang', [RequestItemProposalController::class, 'index'])->name('request.item-proposals.index');
    Route::get('/permintaan/usulan-barang/create', [RequestItemProposalController::class, 'create'])->name('request.item-proposals.create');
    Route::post('/permintaan/usulan-barang', [RequestItemProposalController::class, 'store'])->name('request.item-proposals.store');
    Route::post('/permintaan', [RequestController::class, 'store'])->name('request.store');
    Route::get('/permintaan', [RequestController::class, 'index'])->name('request.index');
    Route::get('/permintaan/{request}/nota-dinas', [RequestController::class, 'notaDinas'])->name('request.nota-dinas');
    Route::get('/permintaan/{request}/bast', [RequestController::class, 'bast'])->name('request.bast');
    Route::get('/permintaan/{request}', [RequestController::class, 'show'])->name('request.show');

    Route::middleware('role:kepala_unit,pengelola_gudang,admin,kasubbag_umum_ti')->group(function () {
        Route::get('/permintaan/{request}/edit', [RequestController::class, 'edit'])->name('request.edit');
        Route::put('/permintaan/{request}', [RequestController::class, 'update'])->name('request.update');
        Route::post('/permintaan/{request}/respond', [RequestController::class, 'respondByRole'])->name('request.respond');
        Route::delete('/permintaan/{request}/detail/{requestDetail}/proposal', [RequestController::class, 'removeRejectedProposal'])
            ->name('request.proposal-detail.remove');
    });

    /*
    |----------------------------------------------------------------------
    | Notifikasi
    |----------------------------------------------------------------------
    | Tanpa gate peran: setiap pengguna berhak atas notifikasinya sendiri.
    | Kepemilikan dijaga di controller, per baris, bukan lewat otorisasi rute.
    */
    Route::get('notifikasi', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifikasi/baca-semua', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('notifikasi/{notification}/baca', [NotificationController::class, 'read'])->name('notifications.read');

    /*
    |----------------------------------------------------------------------
    | Usulan pengadaan — daftar dan detail (Pengelola Gudang + Pejabat
    | Pengadaan, dan Admin)
    |----------------------------------------------------------------------
    | Gate `akses-pengadaan` meloloskan kedua peran karena `can:` yang
    | ditumpuk bersifat DAN, bukan ATAU (lihat ADR 0016). Rute `create` (di
    | bawah `can:kelola-gudang`) sengaja didaftarkan sebelum rute `show`
    | berparameter di sini, supaya "usulan-pengadaan/create" tidak tertangkap
    | sebagai {procurementRequest}.
    */
    Route::middleware('can:akses-pengadaan')->group(function () {
        Route::get('usulan-pengadaan', [ProcurementRequestController::class, 'index'])
            ->name('procurement-requests.index');

        Route::get('pengadaan', [ProcurementController::class, 'index'])
            ->name('procurements.index');

        // Cetak laporan. Ruas statis "laporan" didaftarkan di grup ini yang
        // berada paling awal, sesuai kebiasaan berkas ini, walau nyatanya
        // "pengadaan/{procurement}" hanya satu ruas sehingga tidak menelannya.
        Route::get('pengadaan/laporan/pdf', [ProcurementController::class, 'exportPdf'])
            ->name('procurements.pdf');
        Route::get('pengadaan/laporan/csv', [ProcurementController::class, 'exportCsv'])
            ->name('procurements.csv');

        // BAST dicetak dari sistem, lalu ditandatangani dan dipindai di luar
        // sistem. Keduanya boleh dilakukan kedua peran pengadaan (ADR 0018).
        Route::get('pengadaan/{procurement}/bast', [ProcurementController::class, 'bast'])
            ->name('procurements.bast');

        // Nomor BAST diisi saat cetak pertama, oleh salah satu penanda
        // tangannya (ADR 0042). Sama seperti unggah berkas, gate tambahan ini
        // bagian dari `akses-pengadaan`.
        Route::patch('pengadaan/{procurement}/bast', [ProcurementReceiptController::class, 'nomorBast'])
            ->middleware('can:isi-nomor-bast')
            ->name('procurements.bast-number');

        // Berkas pendukung. Tidak ada tautan langsung ke berkasnya: disknya
        // privat, dan seluruh unduhan wajib melewati rute terjaga ini.
        // Mengunggah dipersempit ke pelaksana pengadaan: Kasubbag membaca
        // berkasnya, tetapi tidak menambahkan bukti ke realisasi yang ia nilai
        // (ADR 0041). Gate tambahan ini bagian dari `akses-pengadaan`, jadi
        // sifat DAN `can:` bertumpuk memang yang dikehendaki.
        Route::post('pengadaan/{procurement}/dokumen', [ProcurementDocumentController::class, 'store'])
            ->middleware('can:unggah-berkas-pengadaan')
            ->name('procurement-documents.store');
        Route::get('pengadaan/{procurement}/dokumen/{document}/unduh', [ProcurementDocumentController::class, 'download'])
            ->name('procurement-documents.download');
        Route::get('pengadaan/{procurement}/dokumen/{document}/lihat', [ProcurementDocumentController::class, 'show'])
            ->name('procurement-documents.show');
        Route::delete('pengadaan/{procurement}/dokumen/{document}', [ProcurementDocumentController::class, 'destroy'])
            ->name('procurement-documents.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | Manajemen gudang (Pengelola Gudang, dan Admin)
    |----------------------------------------------------------------------
    | Memakai gate `kelola-gudang`, bukan middleware `role:`, karena gate sudah
    | meloloskan Admin lewat Gate::before sedangkan `role:` memeriksa peran
    | secara harfiah dan akan menolak Admin.
    |
    | Grup ini hanya berisi rute yang mengubah data. Rute baca gudang ada di
    | grup `akses-gudang` di bawah, yang juga meloloskan Kasubbag Umum dan TI
    | (ADR 0041) — sengaja didaftarkan sesudah grup ini supaya "barang/create"
    | dan "opname/create" tidak tertelan rute berparameter.
    */
    Route::middleware('can:kelola-gudang')->group(function () {
        Route::resource('barang', ItemController::class)
            ->except(['index', 'show'])
            ->parameters(['barang' => 'item'])
            ->names('items');

        Route::patch('barang/{item}/status', [ItemController::class, 'toggleStatus'])
            ->name('items.toggle-status');

        // Riwayat mutasi bersifat immutable: tidak ada rute ubah maupun hapus.
        Route::get('mutasi/create', [StockMutationController::class, 'create'])->name('mutations.create');
        Route::post('mutasi', [StockMutationController::class, 'store'])->name('mutations.store');

        // Stok opname. Opname yang sudah diposting bersifat final: rute ubah dan
        // hapus menolaknya di controller, bukan disembunyikan dari daftar rute.
        Route::post('opname/{opname}/posting', [StockOpnameController::class, 'post'])->name('opname.post');
        Route::resource('opname', StockOpnameController::class)
            ->except(['index', 'show'])
            ->names('opname');

        // Usulan pengadaan: mengusulkan adalah tugas Pengelola Gudang (ADR 0016).
        Route::get('usulan-pengadaan/create', [ProcurementRequestController::class, 'create'])
            ->name('procurement-requests.create');
        Route::post('usulan-pengadaan', [ProcurementRequestController::class, 'store'])
            ->name('procurement-requests.store');

        // Draf usulan otomatis dari pemantauan stok (ADR 0027). Statis, jadi
        // wajib terdaftar sebelum "usulan-pengadaan/{procurementRequest}" di
        // bawah, sama seperti "create" di atas.
        Route::get('usulan-pengadaan/draf', [ProcurementDraftController::class, 'edit'])
            ->name('procurement-requests.draft.edit');
        Route::put('usulan-pengadaan/draf', [ProcurementDraftController::class, 'update'])
            ->name('procurement-requests.draft.update');
        Route::post('usulan-pengadaan/draf/ajukan', [ProcurementDraftController::class, 'finalize'])
            ->name('procurement-requests.draft.finalize');
        Route::delete('usulan-pengadaan/draf', [ProcurementDraftController::class, 'discard'])
            ->name('procurement-requests.draft.discard');

        // Menolak usulan barang baru: master barang milik Pengelola Gudang,
        // jadi dialah yang memutuskan (ADR 0032). Tidak ada rute "setujui" —
        // menyetujui berarti mendaftarkan barangnya lewat `items.store`, dan
        // persetujuan tanpa baris `items` tidak berarti apa pun.
        Route::patch('usulan-barang/{itemProposal}/tolak', [ItemProposalController::class, 'reject'])
            ->name('item-proposals.reject');

        // Konfirmasi penerimaan barang: tugas Pengelola Gudang, terpisah dari
        // yang merealisasi (ADR 0008). Peristiwa sekali jadi, tanpa rute ubah.
        Route::get('pengadaan/{procurement}/penerimaan/create', [ProcurementReceiptController::class, 'create'])
            ->name('procurement-receipts.create');
        Route::post('pengadaan/{procurement}/penerimaan', [ProcurementReceiptController::class, 'store'])
            ->name('procurement-receipts.store');
    });

    /*
    |----------------------------------------------------------------------
    | Pengadaan (Pejabat Pengadaan, dan Admin)
    |----------------------------------------------------------------------
    | Sama seperti gudang: memakai gate, bukan middleware `role:`, supaya
    | Admin ikut lolos lewat Gate::before.
    */
    Route::middleware('can:kelola-pengadaan')->group(function () {
        // Daftar dan rincian penyedia ada di grup `akses-penyedia` di bawah
        // (ADR 0041), sesudah "penyedia/create" di sini.
        Route::resource('penyedia', SupplierController::class)
            ->except(['index', 'show'])
            ->parameters(['penyedia' => 'supplier'])
            ->names('suppliers');

        Route::patch('penyedia/{supplier}/status', [SupplierController::class, 'toggleStatus'])
            ->name('suppliers.toggle-status');

        // Menyetujui/menolak usulan adalah tugas Pejabat Pengadaan (ADR 0016).
        Route::patch('usulan-pengadaan/{procurementRequest}/setujui', [ProcurementRequestController::class, 'approve'])
            ->name('procurement-requests.approve');
        Route::patch('usulan-pengadaan/{procurementRequest}/tolak', [ProcurementRequestController::class, 'reject'])
            ->name('procurement-requests.reject');

        // Mengusulkan barang baru: yang tahu ada barang belum terdaftar adalah
        // yang mau mengadakannya (ADR 0032). Segmen statis "create" wajib
        // terdaftar sebelum "usulan-barang/{itemProposal}" di grup penutup.
        Route::get('usulan-barang/create', [ItemProposalController::class, 'create'])
            ->name('item-proposals.create');
        Route::post('usulan-barang', [ItemProposalController::class, 'store'])
            ->name('item-proposals.store');

        // Realisasi: merealisasi adalah tugas Pejabat Pengadaan (ADR 0016).
        // "create" wajib terdaftar sebelum "pengadaan/{procurement}" di bawah
        // (gate berbeda, tetap satu berkas rute) — kalau tidak, "create"
        // tertangkap sebagai parameter dan gagal route model binding.
        Route::get('pengadaan/create', [ProcurementController::class, 'create'])
            ->name('procurements.create');
        Route::post('pengadaan', [ProcurementController::class, 'store'])
            ->name('procurements.store');

        // Koreksi realisasi selama belum dikonfirmasi diterima. Salah ketik
        // diperbaiki di sini, bukan dengan membatalkan lalu mengulang dari
        // usulan baru (ADR 0025).
        Route::get('pengadaan/{procurement}/edit', [ProcurementController::class, 'edit'])
            ->name('procurements.edit');
        Route::put('pengadaan/{procurement}', [ProcurementController::class, 'update'])
            ->name('procurements.update');

        // Membatalkan realisasi yang tidak akan pernah sampai ke penerimaan —
        // dokumen milik Pejabat Pengadaan, jadi dialah yang membatalkannya
        // (ADR 0024). Tidak ada rute hapus: realisasi yang batal tetap
        // tersimpan sebagai bukti bahwa pengadaan itu pernah berjalan.
        Route::patch('pengadaan/{procurement}/batal', [ProcurementController::class, 'cancel'])
            ->name('procurements.cancel');

        // Menandai barang sudah dibelanjakan beserta fotonya — yang
        // membelanjakan adalah Pejabat Pengadaan, jadi dialah yang mencatatnya
        // (ADR 0035). Baru setelah ini gudang menunggu barangnya.
        Route::patch('pengadaan/{procurement}/dibelanjakan', [ProcurementPurchaseController::class, 'store'])
            ->name('procurements.purchase');
    });

    /*
    |----------------------------------------------------------------------
    | Persetujuan realisasi — Kasubbag Umum dan TI
    |----------------------------------------------------------------------
    |
    | Jenjang keempat alur pengadaan (ADR 0030). Keduanya PATCH, sehingga bebas
    | dari jebakan urutan rute yang mengenai GET segmen statis versus GET
    | wildcard di grup-grup sebelumnya.
    |
    | Tidak ada rute "ajukan ulang": realisasi yang ditolak diperbaiki lewat
    | `procurements.update` milik Pejabat Pengadaan, dan penyimpanan perubahan
    | itulah pengajuan ulangnya.
    */
    Route::middleware('can:setujui-pengadaan')->group(function () {
        Route::patch('pengadaan/{procurement}/setujui', [ProcurementApprovalController::class, 'approve'])
            ->name('procurements.approve');
        Route::patch('pengadaan/{procurement}/tolak', [ProcurementApprovalController::class, 'reject'])
            ->name('procurements.reject');
    });

    Route::middleware('can:akses-pengadaan')->group(function () {
        // Usulan barang baru dibaca kedua peran: pengusulnya Pejabat Pengadaan,
        // pemutusnya Pengelola Gudang (ADR 0032).
        Route::get('usulan-barang', [ItemProposalController::class, 'index'])
            ->name('item-proposals.index');
        Route::get('usulan-barang/{itemProposal}', [ItemProposalController::class, 'show'])
            ->name('item-proposals.show');

        Route::get('usulan-pengadaan/{procurementRequest}', [ProcurementRequestController::class, 'show'])
            ->name('procurement-requests.show');

        Route::get('pengadaan/{procurement}', [ProcurementController::class, 'show'])
            ->name('procurements.show');
    });

    /*
    |----------------------------------------------------------------------
    | Baca gudang dan penyedia — pelaksana, dan Kasubbag Umum dan TI
    |----------------------------------------------------------------------
    | Kasubbag mengawasi seluruh data subbagiannya, tetapi hanya membaca dan
    | mencetak; setiap rute yang mengubah data tetap di grup `kelola-*` di atas
    | (ADR 0041). Grup ini wajib berada sesudah grup-grup tersebut: rute
    | berparameter di sini akan menelan "barang/create", "opname/create", dan
    | "penyedia/create" bila didaftarkan lebih dulu.
    */
    Route::middleware('can:akses-gudang')->group(function () {
        Route::resource('barang', ItemController::class)
            ->only(['index', 'show'])
            ->parameters(['barang' => 'item'])
            ->names('items');

        Route::get('barang/{item}/kartu-persediaan', [StockMutationController::class, 'kartuPersediaan'])
            ->name('items.kartu-persediaan');

        Route::get('mutasi/cetak/pdf', [StockMutationController::class, 'exportPdf'])->name('mutations.pdf');
        Route::get('mutasi/cetak/csv', [StockMutationController::class, 'exportCsv'])->name('mutations.csv');
        Route::get('mutasi', [StockMutationController::class, 'index'])->name('mutations.index');

        Route::get('opname/{opname}/cetak', [StockOpnameController::class, 'exportPdf'])->name('opname.pdf');
        Route::resource('opname', StockOpnameController::class)
            ->only(['index', 'show'])
            ->names('opname');
    });

    Route::middleware('can:akses-penyedia')->group(function () {
        Route::resource('penyedia', SupplierController::class)
            ->only(['index', 'show'])
            ->parameters(['penyedia' => 'supplier'])
            ->names('suppliers');
    });

    /*
    |----------------------------------------------------------------------
    | Administrasi sistem (khusus Admin)
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::resource('pengguna', UserController::class)
            ->parameters(['pengguna' => 'user'])
            ->names('users')
            ->except(['show']);

        Route::patch('pengguna/{user}/status', [UserController::class, 'toggleStatus'])
            ->name('users.toggle-status');

        Route::delete('pengguna/{user}/foto', [UserController::class, 'destroyAvatar'])
            ->name('users.avatar.destroy');

        // Segmen statis lebih dulu, mengikuti kebiasaan modul lain: rute GET
        // berpola tetap yang didaftarkan setelah rute berparameter pada awalan
        // yang sama akan ditelan wildcard-nya.
        Route::get('log-aktivitas/ekspor/csv', [ActivityLogController::class, 'exportCsv'])
            ->name('activity-logs.csv');

        Route::get('log-aktivitas', [ActivityLogController::class, 'index'])
            ->name('activity-logs.index');
    });

    /*
    |----------------------------------------------------------------------
    | Halaman referensi komponen TailAdmin
    |----------------------------------------------------------------------
    | Sementara dipertahankan sebagai contoh UI selama modul dikembangkan.
    */
    Route::get('/calendar', fn () => view('pages.calender', ['title' => 'Kalender']))->name('calendar');
    Route::get('/form-elements', fn () => view('pages.form.form-elements', ['title' => 'Form Elements']))->name('form-elements');
    Route::get('/basic-tables', fn () => view('pages.tables.basic-tables', ['title' => 'Basic Tables']))->name('basic-tables');
    Route::get('/blank', fn () => view('pages.blank', ['title' => 'Blank']))->name('blank');
    Route::get('/line-chart', fn () => view('pages.chart.line-chart', ['title' => 'Line Chart']))->name('line-chart');
    Route::get('/bar-chart', fn () => view('pages.chart.bar-chart', ['title' => 'Bar Chart']))->name('bar-chart');
    Route::get('/alerts', fn () => view('pages.ui-elements.alerts', ['title' => 'Alerts']))->name('alerts');
    Route::get('/avatars', fn () => view('pages.ui-elements.avatars', ['title' => 'Avatars']))->name('avatars');
    Route::get('/badge', fn () => view('pages.ui-elements.badges', ['title' => 'Badges']))->name('badges');
    Route::get('/buttons', fn () => view('pages.ui-elements.buttons', ['title' => 'Buttons']))->name('buttons');
    Route::get('/image', fn () => view('pages.ui-elements.images', ['title' => 'Images']))->name('images');
    Route::get('/videos', fn () => view('pages.ui-elements.videos', ['title' => 'Videos']))->name('videos');
});

Route::get('/error-404', fn () => view('pages.errors.error-404', ['title' => 'Halaman Tidak Ditemukan']))->name('error-404');

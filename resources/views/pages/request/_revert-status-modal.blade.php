{{--
    Modal Kembalikan Status khusus Admin, dipakai halaman daftar dan detail
    permintaan. Dibuka lewat event `open-revert-modal` berisi
    { id, number, from, to }. Konteks ikut dikirim sebagai `revert_context`
    supaya modal terbuka lagi dengan isi yang sama saat validasi gagal.
--}}
@php
    $revertLabels = [
        'submitted' => 'Terkirim',
        'approved_by_head' => 'Disetujui Kepala Unit',
        'processed_by_staff' => 'Disetujui Staff Umum',
        'in_issuance' => 'Dalam Proses Pengeluaran',
        'rejected' => 'Ditolak',
    ];
    $revertNotes = [
        'approved_by_head' => 'Persetujuan Nota Dinas dicabut dan dokumennya dibuat ulang tanpa tanda tangan. Tautan verifikasi QR yang lama tidak berlaku lagi.',
        'processed_by_staff' => 'Jumlah disetujui dikosongkan dan barang tambahan dari Pengelola Gudang dihapus, sehingga kuantitas bisa diubah ulang.',
    ];
    $oldRevert = $errors->has('reason') ? json_decode((string) old('revert_context'), true) : null;
@endphp

<x-ui.modal @open-revert-modal.window="open = true" :isOpen="is_array($oldRevert)" class="max-w-[520px]">
    <form method="POST" :action="actionUrl.replace('__REQUEST__', revert.id)" class="p-6 sm:p-8"
        x-data="{
            revert: @js(is_array($oldRevert) ? $oldRevert : ['id' => '', 'number' => '', 'from' => '', 'to' => '']),
            actionUrl: @js(route('request.revert-status', '__REQUEST__')),
            labels: @js($revertLabels),
            notes: @js($revertNotes),
        }"
        @open-revert-modal.window="revert = $event.detail">
        @csrf
        <input type="hidden" name="revert_context" :value="JSON.stringify(revert)">
        <p class="text-sm font-medium text-brand-600">Kembalikan status <span x-text="revert.number"></span></p>
        <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">
            <span x-text="labels[revert.from]"></span> &rarr; <span x-text="labels[revert.to]"></span>
        </h3>
        <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400"
            x-text="notes[revert.from] ?? 'Permintaan kembali ke tahap sebelumnya dan bisa diproses ulang.'"></p>
        <label for="reason" class="mt-5 mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Alasan</label>
        <textarea id="reason" name="reason" rows="3" required maxlength="500" placeholder="Contoh: salah klik tolak oleh staf"
            class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('reason') }}</textarea>
        @error('reason')
            <p class="mt-1.5 text-theme-xs text-error-500">{{ $message }}</p>
        @enderror
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">Batal</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Ya, Kembalikan</button>
        </div>
    </form>
</x-ui.modal>

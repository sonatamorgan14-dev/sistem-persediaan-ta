{{--
    Modal nomor urut Nota Dinas, dipakai halaman daftar dan detail permintaan.
    Permintaan yang sudah disetujui Kepala Unit hanya bisa diproses lebih lanjut
    (Setujui untuk permintaan normal, Selesaikan untuk permintaan darurat)
    setelah Nota Dinas-nya diberi nomor. Dibuka lewat event
    `open-nd-number-modal` berisi { id, number, preview, sequence, label }.
--}}
<x-ui.modal @open-nd-number-modal.window="open = true" :isOpen="false" class="max-w-[520px]">
    <form method="POST" :action="actionUrl.replace('__REQUEST__', target.id)" class="p-6 sm:p-8"
        x-data="{
            target: { id: '', number: '', preview: '', label: 'Setujui' },
            actionUrl: @js(route('request.respond', ['request' => '__REQUEST__', 'action' => 'approve'])),
            sequence: '',
        }"
        @open-nd-number-modal.window="target = $event.detail; sequence = $event.detail.sequence ?? ''">
        @csrf
        <p class="text-sm font-medium text-brand-600">Proses permintaan <span x-text="target.number"></span></p>
        <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">Nomor Nota Dinas</h3>
        <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">
            Isi nomor urut Nota Dinas sebelum permintaan diproses. Nomor BAST memakai nomor urut yang sama.
        </p>
        <label for="nd_sequence_modal" class="mt-5 mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
            Nomor Urut Nota Dinas<span class="text-error-500">*</span>
        </label>
        <input id="nd_sequence_modal" type="text" name="nd_sequence" x-model="sequence" required inputmode="numeric"
            pattern="\d{1,6}" maxlength="6" placeholder="mis. 51" autocomplete="off"
            class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">
        <p class="text-theme-xs mt-1.5 break-all text-gray-500 dark:text-gray-400"
            x-text="'Nomor lengkap: ' + target.preview.replace('……', sequence.trim() || '……')"></p>
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">Batal</button>
            <button type="submit" class="bg-success-500 hover:bg-success-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white" x-text="target.label"></button>
        </div>
    </form>
</x-ui.modal>

{{--
    State Alpine untuk partial journal-lines. Pakai di dalam x-data: "{ ...@include(...) }" tidak bisa,
    jadi partial ini mencetak objek JavaScript lengkap. Variabel: $lines (list baris awal), $errors.
--}}
{
    lines: {{ \Illuminate\Support\Js::from($lines) }},
    errors: {{ \Illuminate\Support\Js::from(collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'lines.'))) }},
    kirim: false,
    sen(v) { const n = parseFloat(v); return Number.isFinite(n) ? Math.round(n * 100) : 0 },
    get debit() { return this.lines.reduce((s, l) => s + this.sen(l.debit), 0) },
    get kredit() { return this.lines.reduce((s, l) => s + this.sen(l.credit), 0) },
    get selisih() { return this.debit - this.kredit },
    get seimbang() { return this.debit > 0 && this.selisih === 0 },
    tambah() { this.lines.push({ account_id: '', description: '', cost_center_id: '', debit: '', credit: '' }) },
    hapus(i) { if (this.lines.length > 2) this.lines.splice(i, 1) },
    isiSisa(i) { const l = this.lines[i]; const s = this.selisih + this.sen(l.credit) - this.sen(l.debit); if (s > 0) { l.credit = (s / 100).toFixed(2); l.debit = '' } else if (s < 0) { l.debit = (-s / 100).toFixed(2); l.credit = '' } },
    galat(i, kolom) { return this.errors['lines.' + i + '.' + kolom]?.[0] ?? '' },
    rp(sen) { return 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(sen / 100) },
}

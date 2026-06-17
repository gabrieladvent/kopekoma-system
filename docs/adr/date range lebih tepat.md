date range lebih tepat. Hari Raya itu dikumpulkan sepanjang satu rentang (mis. mulai pengumpulan s/d tanggal terakhir sebelum dibagi), jadi gating "boleh setor kalau tanggal setor ada di dalam rentang" jauh lebih akurat daripada sekadar tahun. Tapi karena ini menyentuh skema dan model saldo, saya konfirmasi desainnya dulu sebelum ubah kode.

Usulan desain (date range)
Registrasi Hari Raya (member_holiday_savings) — tambah kolom:

start_date — mulai pengumpulan
end_date — tanggal terakhir pengumpulan (sebelum dibagi)
monthly_amount, is_active, notes (tetap)
period_year tetap ada tapi auto-derive dari end_date (= tahun pembagian). User tidak input ini — dipakai internal sebagai kunci pengelompokan saldo + keunikan.
Kenapa period_year tetap dipertahankan (auto): model saldo Hari Raya (D1, sudah jadi & ter-test) menghitung saldo per period_year. Kalau saya buang period_year, balance service (1a) + kolom period_year di withdrawal (item 0) ikut berubah — itu menyentuh inti keuangan yang paling sensitif. Dengan auto-derive dari end_date, balance core tidak berubah, kamu cukup isi rentang tanggal. Rentang yang melintasi tahun pun aman (program diidentifikasi oleh tahun pembagian = end_date.year).

Validasi: end_date ≥ start_date; unik (member, period_year) → satu program per anggota per tahun pembagian.

Form setoran Hari Raya jadi lebih simpel:

Pilih anggota → jenis "Hari Raya" muncul kalau ada registrasi aktif yang rentangnya memuat deposit_date.
Nominal auto-lock dari monthly_amount registrasi itu.
period_month untuk hari_raya tidak diisi manual lagi — otomatis di-tag ke tahun program (biar saldo D1 konsisten). Tanggal setor di luar semua rentang aktif → ditolak.
Yang TIDAK berubah: SavingsBalanceService (1a), withdrawal period_year (item 0), aturan jenis lain (D11).

Oke saya implement dengan desain ini? Atau kamu mau period_year tetap diinput manual (bukan auto dari end_date)?

Update Todos

- Migration: add start_date + end_date to member_holiday_savings
- Model + factory: range fields, period_year auto-derive
- MemberHolidaySavingResource: start/end DatePickers, auto period_year, range in table/infolist
- SavingsDepositResource: gate hari_raya by deposit_date in active range, auto-tag period_month
- Update tests (Hari Raya CRUD + deposit hari_raya range)
- Run suite + pint; update ADR D11

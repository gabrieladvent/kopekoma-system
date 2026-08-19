# Bayar Angsuran dari Saldo Simpanan

Menambah **sumber pembayaran** angsuran: selain tunai/potong-gaji, anggota bisa membayar angsuran dengan mendebit saldo simpanannya (jenis dipilih), sebagai transfer internal atomik.

**Author:** gabrieladvent
**Date:** 2026-07-22
**Status:** Implemented (2026-07-23 — Key Items 1a-1f + 2a Done; 3a Won't-do). Deploy-review produksi menyusul.

> **Changelog rev.3:** menutup temuan ronde-2 (fix inti `installment_id` sudah dinyatakan bersih).
> - **🔴 Reverse anggota non-aktif:** reverse debit berpasangan **tidak boleh** kena guard `memberInactive` (membalik debit = mengembalikan saldo anggota, selalu boleh). Jalur khusus, tidak lewat `ReverseTransaction` apa adanya.
> - **🔴 Privilege inversion:** reverse angsuran ber-`payment_method='saldo_simpanan'` di-gate **`reverse_loan` (Pengurus)** — setara `is_settlement`. (RESOLVE Open Q #6)
> - **🔴 Consent WAJIB** (validasi server-side) untuk sumber simpanan; tercatat sebagai media di angsuran.
> - **🔴 enum `internal`:** tambah label ke `DISBURSEMENT_METHODS` + fallback; baris debit disembunyikan/dilabeli di listing penarikan.
> - `InstallmentResource::PAYMENT_METHODS` + `saldo_simpanan`; defense-in-depth reverse (guard di layer mutasi, bukan hanya policy); search paired-withdrawal exclude yang sudah di-reverse; prasyarat keras Batch.

> **Changelog rev.2:** memperbaiki temuan review yang menohok desain awal.
> - **Governance:** argumen "transfer internal → tak perlu maker-checker" **dicabut** (category error — sukarela = uang withdrawable). Keputusan: permission baru **`pay_installment_from_savings` Pengurus-only**, debit langsung tapi dengan **atribusi** (`approved_by`/`approved_at` = pengurus) + bukti consent anggota.
> - **`sukarela` SAJA** (hard). `swp`/`tabungan_berjangka` → kolisi refund pelunasan; `hari_raya` → double-spend `period_year`.
> - **Jangan pakai `related_loan_id`** sebagai penanda pasangan (bentrok `hasActiveRefund`/`refundPair`). Pakai kolom link baru **`installment_id`** di `savings_withdrawals`.
> - **Debit berpasangan non-reversible** via UI Penarikan — reversal hanya via `Installment::reverse` atomik.
> - Migrasi **enum `disbursement_method` + `internal`**; overpayment **dikunci tepat-tagihan**; lock member (urutan konsisten); audit event eksplisit.

---

## Background

Anggota kadang membayar angsuran lebih dari tagihan ("nitip", mis. angsuran 1jt tapi setor 3jt karena sedang banyak uang). Sistem sekarang mengalihkan kelebihan itu ke **Simpanan Sukarela** (`LoanPaymentService::creditOverpaymentToSukarela`). Tapi **belum ada jalan sebaliknya**: di bulan berikutnya, saldo simpanan itu tak bisa dipakai membayar angsuran — anggota tetap harus menyetor tunai/potong gaji.

`payment_method` pada angsuran hanya `['potong_gaji','manual']` — tidak ada konsep "sumber dana dari simpanan". Yang diminta: saat mencatat angsuran, petugas bisa memilih **sumber**: tunai/potong-gaji **atau** debit dari saldo simpanan anggota yang mencukupi.

Ini melengkapi siklus nitip → pakai, dan mengurangi friksi (anggota tak perlu setor ulang uang yang sudah "diparkir").

---

## Goals

- Tambah sumber pembayaran angsuran: **debit dari saldo Simpanan Sukarela**, selain tunai/potong-gaji.
- Debit **atomik & langsung** (satu transaksi dengan pencatatan angsuran) — **tapi dengan otoritas & atribusi setara pencairan** (Pengurus-only + `approved_by`/`approved_at` terisi), karena sukarela = uang anggota yang bisa ditarik tunai (bukan "kas internal bebas").
- **Hanya `sukarela`** yang bisa didebit di rollout ini. **Kecualikan keras** `swp`, `tabungan_berjangka` (saldo diturunkan dari modul pinjaman → kolisi refund pelunasan), `hari_raya` (per-`period_year` → double-spend), `pokok`, `wajib`, `wajib_belanja` (terkunci).
- Solvabilitas dicek (`canWithdraw`) — tak boleh debit melebihi saldo; lock member (anti over-debit paralel).
- **Reversal-aware & atomik**: membatalkan angsuran ikut membalik debit; debit **tidak boleh** dibalik sendiri lewat jalur Penarikan.
- Konsisten dengan invariant "uang di sistem = uang nyata": saldo turun tepat saat & sebesar yang didebit, terekam (audit event + atribusi) & bisa direkonstruksi.

## Non-Goals

- **Tidak** membuat bucket/jenis simpanan baru ("titipan angsuran"). Keputusan: pakai saldo jenis yang sudah ada (fokus ke *sumber pembayaran*).
- Tidak mengubah perilaku kelebihan bayar (tetap → sukarela).
- Tidak menyentuh alur pelunasan dipercepat ([[2026-07-22-pelunasan-dipercepat]]).
- Tidak ada pembayaran angsuran **sebagian** dari simpanan + sebagian tunai (satu angsuran = satu sumber). Bisa jadi Non-Goal awal, dipertimbangkan nanti.
- Tidak menambah konsep saldo minimum numerik (belum ada di sistem; perlindungan lewat exclusion jenis terkunci).

---

## Design

### Bentuk: debit `sukarela` berpasangan di dalam `pay()` (Pengurus-only)

Saat sumber = **Simpanan Sukarela**:

1. **Otorisasi:** `authorize('payFromSavings', ...)` → permission **`pay_installment_from_savings`** (Pengurus-only). Enforce di entry point (Livewire) **dan** dalam service.
2. Guard jenis: **hanya `sukarela`** (hard-coded; bukan mirror `WITHDRAWABLE_TYPES`).
3. **Nominal = tepat `total_due`** (tidak boleh lebih). Mencegah lingkaran "debit sukarela → kelebihan balik ke sukarela".
4. Cek solvabilitas: `SavingsBalanceService::canWithdraw($member, 'sukarela', $amount)` — else `CannotProcessPayment::insufficientSavings()`.
5. Dalam **satu** `DB::transaction`, **lock member DULU** lalu loan/schedule (urutan konsisten global untuk cegah deadlock vs `WithdrawalWorkflow::disburse` yang me-lock member):
   - Buat `Installment`, `payment_method = 'saldo_simpanan'`.
   - Buat **`SavingsWithdrawal` berpasangan** berstatus **`Cair`** dengan **atribusi**: `savings_type = 'sukarela'`, `amount = amount_paid`, **`installment_id = installment.id`** (kolom BARU — penanda pasangan, BUKAN `related_loan_id`), `approved_by = pelaku`, `approved_at = now`, `disbursed_at = now`, `disbursement_method = 'internal'` (enum BARU). Saldo turun langsung (`withdrawalNet` hanya hitung `Cair`).
   - Audit: `activity()->event('debit_simpanan_angsuran')->withProperties([member_id, savings_type, amount, installment_number, loan_id, approved_by])`.

### Kenapa Pengurus-only + atribusi, bukan debit-bebas (koreksi rev.1)

Rev.1 salah menganggap ini "transfer internal → tak perlu mata-kedua". **Sukarela adalah uang anggota yang bisa ditarik tunai** — dari sisi anggota, saldonya berkurang itu identik entah dipakai bayar pinjaman atau ditarik. Invariant yang dijaga maker-checker adalah **"saldo simpanan anggota berkurang"**, bukan sekadar "kas fisik keluar". Preseden `ShoppingTransaction` **tidak transferable**: `wajib_belanja` terkunci (bukan `WITHDRAWABLE_TYPES`), outflow-nya cuma belanja. Maka: otoritas dinaikkan ke **Pengurus** + `approved_by`/`approved_at` terisi (atribusi keputusan) + **bukti consent anggota WAJIB** — tanpa memaksa alur 2-langkah yang membunuh kepraktisan.

**Consent wajib (bukan opsional):** karena self-approval Pengurus meng-collapse maker-checker ke satu aktor, bukti consent adalah **satu-satunya pengganti mata-kedua**. Untuk `payment_method = 'saldo_simpanan'`, `bukti` **wajib** (validasi server-side di `pay()` + `InstallmentForm`, bukan sekadar UI), dan disimpan sebagai media pada angsuran agar bisa direkonstruksi saat sengketa ("saya tak pernah setuju saldo saya dipakai").

### Penanda pasangan: `installment_id`, BUKAN `related_loan_id`

`related_loan_id` + tipe `swp`/`tabungan_berjangka` sudah dipakai sistem untuk mengenali **refund pelunasan** (`hasActiveRefund`, `refundPair`, `isLoanRefund`). Memakainya untuk debit akan (a) menekan refund SWP/Tab saat lunas, (b) salah-kelompokkan di UI Penarikan. Solusi: kolom BARU **`installment_id`** di `savings_withdrawals` sebagai tautan pasangan yang bersih — tidak masuk query refund.

### Reversal — atomik, tidak boleh terpisah, privilege konsisten

`Installment` sudah `Reversible`. Saat angsuran di-reverse, debit berpasangannya ikut dibalik: cari `SavingsWithdrawal` non-reversal `Cair` `installment_id = installment.id` **yang belum dibalik** (pola `whereNotIn(reversed_ids)` seperti `reverseOverpaymentCredit`), lalu reverse.

**⚠️ Otoritas reverse = `reverse_loan` (Pengurus), bukan `reverse_installment` (Petugas).** Reverse angsuran-dari-simpanan **menaikkan saldo sukarela (withdrawable)** anggota — asimetri berbahaya bila jalur turun butuh Pengurus tapi jalur naik cukup Petugas (Petugas bisa "manufaktur" saldo withdrawable). `InstallmentPolicy::reverse`: `if ($installment->is_settlement || $installment->payment_method === 'saldo_simpanan') return $user->can('reverse_loan');`.

**⚠️ Anggota non-aktif (Keluar/Meninggal):** `ReverseTransaction` menolak reverse bila member inactive — untuk debit ini **salah arah**: membalik debit **mengembalikan** saldo ke anggota, harus **selalu** boleh (kalau tidak, angsuran-dari-simpanan jadi permanen tak bisa dibatalkan begitu anggota keluar, melanggar Goal). Jalur reverse debit berpasangan **tidak** boleh melewati guard `memberInactive` — pakai jalur reverse khusus (mis. flag/parameter bypass), bukan `ReverseTransaction` apa adanya.

**⚠️ Debit berpasangan TIDAK boleh di-reverse sendiri** lewat UI/menu Penarikan — kalau bisa, saldo pulih tapi angsuran tetap Terbayar = **angsuran gratis (uang tercipta)**. Pertahanan berlapis:
- Policy: `SavingsWithdrawalPolicy::reverse` menolak baris ber-`installment_id` (menutup 3 UI path: Resource, `SavingsWithdrawals`, `SavingsWithdrawalDetail` — semua lewat `can('reverse')`).
- **Layer mutasi (defense-in-depth):** `performReversal` di Resource tidak re-authorize (hanya andalkan `->visible`), dan `ReverseTransaction` auth-agnostic → tambahkan guard keras: menolak reverse `SavingsWithdrawal` ber-`installment_id` kecuali dipanggil dari konteks `Installment::reverse`. Cegah caller baru (command/API/bulk) bocor.

### Approach

Reuse: `SavingsBalanceService::canWithdraw`, model `SavingsWithdrawal`, `Reversible`, `LoanPaymentService::pay()` yang sudah atomik. Yang BARU: enum `payment_method` (`saldo_simpanan`) + enum `disbursement_method` (`internal`) + kolom `savings_withdrawals.installment_id` + jalur debit + reversal berpasangan + permission/policy.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|-------------|-----|-----|---------|
| **Debit `sukarela` berpasangan (Cair + atribusi Pengurus) via `installment_id`** | Reuse model+saldo+reversal; 1 langkah tapi ada otoritas & atribusi; sukarela-only aman | Buat `Cair` di luar WithdrawalWorkflow (dimitigasi: approved_by terisi, non-reversible terpisah) | **Chosen** |
| Debit-bebas tanpa approval (rev.1) | Paling praktis | Category error — sukarela withdrawable, satu Petugas gerus saldo anggota tanpa mata-kedua; preseden ShoppingTransaction tak valid | **Rejected (dicabut di rev.2)** |
| WithdrawalWorkflow penuh (draft→acc→cair) lalu bayar | Paling aman (dua mata) | UX 2-langkah membunuh kepraktisan; `pay()` belum bisa mengonsumsi withdrawal | Rejected (dipertimbangkan; dikalahkan opsi Pengurus-only+atribusi) |
| Bucket "Titipan Angsuran" khusus (jenis baru) | Niat dana jelas & terkunci | Nambah savings_type; user memilih "pilih sumber", bukan bucket | Rejected (keputusan bisnis) |
| Tabel debit baru ala ShoppingTransaction | Isolasi dari semantik withdrawal | Nambah tabel + integrasi saldo; `installment_id` di withdrawal sudah cukup | Rejected |

---

## Rollout Plan

| Phase | Behavior | Status |
|-------|----------|--------|
| 0 | Baseline — hanya tunai/potong-gaji | — |
| 1 | Backend jalur debit + test (fitur di-flag off / tak ada UI) | Done (1a-1f) |
| 2 | UI form tunggal: pilih sumber "Saldo Simpanan" | Done (2a) |
| 3 | ~~(opsional) dukung di Batch Potong Gaji~~ | **Won't-do** (2026-07-23) |

### Phase Transition Checklist

**Phase 0 → 1:**
- [x] Enum `payment_method` menambah `saldo_simpanan` (migrasi) — item 1a done
  <!-- source: code | query: migration add saldo_simpanan to installments payment_method -->
- [x] `LoanPaymentService` jalur debit + reversal berpasangan + test hijau — 1c (debit) + 1d (reversal berpasangan, bypass `memberInactive`) done, full suite hijau
  <!-- source: code -->

**Phase 1 → 2:**
- [x] UI form pilih sumber (2a) + test hijau — opsi Pengurus-only, nominal terkunci, consent wajib, saldo divalidasi
  <!-- source: code -->
- [ ] Tak ada error terkait debit simpanan di produksi
  <!-- source: flare | query: search_errors saldo_simpanan | threshold: count = 0 -->
- [ ] Review keamanan (siapa boleh debit, audit) selesai — desain sudah lewat critic + security (rev.3); verifikasi produksi menyusul
  <!-- source: manual -->

---

## Key Items

| # | Item | Effort | Parallel? | Status |
|---|------|--------|-----------|--------|
| 1a | Migrasi: enum `installments.payment_method`+`saldo_simpanan`; enum `savings_withdrawals.disbursement_method`+`internal`; kolom `savings_withdrawals.installment_id` (nullable, FK, index) | S | ✅ bisa sekarang | Done |
| 1b | Model `SavingsWithdrawal`: `$fillable`+`installment_id`, `reverseClone()` bawa `installment_id`, relasi `installment()`; konstanta label: `DISBURSEMENT_METHODS`+`internal`, `InstallmentResource::PAYMENT_METHODS`+`saldo_simpanan` (+ fallback blade listing penarikan) | S | setelah 1a | Done |
| 1c | `LoanPaymentService`: jalur debit `sukarela` di `pay()` (authorize, hard `sukarela`-only, nominal=total_due, **bukti consent WAJIB**, `canWithdraw`, lock member→loan, `Installment`+`SavingsWithdrawal` Cair ber-atribusi+`installment_id`, audit event) + exception `insufficientSavings()` | L | setelah 1b | Done |
| 1d | `reverse()`: balik debit berpasangan via `installment_id`, **exclude yang sudah dibalik**, **bypass guard `memberInactive`** (reverse debit selalu boleh) | M | setelah 1c | Done |
| 1e | Permission `pay_installment_from_savings` (Pengurus) + seeder + `InstallmentPolicy::payFromSavings`; **`InstallmentPolicy::reverse` gate `reverse_loan` untuk `saldo_simpanan`** (cegah privilege inversion) | M | setelah 1c | Done |
| 1f | Guard non-reversible terpisah: `SavingsWithdrawalPolicy::reverse` tolak baris ber-`installment_id` + sembunyikan aksi di ketiga UI (`SavingsWithdrawalResource`/`SavingsWithdrawals`/`SavingsWithdrawalDetail`) + **guard layer mutasi** (defense-in-depth) | M | setelah 1a | Done |
| 2a | UI `InstallmentForm`: pilih sumber (tunai/potong-gaji/**saldo sukarela**, opsi Pengurus-only) + saldo tersedia + validasi ≤ saldo + **upload bukti consent wajib** | L | setelah 1c, 1e | Done |
| 3a | ~~(fase lanjut) Batch — GATE KERAS: dual-control approver-berbeda + consent per-anggota (tak bisa di-borong) + idempotency per-batch teruji~~ | — | setelah 2a | **Won't-do** (2026-07-23) — lihat Open Questions |

**Effort:** S = < 1 jam, M = 1–3 jam, L = > 3 jam.
**Test-first (silent-money-bug):** 1d (reverse atomik + saldo pulih + anggota non-aktif tetap bisa), 1f (reverse-terpisah ditolak di 3 UI path), 1c (saldo turun tepat, tak double-count vs refund), 1e (Petugas 403 debit & reverse).

---

## Key Files

| File | Fungsi |
|------|--------|
| `database/migrations/xxxx_add_saldo_simpanan_and_settlement_link.php` | **Baru** — enum `installments.payment_method`+`saldo_simpanan`; enum `savings_withdrawals.disbursement_method`+`internal`; kolom `savings_withdrawals.installment_id` |
| `app/Services/LoanPaymentService.php` | Jalur debit `sukarela` di `pay()` + reversal berpasangan (via `installment_id`) di `reverse()` |
| `app/Services/SavingsBalanceService.php:229` | `canWithdraw('sukarela')` — cek solvabilitas (reuse) |
| `app/Models/SavingsWithdrawal.php` | `$fillable`+`installment_id`, relasi `installment()`, `reverseClone()` bawa `installment_id` |
| `app/Policies/SavingsWithdrawalPolicy.php` | `reverse()` **tolak** baris ber-`installment_id` (non-reversible terpisah) |
| `app/Filament/Resources/SavingsWithdrawalResource.php` + `app/Livewire/Savings/Withdrawal/SavingsWithdrawals.php` | Sembunyikan aksi reverse untuk baris debit berpasangan |
| `app/Exceptions/CannotProcessPayment.php` | **Baru** `insufficientSavings()` |
| `app/Livewire/Loan/Installment/InstallmentForm.php` + blade | UI pilih sumber (opsi sukarela Pengurus-only) + saldo tersedia + bukti consent |
| `database/seeders/RolePermissionSeeder.php` + `app/Policies/InstallmentPolicy.php` | Permission `pay_installment_from_savings` (Pengurus) + `payFromSavings()` |

---

## Verification

- [ ] Bayar angsuran dari `sukarela` bersaldo cukup → angsuran tercatat, saldo sukarela turun **tepat** sebesar tagihan.
- [ ] Saldo tak cukup → ditolak `insufficientSavings`, tak ada record dibuat (atomic).
- [ ] **Hanya `sukarela`**: coba debit `swp`/`tabungan_berjangka`/`hari_raya`/`pokok`/`wajib`/`wajib_belanja` → ditolak.
- [ ] **Otorisasi:** Petugas tak bisa (opsi tersembunyi + gate server-side 403 walau dipaksa); Pengurus bisa.
- [ ] **Debit ≠ refund:** bayar angsuran dari sukarela pada pinjaman, lalu pinjaman Lunas → refund SWP/Tab **tetap dibuat** (debit tak ter-`hasActiveRefund` karena tak pakai `related_loan_id`).
- [ ] **Non-reversible terpisah:** reverse baris debit langsung dari menu Penarikan → **ditolak** (hanya via `Installment::reverse`).
- [ ] Reverse angsuran-dari-simpanan → saldo pulih (debit berpasangan dibalik), jadwal kembali Belum Bayar, **dua** entri log terkorelasi.
- [ ] Balance konsisten: debit (Cair) dihitung sekali, tak double-count vs sisi angsuran (`SavingsStatsOverview` tetap benar).
- [ ] Race: dua pembayaran dari sukarela sama paralel tak over-debit / saldo negatif (lock member, urutan konsisten, no deadlock).
- [ ] Atribusi & audit: withdrawal berpasangan punya `approved_by`/`approved_at` terisi + event `debit_simpanan_angsuran` + bukti consent.
- [ ] Angsuran terakhir dibayar dari sukarela → tetap memicu Lunas + refund SWP/Tab seperti biasa.
- [ ] **Reverse-gate:** Petugas tak bisa reverse angsuran `saldo_simpanan` (403 — butuh `reverse_loan`/Pengurus); Petugas tak punya `pay_installment_from_savings`.
- [ ] **Anggota non-aktif:** reverse angsuran-dari-simpanan untuk anggota Keluar/Meninggal **tetap berhasil** (debit dibalik, saldo pulih).
- [ ] **Consent wajib:** debit tanpa bukti → ditolak (server-side), bukti tersimpan sebagai media angsuran.
- [ ] **enum `internal`:** listing penarikan (`savings-withdrawals` Livewire) tidak error "Undefined array key"; baris debit dilabeli/disembunyikan benar.
- [ ] **Reverse ganda:** reverse angsuran yang sama dua kali tak menggandakan pengembalian debit (exclude reversed).
- [ ] `saldo_simpanan` lolos whitelist `PAYMENT_METHODS` (submit tak ditolak validasi enum).

---

## Open Questions

- ~~Otorisasi?~~ **RESOLVED → Pengurus-only** (`pay_installment_from_savings`) + atribusi `approved_by`, debit langsung (bukan alur 2-langkah). Bukti consent anggota wajib.
- ~~Jenis yang boleh didebit?~~ **RESOLVED → `sukarela` SAJA** untuk rollout ini (swp/tab kolisi refund, hari_raya double-spend). Perluasan butuh gate arsitektural, bukan sekadar daftar.
- ~~Overpayment?~~ **RESOLVED → dikunci tepat-tagihan** saat sumber simpanan.
- ~~`disbursement_method` internal?~~ **RESOLVED → tambah enum `internal`** (migrasi) agar terpisah dari laporan kas tunai/transfer.
- ~~Reverse Petugas butuh `reverse_loan`?~~ **RESOLVED → ya, `reverse_loan` (Pengurus)** untuk `saldo_simpanan` (cegah privilege inversion).
- ~~Consent wajib/opsional?~~ **RESOLVED → WAJIB** (server-side), tersimpan sebagai media angsuran.
- **Consent — bentuk artefak:** cukup bukti media generik, atau formulir "kuasa pendebitan simpanan" khusus (lebih kuat untuk sengketa)? (default: bukti media wajib; formulir khusus = peningkatan opsional.)
- ~~**Batch (3a):** apakah benar perlu?~~ **RESOLVED → TIDAK (Won't-do, 2026-07-23).** Tiga alasan: (1) **Category error** — Batch Potong Gaji premisnya mendebit *gaji* (`BatchInstallmentPaymentService::METHOD = 'potong_gaji'` hardcoded); menyisipkan sumber simpanan mencampur dua domain arus dana. (2) **Consent tak bisa di-borong** — 1c mewajibkan bukti consent per angsuran; batch memproses N anggota sekaligus sehingga consent massal = persis "borong" yang dilarang. (3) **Dual-control belum ada** — batch single-actor; debit simpanan butuh approver-berbeda + idempotency khusus = fitur besar sensitif-keamanan yang nilainya diragukan. Bila suatu saat benar dibutuhkan, buka ADR baru dengan desain approval-ganda tersendiri — jangan tempel ke batch potong-gaji.
- **Invariant lintas-fitur:** "operasi yang menaikkan saldo withdrawable ≥ privilege operasi yang menurunkannya" — layak ditulis di `.claude/memory` agar tak hilang antar fitur.

---

## Pipeline trace (v1)

| Stage | Agent | Key output | Date |
|---|---|---|---|
| Framing | strategist | (retroactive — not invoked); framing dilakukan inline via diskusi + keputusan "pilih sumber pembayaran" | 2026-07-22 |
| Data baseline | data-analyst | skipped: fitur enabler, belum ada baseline produksi relevan | 2026-07-22 |
| Design | architect | (retroactive — not invoked); desain inline: debit atomik berpasangan (SavingsWithdrawal Cair) di pay(), preseden ShoppingTransaction | 2026-07-22 |
| Critique | critic | R1 **REJECT** (5 blocker) → R2 **APPROVE-with-changes** (fix `installment_id` bersih; sisa: reverse anggota non-aktif, enum `internal` blade, defense-in-depth) | 2026-07-23 |
| Security review | security-reviewer | R1 **BLOCK** (bypass maker-checker) → R2 **BLOCK** (privilege inversion reverse + consent opsional) → resolusi rev.3 | 2026-07-23 |
| Deploy review | deploy-reviewer | pending — 3 perubahan schema (2 enum ALTER + 1 kolom FK) pada tabel finansial hidup | — |
| Implementation | human | pending | — |
| Review | reviewer | pending | — |

**Ronde**: 3 (rev.1 → REJECT/BLOCK → rev.2 → APPROVE-w-changes/BLOCK → rev.3)
**Skipped stages**: data-analyst (fitur enabler, no baseline)
**Calibration notes**: rev.1 miss di Design — "transfer internal" salah (sukarela withdrawable) + reuse `related_loan_id` tabrak refund. rev.2 miss: **asimetri forward/reverse** (turun butuh Pengurus, naik cukup Petugas) & guard `memberInactive` salah-arah untuk reverse-debit — baru ketahuan di ronde-2. Pelajaran: untuk operasi yang mengubah saldo, cek BOTH arah (naik & turun) + interaksi dengan lifecycle anggota (Keluar/Meninggal), bukan hanya jalur maju.

---

## Changelog

- **2026-07-22 v1**: Initial draft. Keputusan bisnis "pilih sumber pembayaran" (bukan bucket khusus). Desain: debit atomik berpasangan via SavingsWithdrawal Cair, preseden ShoppingTransaction.
- **2026-07-22 v2**: Pasca critic REJECT + security BLOCK. Governance Pengurus-only + atribusi (argumen "transfer internal" dicabut); `sukarela`-only; penanda `installment_id` (bukan `related_loan_id`); debit non-reversible terpisah; enum `internal`; overpay dikunci; lock member. Preseden ShoppingTransaction diakui tak transferable.
- **2026-07-23 close-3a**: Item 3a (Batch) ditutup **Won't-do** — category error (batch = potong gaji), consent tak bisa di-borong, dual-control belum ada & nilainya diragukan. Open Question di-resolve. **ADR ini selesai: 1a-1f + 2a Done, 3a Won't-do.** Status → Implemented.
- **2026-07-23 impl-2a**: UI `InstallmentForm` — opsi metode bayar sadar-izin (`paymentMethodOptions()` unset `saldo_simpanan` bila tak punya `payFromSavings` / saat pelunasan); `updatedPaymentMethod()` kunci nominal = tagihan; consent bukti wajib (`Rule::requiredIf(fromSavings)`); validasi tepat-tagihan + ≤ saldo sukarela; `settle()` tolak `saldo_simpanan` (settleEarly tak punya jalur debit); toggle pelunasan reset sumber ke potong_gaji. Blade: dropdown `.live`, info saldo, nominal readonly, label bukti "wajib". 8 test UI hijau; **full suite 606 passed**. **Semua Key Items non-Batch SELESAI (1a-1f + 2a) — fitur siap dipakai end-to-end.** Sisa hanya 3a (Batch, opsional + gated keras).
- **2026-07-23 impl-1f**: Debit berpasangan non-reversible terpisah — 3 layer: (1) `SavingsWithdrawalPolicy::reverse` tolak baris ber-`installment_id` (nutup 3 UI otomatis via `can('reverse')`); (2) `SavingsWithdrawalResource::canReverseBase` tambah `installment_id === null` (UI hide defense); (3) guard layer-mutasi `ReverseTransaction` — tolak `SavingsWithdrawal` ber-`installment_id` kecuali flag `allowPairedInstallmentDebit` (hanya dari `LoanPaymentService::reverse`). Exception `pairedInstallmentDebit()`. Test: 2 baru (mutation guard + policy/UI, dengan kontrol pencairan biasa tetap reversible); **full suite 598 passed**. **Backend ADR ini SELESAI (1a-1f).** Sisa: **2a** (UI form) + **3a** (Batch, opsional). Fitur aman diaktifkan end-to-end begitu 2a jadi.
- **2026-07-23 impl-1d/1e**: **1d** — `LoanPaymentService::reverse()` membalik debit berpasangan via `installment_id` (exclude sudah-dibalik `whereNotIn(reversed_ids)`); param baru `ReverseTransaction($…, allowInactiveMember: true)` mem-bypass guard `memberInactive` khusus reverse-debit (mengembalikan saldo → selalu boleh walau anggota Keluar/Meninggal). **1e** — permission `pay_installment_from_savings` → role Pengurus (seeder); `InstallmentPolicy::payFromSavings`; `InstallmentPolicy::reverse` gate `reverse_loan` untuk `payment_method='saldo_simpanan'` (cegah privilege inversion). Test: 1d (3) + 1e RBAC (1) hijau; **full suite 596 passed**. Sisa: **1f** (guard non-reversible dari UI Penarikan) + **2a** (UI) sebelum fitur aman diaktifkan end-to-end.
- **2026-07-23 impl-1b/1c**: Model `SavingsWithdrawal` (`installment_id` fillable + relasi `installment()` + `reverseClone()`), konstanta label (`PAYMENT_METHODS`+`saldo_simpanan`, `DISBURSEMENT_METHODS`+`internal`, fallback blade listing penarikan). `LoanPaymentService::pay()` jalur debit `sukarela`: gate `pay_installment_from_savings` (service-level, defense-in-depth), consent WAJIB, tepat-tagihan, `canWithdraw`, lock member→loan→schedule, `SavingsWithdrawal` Cair ber-atribusi (`approved_by`/`approved_at`/`disbursed_at`, `disbursement_method='internal'`, `installment_id`), audit `debit_simpanan_angsuran`. Exception `insufficientSavings`/`consentRequired`/`savingsMustEqualBill`. 9 test hijau + 229 suite terkait hijau. **Catatan:** wiring permission→role Pengurus + `InstallmentPolicy::payFromSavings` + reverse-gate = item 1e (belum). Reverse debit berpasangan = item 1d (belum) — **jangan ship sebelum 1d+1e** (reverse angsuran `saldo_simpanan` sekarang belum membalik debit).
- **2026-07-23 impl-1a**: Migrasi 1a (`2026_07_23_000001_add_saldo_simpanan_and_installment_link`). **Deviasi implementasi:** kedua enum (`payment_method`, `disbursement_method`) dilebarkan ke `string` — bukan enum literal — mengikuti konvensi project (`allow_dibatalkan_loan_status`) demi portabilitas MySQL(prod)/SQLite(test); nilai sah dijaga di app level (item 1b/1c). `installment_id` = `foreignUuid` nullable + FK (auto-index). up+down terverifikasi bersih di SQLite.
- **2026-07-23 v3**: Pasca critic APPROVE-with-changes + security BLOCK ronde-2. Reverse `saldo_simpanan` di-gate `reverse_loan` (privilege inversion); reverse-debit bypass guard `memberInactive` (selalu boleh, cegah buntu saat anggota keluar); consent bukti WAJIB server-side; enum `internal` + label map + fallback blade; `PAYMENT_METHODS`+`saldo_simpanan`; defense-in-depth reverse (layer mutasi); exclude sudah-di-reverse; prasyarat keras Batch (dual-control).

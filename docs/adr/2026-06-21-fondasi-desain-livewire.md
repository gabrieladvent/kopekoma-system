# Fondasi Desain UI Livewire (Design System KOPEKOMA)

Membangun fondasi tampilan berbasis Livewire 3 + Alpine + Tailwind v4 dengan design system seragam (token warna custom, dark mode, komponen baku) sebagai dasar pergeseran UI dari Filament.

**Author:** gabrieladvent
**Date:** 2026-06-21
**Status:** Draft

---

## Background

Sistem KOPEKOMA saat ini berjalan dengan **Filament 3.3** sebagai lapisan UI admin. Tim ingin **menggeser tampilan ke Livewire standalone** agar punya kontrol penuh atas tampilan dan identitas visual — bukan terikat look & feel default Filament.

Masalah yang ingin dihindari: tiap halaman Livewire dibuat ad-hoc sehingga tampilan tidak seragam. Karena itu sebelum membangun halaman fitur, perlu **design system** lebih dulu: token, tipografi, animasi, dan komponen baku.

Arah visual sudah disepakati (lihat memory [[livewire-style-guide]] & [[livewire-component-patterns]]):
- Font **Plus Jakarta Sans** — formal tapi tidak kaku.
- **Minimalis tapi tidak miskin**, dan **fresh/out-of-the-box** (anti admin-template generik).
- Primary/secondary **bisa di-custom user lewat Settings**; default emerald/teal.
- **Light + Dark mode**.
- Animasi **smooth, tidak over** (Alpine `x-transition` + Tailwind, tanpa lib animasi tambahan).

Stack existing: Laravel 12, Filament 3.3, **Tailwind v4** (`@tailwindcss/vite`), Vite 7. Livewire 3 sudah ikut terbawa via Filament; dipakai langsung untuk halaman non-Filament.

---

## Goals

- Token desain terpusat di `resources/css/app.css` via CSS custom properties (warna, font).
- Primary/secondary **overridable runtime** dari Settings tanpa rebuild CSS.
- Dark mode berbasis class (`<html class="dark">`) + persist preferensi.
- Set komponen Blade baku `<x-ui.*>` (button, input, card, table, badge, modal, toast, tabs, sidebar item, empty state, skeleton).
- Satu **layout app** Livewire (shell: sidebar + topbar) yang konsisten & anti-template.
- Halaman **showcase/styleguide** untuk verifikasi visual semua komponen.

## Non-Goals

- **Tidak** memigrasikan resource Filament yang sudah ada ke Livewire di ADR ini (itu kerja terpisah per modul).
- **Tidak** membongkar logika/keuangan apa pun — murni lapisan presentasi.
- **Tidak** membuat halaman fitur (Anggota/Simpanan/dll) — hanya fondasi + showcase.

---

## Design

### Approach

Bangun design system sebagai lapisan presentasi mandiri, **berdampingan** dengan Filament (Filament tetap jalan; halaman Livewire baru memakai layout & komponen sendiri). Tidak ada perubahan backend.

1. **Token (`app.css`)** — `@theme` mendefinisikan `--font-sans`, `--color-primary/secondary/...`, neutral/surface/border/status. Kelas dipakai via token (`bg-primary`, `text-muted`), **dilarang** hardcode (`bg-emerald-600`).
2. **Custom theme** — Settings menyimpan hex primary/secondary; layout meng-inject `<style>:root{--color-primary:…;--color-secondary:…}</style>` di `<head>`. Default emerald/teal bila kosong.
3. **Dark mode** — toggle set `class="dark"` di `<html>` (Alpine + localStorage + `prefers-color-scheme` sebagai default awal); blok variabel `.dark` menimpa neutral/surface.
4. **Komponen** — Blade components di `resources/views/components/ui/`, anatomi mengikuti [[livewire-component-patterns]].
5. **App shell** — layout Livewire `layouts.app`: sidebar (item aktif accent kiri), topbar (theme toggle + user), area konten `max-w-7xl`. Signature anti-template diterapkan di shell & dashboard (bento).
6. **Showcase** — route `/styleguide` me-render semua komponen untuk QA visual & acuan dev.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|-------------|-----|-----|---------|
| CSS variables + Blade `<x-ui.*>` (dipilih) | Custom theme runtime mudah; ringan; idiomatik Tailwind v4; dark mode satu sumber | Perlu disiplin "no hardcode color" | **Chosen** |
| Tailwind config statis + kelas warna langsung | Sederhana | Custom theme per-user mustahil tanpa rebuild | Rejected |
| Adopsi Flux/library UI | Cepat | Look generik, lawan goal "out of the box"; lock-in | Rejected |

---

## Rollout Plan

| Phase | Behavior | Status |
|-------|----------|--------|
| 0 | Token + `app.css` + font Plus Jakarta Sans terpasang | Done |
| 1 | App shell (`layouts.app`) + dark mode toggle | Active |
| 2 | Komponen `<x-ui.*>` lengkap + halaman `/styleguide` | Pending |
| 3 | Custom theme dari Settings (inject CSS var) | Pending |

### Phase Transition Checklist

**Phase 0 → 1:** ✅ Validated 2026-06-22
- [x] `npm run build` sukses; font ter-load; token terbaca sebagai utility.
  <!-- validated: vite v7.3.5 build OK (app-*.css 107.7kB); Bunny Fonts plus-jakarta-sans di app/guest layout; utilities .bg-primary/.text-muted/.border-border/.bg-grid/.bg-brand-gradient ter-generate -->
  <!-- catatan: fresh clone perlu `rm -rf node_modules package-lock.json && npm install` dulu (bug rollup optional-deps @rollup/rollup-darwin-arm64) -->


**Phase 1 → 2:**
- [ ] Shell render rapi di light & dark; toggle persist; reduced-motion dihormati.

**Phase 2 → 3:**
- [ ] Semua komponen tampil benar di `/styleguide` (light+dark), kontras AA.

---

## Key Files

| File | Fungsi |
|------|--------|
| `resources/css/app.css` | Token `@theme` + variabel `.dark` + font |
| `resources/views/components/ui/*.blade.php` | Komponen baku (button, input, card, dst.) |
| `resources/views/components/layouts/app.blade.php` | App shell (sidebar + topbar + theme inject) |
| `resources/views/styleguide.blade.php` | Halaman showcase komponen |
| `routes/web.php` | Route `/styleguide` |
| `resources/js/app.js` | Inisialisasi theme (dark toggle, localStorage) |

---

## Verification

- [ ] `npm run build` & `npm run dev` jalan tanpa error.
- [ ] `/styleguide` menampilkan semua komponen, light & dark.
- [ ] Ganti hex primary di `:root` → semua komponen ikut berubah (bukti token, bukan hardcode).
- [ ] `prefers-reduced-motion` mematikan animasi non-esensial.
- [ ] Kontras teks/primary memenuhi WCAG AA di kedua mode.

---

## Open Questions

- Apakah Settings primary/secondary global (per koperasi) atau per-user? (Asumsi awal: **global**, satu identitas.)
- Navigasi Livewire baru hidup di prefix route apa, dan bagaimana koeksistensi dengan panel Filament `/admin` selama transisi?
- ~~Self-host Plus Jakarta Sans (offline-friendly) atau Google Fonts CDN?~~ → **Resolved (2026-06-22):** pakai **Bunny Fonts CDN** (`fonts.bunny.net`, privacy-friendly) di app & guest layout. Self-host bisa ditinjau ulang bila butuh offline-friendly.

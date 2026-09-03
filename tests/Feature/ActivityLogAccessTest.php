<?php

/**
 * Akses Log Aktivitas (ADR 2026-08-28 v26 — temuan `security-reviewer`).
 *
 * Jejak log adalah salah satu dari tiga kanal pendeteksian yang jadi syarat
 * diterimanya R14 (risiko korupsi loket). Sebelum ini rutenya dijaga gate
 * `manage-system` = super_admin, sementara menu sidebar-nya sudah tampil untuk
 * Pengurus — jadi orang yang seharusnya memakai kanal itu melihat menunya lalu
 * kena 403. Kanal yang begitu bukan kanal.
 *
 * Yang dikunci: Pengurus masuk, Petugas tidak, dan pemisahannya tidak ikut
 * membuka modul Sistem (Pengguna & Peran tetap super_admin).
 */
it('lets pengurus open the activity log', function () {
    asPengurus();

    $this->get(route('system.activity-logs'))->assertOk();
});

it('still keeps petugas out of the activity log', function () {
    asPetugas();

    $this->get(route('system.activity-logs'))->assertForbidden();
});

it('does not hand pengurus the rest of the system module', function () {
    asPengurus();

    $this->get(route('system.users'))->assertForbidden();
    $this->get(route('system.roles'))->assertForbidden();
});

it('grants the permission through the role, not a hardcoded role check', function () {
    $pengurus = asPengurus();
    $petugas = asPetugas();

    expect($pengurus->can('access_activity_log'))->toBeTrue()
        ->and($petugas->can('access_activity_log'))->toBeFalse();
});

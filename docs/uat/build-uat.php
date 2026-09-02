<?php

/**
 * Membangun UAT-Titipan-Pokok.xlsx dari daftar skenario di bawah.
 *
 * Skrip ini yang menghasilkan berkas untuk penguji; kolom Hasil Aktual, Status,
 * dan Catatan sengaja dikosongkan. Disimpan di repo supaya berkas Excel-nya bisa
 * dibangun ulang saat skenarionya berubah — kalau tidak, .xlsx jadi berkas biner
 * yang tak ada yang berani menyentuh dan pelan-pelan menyimpang dari
 * titipan-pokok.md.
 *
 * Jalankan:
 *   php docs/uat/build-uat.php docs/uat/UAT-Titipan-Pokok.xlsx
 */
require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$INK = '1F2937';       // teks utama
$MUTED = '6B7280';     // teks sekunder
$HEAD = '111827';      // header gelap
$BAND = 'F3F4F6';      // pita bagian
$WAJIB = 'FEF2F2';     // penanda skenario wajib
$LINE = 'E5E7EB';

$book = new Spreadsheet;
$book->getProperties()
    ->setCreator('KOPEKOMA')
    ->setTitle('UAT — Titipan Pokok & Simpanan Pinjaman')
    ->setDescription('Daftar uji terima manual. Sumber: docs/uat/titipan-pokok.md');

// ───────────────────────────────────────────────────────── Sheet 1: Petunjuk
$s = $book->getActiveSheet();
$s->setTitle('Petunjuk');
$s->setShowGridlines(false);

$row = 1;
$put = function (string $text, int $size = 11, bool $bold = false, ?string $color = null) use ($s, &$row, $INK) {
    $s->setCellValue("B{$row}", $text);
    $s->getStyle("B{$row}")->getFont()->setSize($size)->setBold($bold)
        ->getColor()->setRGB($color ?? $INK);
    $s->getStyle("B{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    $row++;
};

$put('UAT — Titipan Pokok & Simpanan Pinjaman', 18, true);
$put('Uji terima manual. Angka-angka di kolom "Hasil yang Diharapkan" bukan perkiraan — semuanya dikunci test otomatis, jadi bila layar berbeda dari yang tertulis, yang salah sistemnya.', 10, false, $MUTED);
$row++;

$put('Cara mengisi', 13, true);
$put('Kerjakan berurutan per bagian. Isi kolom Hasil Aktual dan Status pada sheet "Skenario". Kolom Catatan untuk apa pun yang perlu dijelaskan.', 10);
$put('Status: OK = sesuai · GAGAL = tidak sesuai · TERLEWAT = tidak sempat diuji · N/A = tidak berlaku', 10, false, $MUTED);
$row++;

$put('Menyiapkan data', 13, true);
$put('php artisan migrate:fresh --seed', 10);
$put('php artisan db:seed --class=DemoDataSeeder', 10);
$put('php artisan db:seed --class=TitipanPokokDemoSeeder', 10);
$put('Kalau menguji di database yang sudah ada, jalankan juga: php artisan db:seed --class=RolePermissionSeeder — tanpa itu tiga permission baru tak ada dan Bagian 7, 9.4, 10.6 akan gagal.', 10, false, $MUTED);
$row++;

$put('Login', 13, true);
$put('Petugas    : petugas@kopekoma.test / password', 10);
$put('Pengurus   : pengurus@kopekoma.test / password', 10);
$put('Seluruh pinjaman uji ada di OPD Dinas Perhubungan Kab. Magelang.', 10, false, $MUTED);
$row++;

$put('Angka acuan — semua pinjaman uji 12.000.000 / 12 bulan', 13, true);
$put('Pokok/bulan 1.000.000  +  Jasa/bulan 78.000  +  Tab. Berjangka/bulan 12.000  =  Tagihan 1.090.000', 10);
$put('SWP 1% = 120.000 (sekali, saat pencairan). Titipan Pokok hanya memotong POKOK, jadi tagihan efektif tak pernah turun di bawah 90.000.', 10, false, $MUTED);
$row++;

$put('Setelan yang memengaruhi hasil', 13, true);
$put('Pengaturan → Pinjaman → Bulan Pembagian SHU. Kosong = jadwal Tabungan Berjangka memakai aturan 12 bulan sejak pencairan terakhir tiap anggota. Diisi = hanya boleh cair di bulan itu, sekali per tahun. Skenario 9.4a menguji yang pertama, 9.4b yang kedua.', 10);
$row++;

$put('Dua skenario yang WAJIB diuji', 13, true);
$put('4.1  — penolakan pembatalan harus meninggalkan jejak di Log Aktivitas. Jejak ini pernah tidak tersimpan sama sekali.', 10);
$put('5.4  — membatalkan setoran pembuat titipan pada pinjaman yang sudah dilunasi harus DITOLAK. Kalau berhasil, jangan diteruskan ke produksi.', 10);
$row++;

$put('Melaporkan temuan', 13, true);
$put('Sebutkan kode skenario, apa yang muncul di layar, dan nomor angsuran/pinjaman yang terlibat. Bila menyangkut angka, tulis angka yang muncul dan angka yang diharapkan — selisihnya yang paling cepat menunjukkan letak salahnya.', 10);

$s->getColumnDimension('A')->setWidth(3);
$s->getColumnDimension('B')->setWidth(120);

// ───────────────────────────────────────────────────── Sheet 2: Data Uji
$d = $book->createSheet();
$d->setTitle('Data Uji');
$d->setShowGridlines(false);

$d->fromArray(['Kode', 'Anggota', 'Keadaan awal', 'Dipakai bagian'], null, 'A1');

$dataUji = [
    ['T1', 'Rahayu Kusumaningrum', '2 angsuran terbayar, titipan 0. Bersih.', '1.1, 1.2, 1.3'],
    ['T2', 'Yusuf Maulana', 'Titipan 1.090.000. Angsuran berikutnya #3, tagihan efektif 90.000.', '2, 2.1, 2.2'],
    ['T3', 'Wahyu Nugroho', 'Angsuran #1 & #2 tertutup SATU setoran (satu kunci sesi). Titipan 0.', '3, 3.1'],
    ['T4', 'Ratna Dewi Anggraini', 'Titipan sudah terpakai angsuran #2, sisa 90.000.', '4, 4.1'],
    ['T5', 'Hesti Prabaningrum', 'LUNAS via Pelunasan Dipercepat. Titipan 2.000.000: 1.000.000 memotong pelunasan, 1.000.000 ke Sukarela.', '5.2, 5.3, 5.4, 9.2'],
    ['T6', 'Bagus Prakoso', 'Titipan 1.090.000, sisa pokok 8.000.000. Payoff harus 6.988.000.', '5.1, 6'],
];
$d->fromArray($dataUji, null, 'A2');

$d->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$d->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($HEAD);
$d->getStyle('A1:D'.(count($dataUji) + 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
$d->getStyle('A1:D'.(count($dataUji) + 1))->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($LINE);
$d->getColumnDimension('A')->setWidth(8);
$d->getColumnDimension('B')->setWidth(26);
$d->getColumnDimension('C')->setWidth(70);
$d->getColumnDimension('D')->setWidth(20);
$d->freezePane('A2');

$dTab = 8 + count($dataUji);
$d->setCellValue('A'.($dTab + 2), 'Tabungan Berjangka terkumpul per pinjaman uji (untuk Bagian 9.1)');
$d->getStyle('A'.($dTab + 2))->getFont()->setBold(true);
$d->fromArray(['Kode', 'Angsuran dibayar', 'Tab. Berjangka', 'SWP'], null, 'A'.($dTab + 3));
$d->fromArray([
    ['T1', 2, '24.000', '120.000'],
    ['T2', 2, '24.000', '120.000'],
    ['T3', 2, '24.000', '120.000'],
    ['T4', 2, '24.000', '120.000'],
    ['T5', '11 (+1 pelunasan)', '132.000 — baris pelunasan TIDAK menambah', '120.000'],
    ['T6', 4, '48.000', '120.000'],
], null, 'A'.($dTab + 4));
$d->getStyle('A'.($dTab + 3).':D'.($dTab + 3))->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$d->getStyle('A'.($dTab + 3).':D'.($dTab + 3))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($HEAD);
$d->getStyle('A'.($dTab + 3).':D'.($dTab + 9))->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($LINE);

// ─────────────────────────────────────────────────── Sheet 3: Skenario
$t = $book->createSheet();
$t->setTitle('Skenario');
$t->setShowGridlines(false);

$cols = ['No.', 'Kode', 'Peran', 'Langkah', 'Hasil yang Diharapkan', 'Hasil Aktual', 'Status', 'Catatan'];
$t->fromArray($cols, null, 'A1');

/** @var array<int, array{0:string,1:string,2:string,3:string,4:string,5:bool}> */
$rows = [];
$section = function (string $title) use (&$rows) {
    $rows[] = ['__SECTION__', $title, '', '', '', false];
};
$step = function (string $code, string $role, string $langkah, string $harapan, bool $wajib = false) use (&$rows) {
    $rows[] = [$code, $role, $langkah, $harapan, '', $wajib];
};

$section('BAGIAN 1 — Dialog alokasi (pakai T1: Rahayu Kusumaningrum)');
$step('1.1', 'Petugas', 'Angsuran → Catat Pembayaran. Pilih anggota & pinjaman T1.', 'Nominal ter-prefill 1.090.000.');
$step('1.1', 'Petugas', 'Ubah nominal jadi 1.100.000, lalu Simpan.', 'Tersimpan LANGSUNG tanpa dialog. Titipan jadi 10.000. (Dialog hanya muncul bila sisa uang cukup menutup satu angsuran penuh.)');
$step('1.2', 'Petugas', 'Ulangi seeder / pakai T1 bersih. Isi nominal 2.180.000, Simpan.', 'DIALOG MUNCUL. Belum ada yang tersimpan.');
$step('1.2', 'Petugas', 'Baca pilihan "Simpan sebagai Titipan Pokok" di dialog.', 'Angsuran tertutup: #3. Sisa Titipan Pokok: 1.090.000. Tagihan berikutnya: #4 → 90.000 dan #5 → 1.000.000.');
$step('1.2', 'Petugas', 'Baca pilihan "Tutup angsuran berikutnya sekalian".', 'Angsuran tertutup: #3 dan #4. Sisa Titipan Pokok: 0. Tagihan berikutnya: #5 → 1.090.000.');
$step('1.3', 'Petugas', 'Klik "Simpan sebagai Titipan Pokok".', 'Langsung tersimpan TANPA klik konfirmasi kedua. 1 baris angsuran dibuat.');
$step('1.3', 'Petugas', 'Buka detail pinjaman T1.', 'Saldo Titipan Pokok 1.090.000.');
$step('1.3', 'Petugas', 'Tekan tombol kembali browser lalu simpan ulang.', 'TIDAK menghasilkan baris angsuran kedua.');

$section('BAGIAN 2 — Tagihan efektif & kuitansi (pakai T2: Yusuf Maulana)');
$step('2', 'Petugas', 'Catat Pembayaran. Pilih anggota & pinjaman T2.', 'Nominal ter-prefill 90.000 — bukan 1.090.000.');
$step('2', 'Petugas', 'Baca panel tagihan.', 'Menampilkan Tagihan kontrak 1.090.000, Titipan Pokok dipakai 1.000.000, Tagihan Bulan Ini 90.000.');
$step('2', 'Petugas', 'Cari kalimat "dikreditkan ke Simpanan Sukarela" di layar.', 'TIDAK ADA. Sudah dicabut dari layar loket.');
$step('2', 'Petugas', 'Isi 89.999, Simpan.', 'DITOLAK — "Nominal tidak boleh kurang dari tagihan Rp 90.000".');
$step('2', 'Petugas', 'Isi 90.000, Simpan.', 'Tersimpan. Titipan jadi 0.');
$step('2.1', 'Petugas', 'Buka kuitansi baris ANG-2026-000004 (setoran 2.180.000 milik Yusuf).', 'Pokok 1.000.000 + Jasa 78.000 + Tab. Berjangka 12.000 + Titipan Pokok disisihkan 1.090.000 = Total 2.180.000. Sisa Titipan Pokok 1.090.000.');
$step('2.1', 'Petugas', 'Jumlahkan komponen kuitansi.', 'Σ komponen SAMA PERSIS dengan total. Kuitansi yang tak berjumlah = dokumen salah yang diserahkan ke anggota.');
$step('2.2', 'Petugas', 'Detail Pinjaman T2 → panel Riwayat Titipan Pokok.', 'Satu baris: ANG-…04, Masuk 1.090.000, Saldo 1.090.000.');
$step('2.2', 'Petugas', 'Cari baris pembayaran yang pas (tidak menggerakkan saldo).', 'TIDAK MUNCUL. Panel ini menjelaskan pergerakan, bukan mendaftar transaksi.');

$section('BAGIAN 3 — Satu setoran, dua angsuran (pakai T3: Wahyu Nugroho)');
$step('3', 'Petugas', 'Detail pinjaman T3 → lihat daftar angsuran.', 'DUA baris, keduanya 1.090.000, angsuran #1 dan #2.');
$step('3', 'Petugas', 'Buka detail salah satu baris.', 'Menampilkan keterkaitan sesi — "satu setoran bersama ANG-…".');
$step('3', 'Petugas', 'Cek lampiran bukti (bila setoran dibuat dengan bukti).', 'Bukti melekat di KEDUA baris, bukan hanya yang pertama.');
$step('3', 'Petugas', 'Cek saldo Titipan Pokok T3.', '0.');
$step('3.1', 'Petugas', 'Batalkan baris angsuran #2 saja.', 'Berhasil. Jadwal #2 kembali Belum Bayar; #1 tetap terbayar.');
$step('3.1', 'Petugas', 'Cek Sisa Pokok di detail angsuran #1.', '11.000.000 — sama dengan sisa pokok pinjaman sebenarnya.');

$section('BAGIAN 4 — Guard pembatalan (pakai T4: Ratna Dewi Anggraini)');
$step('4', 'Petugas', 'Coba batalkan angsuran #1 (setoran 2.180.000).', 'DITOLAK. Pesan menyebut nomor angsuran penghalang: "titipannya sudah dipakai angsuran ANG-… Batalkan angsuran ANG-… lebih dulu."');
$step('4', 'Petugas', 'Cek daftar angsuran setelah penolakan.', 'TIDAK ADA baris pembalik yang tertinggal. Seluruh transaksi dibatalkan.');
$step('4', 'Petugas', 'Batalkan angsuran #2 lebih dulu.', 'Berhasil. Titipan kembali 1.090.000.');
$step('4', 'Petugas', 'Lalu batalkan angsuran #1.', 'Sekarang berhasil. Titipan kembali 0.');
$step('4.1', 'Pengurus', 'Sistem → Log Aktivitas. Cari event "Pembatalan ditolak".', 'ADA. Penolakan tanpa jejak berarti percobaan berulang tak terlihat siapa pun.', true);
$step('4.1', 'Pengurus', 'Buka isi jejaknya.', 'Menampilkan Titipan Pokok sesudah (negatif), Titipan Pokok tertahan pelunasan, dan Angsuran penghalang — dengan label Indonesia dan format rupiah, bukan nama kolom mentah.', true);

$section('BAGIAN 5 — Pelunasan Dipercepat bertitipan (pakai T5 dan T6)');
$step('5.1', 'Petugas', 'Catat Pembayaran untuk T6 (Bagus Prakoso). Isi nominal 7.000.000, Simpan.', 'DITOLAK dengan arahan: "Nominal ini cukup untuk melunasi seluruh sisa pinjaman. Gunakan Pelunasan Dipercepat — jumlahnya Rp 6.988.000…"');
$step('5.1', 'Petugas', 'Periksa angka pelunasannya.', '6.988.000 = sisa pokok 8.000.000 + jasa 78.000 − titipan 1.090.000. Bila layar menyebut 8.078.000, potongan titipannya hilang.');
$step('5.2', 'Petugas', 'Detail pinjaman T5 (Hesti) — cek status.', 'Lunas.');
$step('5.2', 'Petugas', 'Cek nominal baris pelunasan.', '78.000 = sisa pokok 1.000.000 + jasa 78.000 − titipan terpakai 1.000.000.');
$step('5.2', 'Petugas', 'Cek rincian baris pelunasan.', 'Kolom "Titipan Pokok dipakai" terisi 1.000.000, bukan kosong.');
$step('5.2', 'Pengurus', 'Cek Simpanan Sukarela Hesti.', 'Bertambah 1.000.000 — sisa titipan yang tak terpakai, TIDAK hangus.');
$step('5.3', 'Petugas', 'Panel Riwayat Titipan Pokok T5 — baca TIGA baris terakhir.', 'Baris 1: ANG-…19 Masuk 2.000.000, saldo 2.000.000. Baris 2: Dipakai 1.000.000, saldo 1.000.000, catatan "Memotong jumlah Pelunasan Dipercepat". Baris 3: Dipakai 1.000.000, saldo 0, catatan "Dilimpahkan ke Simpanan Sukarela saat pinjaman ditutup".');
$step('5.4', 'Petugas', 'Coba batalkan ANG-…19 (setoran 3.090.000 yang membuat titipan) — biarkan baris pelunasannya berdiri.', 'DITOLAK, dengan pesan menunjuk BARIS PELUNASAN sebagai penghalang.', true);
$step('5.4', 'Petugas', 'Cek status pinjaman T5 setelah penolakan.', 'Tetap Lunas. Sisa pokok tetap 0.', true);
$step('5.4', 'Pengurus', 'Batalkan baris pelunasan lebih dulu.', 'Berhasil. Status kembali Cair, titipan kembali 2.000.000.', true);
$step('5.4', 'Petugas', 'Lalu batalkan ANG-…19.', 'Sekarang berhasil. (Bila langkah pertama tadi BERHASIL, jangan diteruskan ke produksi.)', true);

$section('BAGIAN 6 — Batch potong gaji (OPD Dinas Perhubungan)');
$step('6', 'Petugas', 'Angsuran → Batch Potong Gaji. Pilih OPD Dinas Perhubungan Kab. Magelang.', 'Muncul baris untuk pinjaman yang masih berjalan.');
$step('6', 'Petugas', 'Lihat baris Bagus Prakoso (T6) — kolom pelunasan.', '6.988.000 — sudah dikurangi titipan.');
$step('6', 'Petugas', 'Lihat nominal angsuran biasa.', 'Tetap 1.090.000 (angka kontrak). Payroll memotong angka kontrak, bukan angka efektif.');
$step('6', 'Petugas', 'Proses batch.', 'Berhasil. Titipan T6 TIDAK berkurang oleh potongan sebesar kontrak.');
$step('6.1', 'Petugas', 'Bayar satu angsuran manual dulu, lalu jalankan batch yang memuat jadwal yang sama.', 'Ringkasan menyebut jumlah yang dilewati.');
$step('6.1', 'Pengurus', 'Log Aktivitas → event "Batch Angsuran Potong Gaji".', 'Memuat DAFTAR baris yang dilewati: nomor pinjaman, nama anggota, dan SEBABNYA — bukan hanya angka "1 dilewati".');
$step('6.2', 'Petugas', 'Coba centang pelunasan pada baris batch.', 'Tidak tersedia / ditolak 403.');
$step('6.2', 'Pengurus', 'Centang pelunasan pada baris batch.', 'Tersedia, tapi WAJIB centang konfirmasi dulu sebelum diproses.');

$section('BAGIAN 7 — Laporan agregat & hak akses');
$step('7.1', 'Pengurus', 'Laporan → Laporan Titipan Pokok. Lihat Total Titipan Pokok Mengendap.', '2.270.000 (T2 1.090.000 + T4 90.000 + T6 1.090.000).');
$step('7.1', 'Pengurus', 'Hitung jumlah baris.', '3 — hanya pinjaman yang benar-benar bertitipan.');
$step('7.1', 'Pengurus', 'Cek urutannya.', 'Terbesar dulu: Yusuf / Bagus (1.090.000), lalu Ratna (90.000).');
$step('7.1', 'Pengurus', 'Cek kolomnya.', 'Tagihan Kontrak dan Tagihan Efektif tampil BERDAMPINGAN.');
$step('7.1', 'Pengurus', 'Cari T5 (Hesti) di laporan.', 'TIDAK MUNCUL — sudah Lunas, titipannya sudah keluar.');
$step('7.1', 'Pengurus', 'Coba filter OPD & pencarian.', 'Berfungsi; total ikut menyesuaikan.');
$step('7.2', 'Petugas', 'Buka Laporan Titipan Pokok.', '403. Menunya juga tidak muncul di sidebar.');
$step('7.2', 'Petugas', 'Buka Sistem → Log Aktivitas.', '403.');
$step('7.2', 'Pengurus', 'Buka Sistem → Log Aktivitas.', 'BISA dibuka.');
$step('7.2', 'Pengurus', 'Buka Sistem → Pengguna dan Sistem → Peran.', '403 — membaca jejak ≠ mengelola sistem.');

$section('BAGIAN 8 — Regresi (yang TIDAK boleh berubah)');
$step('8', 'Petugas', 'Buka pinjaman yang tak pernah kelebihan bayar (mis. Sri Wahyuni dari DemoDataSeeder).', 'Berperilaku persis seperti sebelumnya. Tak ada panel Titipan Pokok, tak ada baris tambahan di kuitansi.');
$step('8', 'Pengurus', 'Bayar angsuran dari saldo simpanan.', 'Tetap terkunci TEPAT sebesar tagihan efektif — tidak boleh lebih.');
$step('8', 'Petugas', 'Sebrakan (jangka_pendek) dengan kelebihan bayar.', 'Kelebihannya LANGSUNG ke Simpanan Sukarela saat lunas, tanpa singgah jadi titipan.');
$step('8', 'Pengurus', 'Cek angka tunggakan di dashboard.', 'Anggota bertitipan TIDAK dilaporkan menunggak lebih besar dari kewajiban riilnya.');
$step('8', 'Pengurus', 'Bandingkan Σ uang seumur pinjaman antara mode Titipan dan Tutup Sekalian.', 'IDENTIK. Fitur ini keringanan arus kas, bukan potongan.');

$section('BAGIAN 9 — SWP & Tabungan Berjangka sebagai simpanan');
$step('9.1', 'Pengurus', 'Simpanan → Saldo Anggota → buka salah satu anggota T1–T6. Lihat kartu saldo.', 'Ada kartu SWP dan Tab. Berjangka, bukan hanya Pokok/Wajib/Sukarela.');
$step('9.1', 'Pengurus', 'Cek nilai SWP.', '120.000 untuk semua pinjaman uji.');
$step('9.1', 'Pengurus', 'Cek Tab. Berjangka per anggota.', 'T1–T4: 24.000 · T5: 132.000 (11 angsuran; baris pelunasan TIDAK menambah — kalau 144.000 berarti salah) · T6: 48.000.');
$step('9.1', 'Pengurus', 'Buka buku mutasi anggota.', 'Barisnya berbunyi "Potongan SWP saat pencairan pinjaman" dan "Tabungan Berjangka dari angsuran" — bukan "Setoran".');
$step('9.1', 'Pengurus', 'Cek Total saldo anggota.', 'TERMASUK SWP & Tab Berjangka. Dulu tidak — anggota melihat angka lebih kecil dari simpanan yang benar-benar ia punya.');
$step('9.2', 'Pengurus', 'Simpanan → Pencairan. Cari draft pengembalian atas nama Hesti (T5, sudah Lunas).', 'TIDAK ADA draft pengembalian SWP maupun Tab. Berjangka.');
$step('9.2', 'Pengurus', 'Cek saldo SWP dan Tab. Berjangka Hesti.', 'Tetap 120.000 dan 132.000. Lunas tidak memindahkan apa pun.');
$step('9.3', 'Pengurus', 'Simpanan → Pencairan → Buat. Pilih anggota Hesti.', 'Baris sumber dana memuat SWP dan Tabungan Berjangka dengan saldo di atas.');
$step('9.3', 'Pengurus', 'Ajukan penarikan SWP 120.000, lalu ACC dan Cairkan.', 'Saldo SWP jadi 0; Tab. Berjangka tak tersentuh. (CATATAN: aturan koperasi sebenarnya SWP hanya kembali saat anggota KELUAR — belum ditegakkan, jangan dilaporkan sebagai bug.)');
$step('9.4a', 'Pengurus', 'Pengaturan → pastikan Bulan Pembagian SHU = "Belum ditetapkan". Lalu cairkan Tab. Berjangka anggota mana pun untuk PERTAMA kali.', 'Berhasil — belum pernah cair, tak ada yang menghalangi.');
$step('9.4a', 'Pengurus', 'Ajukan lagi, ACC, lalu Cairkan.', 'DITOLAK: "…dikembalikan satu kali dalam setahun. Pencairan terakhir …, jadi pencairan berikutnya baru boleh mulai …"');
$step('9.4a', 'Pengurus', 'Cek status pencairan kedua.', 'Masih acc, tidak berubah jadi cair.');
$step('9.4a', 'Pengurus', 'PALING PENTING — coba lagi sebagai Pengurus.', 'TETAP DITOLAK. Kalau Pengurus lolos, aturannya tak mengikat siapa pun — hanya Pengurus yang bisa mencairkan.', true);
$step('9.4a', 'Pengurus', 'Cairkan Sukarela dua kali berturut-turut.', 'BOLEH — jadwal ini hanya berlaku untuk Tabungan Berjangka.');
$step('9.4a', 'Pengurus', 'Batalkan pencairan Tab. Berjangka, lalu ajukan lagi.', 'BOLEH — uangnya kembali, jadi jadwalnya ikut terbuka lagi.');
$step('9.4b', 'Pengurus', 'Pengaturan → set Bulan Pembagian SHU ke BULAN INI. Lalu cairkan Tab. Berjangka.', 'Berhasil.');
$step('9.4b', 'Pengurus', 'Ajukan lagi di bulan yang sama, ACC & Cairkan.', 'DITOLAK: "…sudah dicairkan tahun ini …". Jendela sebulan tak boleh dipakai dua kali.');
$step('9.4b', 'Pengurus', 'Set Bulan SHU ke BULAN LAIN, lalu cairkan Tab. Berjangka anggota yang belum pernah cair.', 'DITOLAK: "…hanya dapat dicairkan pada bulan pembagian SHU (…). Jendela berikutnya: …"');
$step('9.4b', 'Pengurus', 'Anggota yang pencairan terakhirnya 13 BULAN LALU, sekarang bukan bulan SHU.', 'TETAP DITOLAK — inilah bedanya dengan aturan lama, yang akan meloloskannya. "Sekali setahun" jadi benar-benar "bersamaan SHU".', true);
$step('9.4b', 'super_admin', 'Cairkan di luar jendela sebagai super_admin.', 'BERHASIL, dan di Log Aktivitas muncul "Pencairan Tabungan Berjangka di luar jadwal" dengan alasan "di luar bulan pembagian SHU".');
$step('9.4c', 'Pengurus', 'Ubah status anggota jadi Keluar. Set bulan SHU ke bulan lain. Cairkan Tab. Berjangka-nya.', 'BERHASIL — anggota Keluar/Meninggal dikecualikan dari jadwal.');
$step('9.4c', 'Pengurus', 'Cek Log Aktivitas setelah itu.', 'TIDAK ADA jejak "Pencairan di Luar Jadwal" — ia memang tidak melanggar, bukan menembus.');
$step('9.4c', 'Pengurus', 'Kembalikan status anggota jadi Aktif, lalu coba lagi di luar bulan SHU.', 'DITOLAK. Pengecualiannya sempit — hanya untuk yang benar-benar keluar.');
$step('9.5', 'Petugas', 'Batalkan satu baris angsuran mana pun (mis. T1 angsuran #2).', 'Saldo Tab. Berjangka anggota turun 12.000.');
$step('9.5', 'Pengurus', 'Buka buku mutasi anggota.', 'Muncul baris "Pembatalan Tabungan Berjangka angsuran". Baris aslinya TETAP ADA, dinetralkan, tidak dihapus.');
$step('9.5', 'Petugas', 'Batalkan sebuah pinjaman yang belum punya angsuran (salah input).', 'Saldo SWP anggota turun 120.000, dengan baris "Pembatalan potongan SWP".');
$step('9.6', 'Pengurus', 'Laporan → Laporan Simpanan. Rentang yang memuat pembayaran angsuran anggota uji, basis Periode Potong Gaji.', 'Total TIDAK memuat Tabungan Berjangka. Bandingkan dengan Laporan Angsuran — angka yang sama tak boleh muncul di dua laporan.');
$step('9.6', 'Pengurus', 'Buka filter jenis simpanan di laporan.', 'SWP & Tab Berjangka TIDAK ditawarkan — memang bukan bagian laporan ini.');
$step('9.6', 'Pengurus', 'Simpanan → Setoran. Filter per jenis.', 'Baris SWP & Tab Berjangka ADA dan bisa difilter, dengan label "SWP (Simpanan Wajib Pinjaman)" dan "Tabungan Berjangka" — bukan tulisan mentah.');

$section('BAGIAN 10 — Pengaman hasil security review');
$step('10.1', 'Petugas', 'Simpanan → Setoran → Buat. Lihat daftar jenis simpanan.', 'Lima jenis saja: Pokok, Wajib, Sukarela, Hari Raya, Wajib Belanja. SWP & Tab Berjangka TIDAK ADA.');
$step('10.2', 'Petugas', 'Simpanan → Setoran → buka baris bertipe SWP.', 'Tombol Reversal TIDAK MUNCUL.');
$step('10.2', 'Pengurus', 'Buka baris yang sama.', 'Sama — tidak muncul juga.');
$step('10.2', 'Petugas', 'Batalkan pinjamannya (jalur yang benar).', 'Setoran SWP ikut terbalik, saldo turun.');
$step('10.2', 'Petugas', 'Batalkan angsuran (jalur yang benar).', 'Setoran Tab Berjangka bulan itu ikut terbalik.');
$step('10.3', 'Pengurus', 'Cairkan SWP anggota sampai saldonya 0, lalu batalkan pinjamannya.', 'DITOLAK: "SWP-nya … sudah ditarik anggota … Batalkan dulu pencairan SWP tersebut, baru pinjamannya."', true);
$step('10.3', 'Pengurus', 'Cek saldo SWP anggota.', 'Tetap 0, TIDAK MINUS.', true);
$step('10.3', 'Pengurus', 'Batalkan pencairan SWP-nya dulu, lalu batalkan pinjamannya.', 'Sekarang berhasil.');
$step('10.4', 'Pengurus', 'Laporan → Rekonsiliasi Pinjaman (setelah seluruh skenario di atas dijalankan).', '"Seluruhnya cocok" — halaman kosong adalah hasil yang benar.');
$step('10.4', 'Pengurus', 'Cek kolomnya.', 'SWP dan Tab Berjangka, masing-masing Tercatat / Seharusnya / Selisih.');
$step('10.4', 'Petugas', 'Buka Rekonsiliasi Pinjaman.', '403.');
$step('10.5', 'Pengurus', 'Sistem → Log Aktivitas → buka filter Aksi.', 'Memuat "Pencairan di Luar Jadwal", "Pembatalan Ditolak", "Pembayaran Angsuran", "Batch Angsuran Potong Gaji", "Koreksi / Pembatalan".');
$step('10.5', 'Pengurus', 'Pilih filter "Pencairan di Luar Jadwal".', 'Menampilkan bypass yang dilakukan di skenario 9.4.');
$step('10.6', 'super_admin', 'Sistem → Pengguna → edit seorang Pengurus. Cari bagian "Izin Khusus".', 'ADA, memuat "Tembus Jadwal Tahunan Tabungan Berjangka".');
$step('10.6', 'super_admin', 'Cek apakah izin bawaan peran ikut ditawarkan.', 'TIDAK — hanya izin yang tak dimiliki peran mana pun.');
$step('10.6', 'super_admin', 'Centang izin itu, simpan, lalu ulangi skenario 9.4 sebagai Pengurus tersebut.', 'Sekarang LOLOS, dan tercatat sebagai pencairan di luar jadwal.');

$r = 2;
$no = 0;
foreach ($rows as $item) {
    if ($item[0] === '__SECTION__') {
        $t->setCellValue("A{$r}", $item[1]);
        $t->mergeCells("A{$r}:H{$r}");
        $t->getStyle("A{$r}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB($HEAD);
        $t->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($BAND);
        $t->getRowDimension($r)->setRowHeight(24);
        $r++;

        continue;
    }

    [$code, $role, $langkah, $harapan, , $wajib] = $item;
    $no++;

    $t->setCellValue("A{$r}", $no);
    $t->setCellValue("B{$r}", $code.($wajib ? ' ⚑' : ''));
    $t->setCellValue("C{$r}", $role);
    $t->setCellValue("D{$r}", $langkah);
    $t->setCellValue("E{$r}", $harapan);

    if ($wajib) {
        $t->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($WAJIB);
        $t->getStyle("B{$r}")->getFont()->setBold(true);
    }

    $dv = $t->getCell("G{$r}")->getDataValidation();
    $dv->setType(DataValidation::TYPE_LIST)
        ->setErrorStyle(DataValidation::STYLE_INFORMATION)
        ->setAllowBlank(true)
        ->setShowDropDown(true)
        ->setFormula1('"OK,GAGAL,TERLEWAT,N/A"');

    $r++;
}

$last = $r - 1;

$t->getStyle('A1:H1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$t->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($HEAD);
$t->getStyle("A1:H{$last}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
$t->getStyle("A1:H{$last}")->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($LINE);
$t->getStyle("A2:A{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$t->getStyle("G2:G{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$t->getStyle("F2:H{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFDF5');

foreach (['A' => 5, 'B' => 8, 'C' => 11, 'D' => 46, 'E' => 62, 'F' => 30, 'G' => 11, 'H' => 30] as $col => $w) {
    $t->getColumnDimension($col)->setWidth($w);
}
$t->freezePane('A2');
$t->setAutoFilter("A1:H{$last}");

$book->setActiveSheetIndex(0);

$out = $argv[1] ?? 'UAT-Titipan-Pokok.xlsx';
(new Xlsx($book))->save($out);

echo "Tersimpan: {$out}\n";
echo 'Skenario: '.$no."\n";

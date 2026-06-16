<?php

/*
|--------------------------------------------------------------------------
| Pesan Validasi (Bahasa Indonesia)
|--------------------------------------------------------------------------
|
| Dipakai app-wide oleh semua form (Filament & request biasa). Cukup atur di
| sini sekali; setiap field otomatis memakai pesan ini. Untuk pesan khusus
| per-field gunakan `->validationMessages([...])` di field tersebut.
|
*/

return [
    'accepted' => ':attribute harus diterima.',
    'active_url' => ':attribute bukan URL yang valid.',
    'after' => ':attribute harus berisi tanggal setelah :date.',
    'after_or_equal' => ':attribute harus berisi tanggal setelah atau sama dengan :date.',
    'alpha' => ':attribute hanya boleh berisi huruf.',
    'alpha_dash' => ':attribute hanya boleh berisi huruf, angka, strip, dan garis bawah.',
    'alpha_num' => ':attribute hanya boleh berisi huruf dan angka.',
    'array' => ':attribute harus berupa array.',
    'before' => ':attribute harus berisi tanggal sebelum :date.',
    'before_or_equal' => ':attribute harus berisi tanggal sebelum atau sama dengan :date.',
    'between' => [
        'numeric' => ':attribute harus bernilai antara :min sampai :max.',
        'file' => ':attribute harus berukuran antara :min sampai :max kilobita.',
        'string' => ':attribute harus berisi antara :min sampai :max karakter.',
        'array' => ':attribute harus memiliki antara :min sampai :max item.',
    ],
    'boolean' => 'Kolom :attribute harus bernilai benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'date' => ':attribute bukan tanggal yang valid.',
    'date_equals' => ':attribute harus berisi tanggal yang sama dengan :date.',
    'date_format' => ':attribute tidak cocok dengan format :format.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus terdiri dari :digits angka.',
    'digits_between' => ':attribute harus terdiri dari :min sampai :max angka.',
    'email' => ':attribute harus berupa alamat surel yang valid.',
    'ends_with' => ':attribute harus diakhiri salah satu dari: :values.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'file' => ':attribute harus berupa berkas.',
    'filled' => 'Kolom :attribute wajib diisi.',
    'gt' => [
        'numeric' => ':attribute harus lebih besar dari :value.',
        'file' => ':attribute harus lebih besar dari :value kilobita.',
        'string' => ':attribute harus lebih dari :value karakter.',
        'array' => ':attribute harus memiliki lebih dari :value item.',
    ],
    'gte' => [
        'numeric' => ':attribute harus lebih besar dari atau sama dengan :value.',
        'file' => ':attribute harus lebih besar dari atau sama dengan :value kilobita.',
        'string' => ':attribute harus lebih dari atau sama dengan :value karakter.',
        'array' => ':attribute harus memiliki :value item atau lebih.',
    ],
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak valid.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'ip' => ':attribute harus berupa alamat IP yang valid.',
    'json' => ':attribute harus berupa string JSON yang valid.',
    'lt' => [
        'numeric' => ':attribute harus kurang dari :value.',
        'file' => ':attribute harus kurang dari :value kilobita.',
        'string' => ':attribute harus kurang dari :value karakter.',
        'array' => ':attribute harus memiliki kurang dari :value item.',
    ],
    'lte' => [
        'numeric' => ':attribute harus kurang dari atau sama dengan :value.',
        'file' => ':attribute harus kurang dari atau sama dengan :value kilobita.',
        'string' => ':attribute harus kurang dari atau sama dengan :value karakter.',
        'array' => ':attribute tidak boleh memiliki lebih dari :value item.',
    ],
    'max' => [
        'numeric' => ':attribute tidak boleh lebih besar dari :max.',
        'file' => ':attribute tidak boleh lebih besar dari :max kilobita.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
        'array' => ':attribute tidak boleh memiliki lebih dari :max item.',
    ],
    'mimes' => ':attribute harus berupa berkas berjenis: :values.',
    'mimetypes' => ':attribute harus berupa berkas berjenis: :values.',
    'min' => [
        'numeric' => ':attribute minimal bernilai :min.',
        'file' => ':attribute minimal berukuran :min kilobita.',
        'string' => ':attribute minimal berisi :min karakter.',
        'array' => ':attribute minimal memiliki :min item.',
    ],
    'not_in' => ':attribute yang dipilih tidak valid.',
    'numeric' => ':attribute harus berupa angka.',
    'present' => 'Kolom :attribute harus ada.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'required_if' => ':attribute wajib diisi bila :other bernilai :value.',
    'required_unless' => ':attribute wajib diisi kecuali :other bernilai :values.',
    'required_with' => ':attribute wajib diisi bila terdapat :values.',
    'required_without' => ':attribute wajib diisi bila tidak terdapat :values.',
    'same' => ':attribute dan :other harus sama.',
    'size' => [
        'numeric' => ':attribute harus bernilai :size.',
        'file' => ':attribute harus berukuran :size kilobita.',
        'string' => ':attribute harus berisi :size karakter.',
        'array' => ':attribute harus mengandung :size item.',
    ],
    'starts_with' => ':attribute harus diawali salah satu dari: :values.',
    'string' => ':attribute harus berupa string.',
    'unique' => ':attribute sudah digunakan.',
    'uploaded' => ':attribute gagal diunggah.',
    'url' => 'Format :attribute tidak valid.',

    /*
    |--------------------------------------------------------------------------
    | Pesan Khusus per Atribut
    |--------------------------------------------------------------------------
    */
    'custom' => [
        'nik' => [
            'digits' => 'NIK harus terdiri dari tepat 16 angka.',
            'unique' => 'NIK ini sudah terdaftar pada anggota lain.',
        ],
        'nip' => [
            'required' => 'NIP wajib diisi untuk pegawai ASN.',
        ],
        'exit_date' => [
            'required' => 'Tanggal keluar wajib diisi bila status anggota Keluar atau Meninggal.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nama Atribut Manusiawi
    |--------------------------------------------------------------------------
    */
    'attributes' => [
        'member_number' => 'Nomor anggota',
        'full_name' => 'Nama lengkap',
        'nik' => 'NIK',
        'nip' => 'NIP',
        'birth_place' => 'Tempat lahir',
        'birth_date' => 'Tanggal lahir',
        'gender' => 'Jenis kelamin',
        'agency_id' => 'OPD / Instansi',
        'grade_id' => 'Golongan',
        'mandatory_savings_amount' => 'Simpanan wajib',
        'position' => 'Jabatan',
        'employment_status' => 'Status kepegawaian',
        'payroll_account_number' => 'Nomor rekening gaji',
        'bank_name' => 'Nama bank',
        'address' => 'Alamat',
        'phone_number' => 'Nomor HP',
        'join_date' => 'Tanggal bergabung',
        'exit_date' => 'Tanggal keluar',
        'heir_name' => 'Nama ahli waris',
        'heir_relationship' => 'Hubungan ahli waris',
        'heir_phone_number' => 'Nomor HP ahli waris',
        'status' => 'Status',
        'agency_code' => 'Kode OPD',
        'agency_name' => 'Nama OPD',
        'code' => 'Kode',
        'name' => 'Nama',
    ],
];

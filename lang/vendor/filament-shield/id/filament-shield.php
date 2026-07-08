<?php

// Override sebagian translation filament-shield (Laravel merge dgn file vendor).
// Pindahkan resource Peran dari grup default "Pelindung" ke grup "Sistem"
// agar konsisten dengan navigasi panel admin, dan pertegas labelnya.
return [
    'nav.group' => 'Sistem',
    'nav.role.label' => 'Peran & Izin',
];

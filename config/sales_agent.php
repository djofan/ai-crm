<?php

/*
|--------------------------------------------------------------------------
| AI Sales Agent
|--------------------------------------------------------------------------
|
| Konteks bisnis yang dikirim ke AI. Agent HANYA boleh menyebut produk, harga,
| dan kebijakan yang tertulis di sini. Jika `products` dikosongkan, agent akan
| mengumpulkan kebutuhan pelanggan dan menyerahkan ke tim (tanpa menyebut harga).
|
| Contoh isi `products`:
|   ['name' => 'Paket Toko Online', 'price' => 'Mulai Rp X / bulan', 'description' => '...'],
|
*/

return [

    'agent_name' => env('SALES_AGENT_NAME') ?: 'Asisten',

    'business' => [
        'name' => env('SALES_AGENT_BUSINESS_NAME') ?: env('APP_NAME', 'Bisnis kami'),
        'description' => env('SALES_AGENT_BUSINESS_DESCRIPTION') ?: '',
        'timezone' => env('SALES_AGENT_TIMEZONE') ?: 'Asia/Jakarta',
        'language' => 'Bahasa Indonesia',
        'tone' => 'ramah, sopan, singkat, dan profesional',
    ],

    // Daftar produk/paket yang boleh disebut agent. Kosong = agent tidak boleh menyebut produk/harga.
    'products' => [],

    // Teks bebas: jam operasional, metode pembayaran, kebijakan, dst.
    'policies' => env('SALES_AGENT_POLICIES') ?: '',

    // Jumlah pesan terakhir yang dikirim ke AI sebagai konteks percakapan.
    'history_limit' => 20,

    // Batas panjang satu balasan agent (karakter).
    'max_message_length' => 1000,

];

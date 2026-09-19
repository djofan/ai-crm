<?php

namespace App\Enums;

enum SenderType: string
{
    /** Pesan dari customer. */
    case Customer = 'customer';

    /** Pesan yang dibuat oleh AI Sales Agent. */
    case Agent = 'agent';

    /** Pesan yang dibuat manual oleh staf/admin. */
    case Human = 'human';

    /** Catatan sistem (bukan pesan yang dikirim ke customer). */
    case System = 'system';
}

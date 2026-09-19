<?php

namespace App\Enums;

enum MessageStatus: string
{
    /** Pesan masuk dari customer, sudah tersimpan. */
    case Received = 'received';

    /** Pesan keluar, belum dikirim ke provider. */
    case Pending = 'pending';

    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
}

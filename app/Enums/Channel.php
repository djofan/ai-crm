<?php

namespace App\Enums;

enum Channel: string
{
    case WhatsApp = 'whatsapp';
    case Instagram = 'instagram';
    case Telegram = 'telegram';
    case Email = 'email';
}

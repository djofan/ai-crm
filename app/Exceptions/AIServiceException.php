<?php

namespace App\Exceptions;

use RuntimeException;

class AIServiceException extends RuntimeException
{
    public static function notConfigured(string $setting): self
    {
        return new self("AI belum dikonfigurasi: {$setting} kosong.");
    }

    public static function requestFailed(int $status, ?string $type = null, ?string $code = null): self
    {
        $detail = trim(($type ?? '').' '.($code ?? ''));

        return new self("Permintaan ke OpenAI gagal (HTTP {$status})".($detail !== '' ? ": {$detail}" : '').'.');
    }

    public static function connectionFailed(string $reason): self
    {
        return new self("Tidak dapat terhubung ke OpenAI: {$reason}");
    }

    public static function malformedResponse(string $reason): self
    {
        return new self("Respons OpenAI tidak valid: {$reason}");
    }
}

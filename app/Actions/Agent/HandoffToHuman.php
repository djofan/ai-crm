<?php

namespace App\Actions\Agent;

use App\Models\Conversation;

class HandoffToHuman
{
    public function execute(Conversation $conversation, string $reason): Conversation
    {
        $metadata = $conversation->metadata ?? [];
        $metadata['handoff'] = [
            'reason' => trim($reason),
            'at' => now()->toIso8601String(),
        ];

        $conversation->ai_enabled = false;
        $conversation->metadata = $metadata;
        $conversation->save();

        return $conversation;
    }
}

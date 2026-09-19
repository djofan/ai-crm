<?php

namespace App\Actions\Agent;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Menyimpan balasan agent sebagai pesan keluar.
 *
 * Status awalnya `pending`: pesan sudah dicatat tetapi BELUM dikirim ke customer.
 * Pengiriman lewat channel (WhatsApp, dst) ditambahkan di Step 3 melalui MessagingService.
 */
class SendReply
{
    public function execute(Conversation $conversation, string $text, ?AgentRun $run = null): Message
    {
        return $conversation->messages()->create([
            'channel' => $conversation->channel,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Agent,
            'type' => MessageType::Text,
            'content' => trim($text),
            'status' => MessageStatus::Pending,
            'metadata' => $run ? ['agent_run_id' => $run->id] : null,
        ]);
    }
}

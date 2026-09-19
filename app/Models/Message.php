<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\SenderType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id', 'channel', 'direction', 'sender_type', 'type', 'content',
    'external_message_id', 'status', 'error', 'sent_at', 'metadata',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Channel pesan selalu mengikuti conversation-nya jika tidak diisi eksplisit.
        static::creating(function (Message $message) {
            $message->channel ??= $message->conversation->channel;
        });

        // Jaga conversations.last_message_at tetap akurat dari semua jalur pembuatan pesan.
        static::created(function (Message $message) {
            $message->conversation()->update(['last_message_at' => $message->created_at]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'direction' => MessageDirection::class,
            'sender_type' => SenderType::class,
            'type' => MessageType::class,
            'status' => MessageStatus::class,
            'sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::Inbound;
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}

<?php

namespace App\Models;

use App\Enums\AgentRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id', 'trigger_message_id', 'model', 'status', 'actions',
    'usage', 'error', 'started_at', 'finished_at',
])]
class AgentRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'actions' => 'array',
            'usage' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }
}

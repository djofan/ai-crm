<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\ConversationStatus;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id', 'lead_id', 'channel', 'status', 'ai_enabled',
    'external_thread_id', 'last_message_at', 'metadata',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => ConversationStatus::class,
            'ai_enabled' => 'boolean',
            'last_message_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ConversationStatus::Open->value);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Pesan terbaru dalam urutan kronologis (terlama -> terbaru), siap dipakai sebagai context AI.
     *
     * @return Collection<int, Message>
     */
    public function recentMessages(int $limit = 20): Collection
    {
        return $this->messages()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}

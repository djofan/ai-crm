<?php

namespace App\Services\AI\DTO;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Message;

final readonly class ToolContext
{
    public function __construct(
        public Conversation $conversation,
        public Customer $customer,
        public ?Lead $lead,
        public ?Message $triggerMessage,
        public ?AgentRun $run,
    ) {}
}

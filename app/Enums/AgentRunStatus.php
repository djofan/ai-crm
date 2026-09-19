<?php

namespace App\Enums;

enum AgentRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}

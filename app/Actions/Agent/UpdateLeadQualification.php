<?php

namespace App\Actions\Agent;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Support\Str;

class UpdateLeadQualification
{
    private const MAX_NOTES_LENGTH = 5000;

    /**
     * @param  array{status?: string, interest?: string, estimated_value?: int|float|string, notes?: string}  $data
     */
    public function execute(Lead $lead, array $data): Lead
    {
        if (isset($data['status'])) {
            $status = LeadStatus::from($data['status']);
            $lead->status = $status;
            $lead->closed_at = $status === LeadStatus::Lost ? now() : null;
        }

        if (isset($data['interest'])) {
            $lead->interest = $data['interest'];
        }

        if (isset($data['estimated_value'])) {
            $lead->estimated_value = $data['estimated_value'];
        }

        if (isset($data['notes']) && trim($data['notes']) !== '') {
            $entry = '['.now()->format('Y-m-d H:i').'] '.trim($data['notes']);
            $lead->notes = Str::substr(trim(($lead->notes ? $lead->notes."\n" : '').$entry), -self::MAX_NOTES_LENGTH);
        }

        $lead->save();

        return $lead;
    }
}

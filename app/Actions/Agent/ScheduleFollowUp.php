<?php

namespace App\Actions\Agent;

use App\Models\Lead;

/**
 * Menjadwalkan waktu follow-up pada lead.
 *
 * Catatan: ini hanya MENCATAT jadwal (next_follow_up_at). Pengiriman pesan follow-up
 * otomatis belum ada dan butuh scheduler/cron terpisah.
 */
class ScheduleFollowUp
{
    public function execute(Lead $lead, int $hours, ?string $reason = null): Lead
    {
        $lead->next_follow_up_at = now()->addHours($hours);

        if ($reason !== null && trim($reason) !== '') {
            $metadata = $lead->metadata ?? [];
            $metadata['follow_up_reason'] = trim($reason);
            $lead->metadata = $metadata;
        }

        $lead->save();

        return $lead;
    }
}

<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case ProposalSent = 'proposal_sent';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * Status yang berarti lead sudah selesai (tidak perlu di-follow up lagi).
     *
     * @return list<self>
     */
    public static function closed(): array
    {
        return [self::Won, self::Lost];
    }

    public function isOpen(): bool
    {
        return ! in_array($this, self::closed(), true);
    }
}

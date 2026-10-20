<?php

namespace App\Services;

use App\Models\ZenConsultation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ConsultationDeletionService
{
    public function __construct(private readonly PublicConsultationShareService $shareService) {}

    /**
     * Deletes the matching consultations and revokes their public share links.
     *
     * @param  Builder<ZenConsultation>|Relation<ZenConsultation, *, *>  $query
     */
    public function delete(Builder|Relation $query): int
    {
        $deleted = 0;

        $query->chunkById(200, function ($consultations) use (&$deleted): void {
            foreach ($consultations as $consultation) {
                $this->shareService->revoke($consultation->share_token_hash);
                $consultation->delete();
                $deleted++;
            }
        });

        return $deleted;
    }
}

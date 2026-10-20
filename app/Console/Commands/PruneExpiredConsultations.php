<?php

namespace App\Console\Commands;

use App\Models\ZenConsultation;
use App\Services\ConsultationDeletionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('zen:prune-consultations')]
#[Description('Delete saved consultations containing personal information after the configured retention period')]
class PruneExpiredConsultations extends Command
{
    public function handle(ConsultationDeletionService $deletionService): int
    {
        $retentionDays = (int) config('zen.consultation_retention_days');

        if ($retentionDays <= 0) {
            $this->info('Consultation retention is disabled.');

            return self::SUCCESS;
        }

        $deleted = $deletionService->delete(
            ZenConsultation::query()
                ->where('contains_personal_info', true)
                ->where('created_at', '<', now()->subDays($retentionDays)),
        );

        $this->info("Deleted {$deleted} expired consultation(s).");

        return self::SUCCESS;
    }
}

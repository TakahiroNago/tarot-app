<?php

namespace App\Services;

use App\Models\User;
use App\Models\ZenConsultation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class SessionConsultationPersister
{
    public function persist(User $user, Request $request): void
    {
        $sessionConsultations = $request->session()->get('zen_consultations', []);
        if (! is_array($sessionConsultations)) {
            throw new UnexpectedValueException('Session Zen consultations must be an array.');
        }

        $consultations = [];
        foreach ($sessionConsultations as $consultation) {
            if (
                ! is_array($consultation)
                || ! is_string($consultation['concern'] ?? null)
                || ! is_string($consultation['response'] ?? null)
            ) {
                throw new UnexpectedValueException('Session Zen consultation has an invalid format.');
            }

            $hasTopic = array_key_exists('topic', $consultation);
            $hasSummary = array_key_exists('summary', $consultation);
            if ($hasTopic !== $hasSummary) {
                throw new UnexpectedValueException('Session consultation metadata has an invalid format.');
            }

            if (
                $hasTopic
                && (
                    ! is_string($consultation['topic'])
                    || ! in_array($consultation['topic'], ZenConsultation::TOPICS, true)
                    || ! is_string($consultation['summary'])
                    || trim($consultation['summary']) === ''
                    || mb_strlen($consultation['summary']) > ZenConsultation::SUMMARY_MAX_LENGTH
                )
            ) {
                throw new UnexpectedValueException('Session consultation metadata has an invalid format.');
            }

            $persistedConsultation = [
                'concern' => $consultation['concern'],
                'response' => $consultation['response'],
            ];
            if ($hasTopic) {
                $persistedConsultation['topic'] = $consultation['topic'];
                $persistedConsultation['summary'] = $consultation['summary'];
            }

            $consultations[] = $persistedConsultation;
        }

        if ($consultations !== []) {
            DB::transaction(fn () => $user->zenConsultations()->createMany($consultations));
        }

        $request->session()->forget('zen_consultations');
    }
}

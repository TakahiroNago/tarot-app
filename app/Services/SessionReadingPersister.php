<?php

namespace App\Services;

use App\Models\TarotReading;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class SessionReadingPersister
{
    public function persist(User $user, Request $request): void
    {
        $sessionReadings = $request->session()->get('tarot_readings', []);
        if (! is_array($sessionReadings)) {
            throw new UnexpectedValueException('Session tarot readings must be an array.');
        }

        $readings = [];
        foreach ($sessionReadings as $reading) {
            if (
                ! is_array($reading)
                || ! is_string($reading['question'] ?? null)
                || (! is_string($reading['card'] ?? null) && ($reading['card'] ?? null) !== null)
                || ! is_string($reading['interpretation'] ?? null)
            ) {
                throw new UnexpectedValueException('Session tarot reading has an invalid format.');
            }

            $hasCategory = array_key_exists('category', $reading);
            $hasSummary = array_key_exists('summary', $reading);
            if ($hasCategory !== $hasSummary) {
                throw new UnexpectedValueException('Session tarot reading metadata has an invalid format.');
            }

            if (
                $hasCategory
                && (
                    ! is_string($reading['category'])
                    || ! in_array($reading['category'], TarotReading::CATEGORIES, true)
                    || ! is_string($reading['summary'])
                    || trim($reading['summary']) === ''
                    || mb_strlen($reading['summary']) > TarotReading::SUMMARY_MAX_LENGTH
                )
            ) {
                throw new UnexpectedValueException('Session tarot reading metadata has an invalid format.');
            }

            $persistedReading = [
                'question' => $reading['question'],
                'interpretation' => $reading['interpretation'],
            ];
            if (is_string($reading['card'] ?? null)) {
                $persistedReading['card'] = $reading['card'];
            }
            if ($hasCategory) {
                $persistedReading['category'] = $reading['category'];
                $persistedReading['summary'] = $reading['summary'];
            }

            $readings[] = $persistedReading;
        }

        if ($readings !== []) {
            DB::transaction(fn () => $user->tarotReadings()->createMany($readings));
        }

        $request->session()->forget('tarot_readings');
    }
}

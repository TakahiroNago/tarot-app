<?php

namespace App\Services;

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
                || ! is_string($reading['card'] ?? null)
                || ! is_string($reading['interpretation'] ?? null)
            ) {
                throw new UnexpectedValueException('Session tarot reading has an invalid format.');
            }

            $readings[] = [
                'question' => $reading['question'],
                'card' => $reading['card'],
                'interpretation' => $reading['interpretation'],
            ];
        }

        if ($readings !== []) {
            DB::transaction(fn () => $user->tarotReadings()->createMany($readings));
        }

        $request->session()->forget('tarot_readings');
    }
}

<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TarotReading;
use App\Models\User;
use Illuminate\Database\QueryException;
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
        $recommendationProductIds = [];
        foreach ($sessionReadings as $reading) {
            if (
                ! is_array($reading)
                || ! is_string($reading['question'] ?? null)
                || ! is_string($reading['card'] ?? null)
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
                'card' => $reading['card'],
                'interpretation' => $reading['interpretation'],
            ];
            if (array_key_exists('recommended_product_id', $reading)) {
                $recommendationProductId = $reading['recommended_product_id'];
                if ($recommendationProductId !== null && (! is_int($recommendationProductId) || $recommendationProductId < 1)) {
                    throw new UnexpectedValueException('Session tarot reading recommendation has an invalid format.');
                }
                $recommendationProductIds[count($readings)] = $recommendationProductId;
            }
            if ($hasCategory) {
                $persistedReading['category'] = $reading['category'];
                $persistedReading['summary'] = $reading['summary'];
            }

            $readings[] = $persistedReading;
        }

        if ($readings !== []) {
            $savedReadings = DB::transaction(fn () => $user->tarotReadings()->createMany($readings));
            foreach ($savedReadings as $index => $savedReading) {
                if (! array_key_exists($index, $recommendationProductIds)) {
                    continue;
                }

                $productId = $recommendationProductIds[$index];
                try {
                    $productStillExists = $productId !== null && Product::withTrashed()->whereKey($productId)->exists();
                    $savedReading->productRecommendation()->create([
                        'product_id' => $productStillExists ? $productId : null,
                        'selected_at' => now(),
                    ]);
                } catch (QueryException $exception) {
                    report($exception);
                }
            }
        }

        $request->session()->forget('tarot_readings');
    }
}

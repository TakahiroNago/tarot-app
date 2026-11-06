<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TarotReading;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

/**
 * A recommendation is an extra on a reading the customer has already paid a credit for, so a failure
 * here is reported and never reaches the caller, where it could stop the reading or refund the credit.
 */
class ProductRecommendationService
{
    /**
     * A reading is checked once. Having no candidate is recorded too, so the result does not change
     * later, while an error records nothing and the next view checks again.
     */
    public function forReading(TarotReading $reading): ?Product
    {
        try {
            return DB::transaction(function () use ($reading): ?Product {
                $lockedReading = TarotReading::query()->lockForUpdate()->findOrFail($reading->id);
                $existingRecommendation = $lockedReading->productRecommendation()->first();

                if ($existingRecommendation !== null) {
                    return $this->findById($existingRecommendation->product_id);
                }

                $product = $this->eligibleProducts()->inRandomOrder()->first();

                $lockedReading->productRecommendation()->create([
                    'product_id' => $product?->id,
                    'selected_at' => now(),
                ]);

                return $this->findById($product?->id);
            });
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $reading
     */
    public function forSession(array &$reading): ?Product
    {
        if (array_key_exists('recommended_product_id', $reading)) {
            $productId = $reading['recommended_product_id'];
            if ($productId === null) {
                return null;
            }
            if (! is_int($productId) || $productId < 1) {
                report(new UnexpectedValueException('Session product recommendation has an invalid format.'));

                return null;
            }

            return $this->findById($productId);
        }

        try {
            $product = $this->eligibleProducts()->inRandomOrder()->first();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
        $reading['recommended_product_id'] = $product?->id;

        return $product instanceof Product ? $this->findById($product->id) : null;
    }

    public function findById(?int $productId): ?Product
    {
        if ($productId === null) {
            return null;
        }

        try {
            return Product::withTrashed()
                ->with([
                    'images',
                    'variants' => fn (HasMany $query) => $query->where('is_active', true)->orderBy('id'),
                ])
                ->find($productId);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return Builder<Product>
     */
    private function eligibleProducts(): Builder
    {
        return Product::query()
            ->where('status', 'published')
            ->where('reading_recommendable', true)
            ->whereHas('variants', fn (Builder $variants) => $variants
                ->where('is_active', true)
                ->whereHas('inventoryBalance', fn (Builder $balance) => $balance
                    ->whereColumn('on_hand', '>', 'reserved')));
    }
}

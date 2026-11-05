<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
        ]);
        $search = trim($validated['search'] ?? '');

        $products = Product::query()
            ->with(['variants.inventoryBalance', 'images', 'categories'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('variants', function ($query) use ($search): void {
                            $query->where('sku', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'search' => $search,
            'selectedStatus' => $validated['status'] ?? null,
        ]);
    }

    public function create(): View
    {
        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->get();

        return view('admin.products.create', compact('categories'));
    }

    public function store(Request $request, InventoryService $inventoryService): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $storedImages = $this->storeImages(
            $request->file('images', []),
            $validated['image_alt_texts'] ?? [],
        );

        try {
            $product = DB::transaction(function () use ($validated, $storedImages, $actor, $inventoryService): Product {
                $product = Product::query()->create([
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'description' => $validated['description'] ?? null,
                    'status' => $validated['status'],
                    'reading_recommendable' => $validated['reading_recommendable'] ?? false,
                ]);
                $variant = $product->variants()->create([
                    'sku' => $validated['sku'],
                    'label' => $validated['variant_label'] ?? '標準',
                    'price_minor' => $validated['price_minor'],
                    'currency' => 'JPY',
                    'is_active' => true,
                ]);
                InventoryBalance::query()->create([
                    'variant_id' => $variant->id,
                    'on_hand' => 0,
                    'reserved' => 0,
                ]);
                $inventoryService->setOnHand(
                    $variant,
                    (int) $validated['stock'],
                    $actor,
                    $validated['stock_reason'],
                );
                $product->categories()->sync($validated['category_ids'] ?? []);
                $this->persistImages($product, $storedImages);

                return $product;
            });
        } catch (Throwable $exception) {
            $this->deleteStoredImages($storedImages);
            throw $exception;
        }

        return to_route('admin.products.edit', $product)->with('status', '商品を登録しました。');
    }

    public function edit(Product $product): View
    {
        $product->load(['variants.inventoryBalance', 'images', 'categories']);
        $variant = $product->variants->first();
        abort_if($variant === null, 404);

        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.products.edit', compact('product', 'variant', 'categories'));
    }

    public function update(
        Request $request,
        Product $product,
        InventoryService $inventoryService,
    ): RedirectResponse {
        $product->load('variants');
        $variant = $product->variants->first();
        abort_if($variant === null, 404);

        $validated = $request->validate($this->rules($product, $variant));
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $storedImages = $this->storeImages(
            $request->file('images', []),
            $validated['image_alt_texts'] ?? [],
        );

        try {
            DB::transaction(function () use ($validated, $storedImages, $product, $variant, $actor, $inventoryService): void {
                $product->fill([
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'description' => $validated['description'] ?? null,
                    'status' => $validated['status'],
                    'reading_recommendable' => $validated['reading_recommendable'] ?? false,
                ])->save();
                $variant->fill([
                    'sku' => $validated['sku'],
                    'label' => $validated['variant_label'] ?? '標準',
                    'price_minor' => $validated['price_minor'],
                    'currency' => 'JPY',
                ])->save();
                $product->categories()->sync($validated['category_ids'] ?? []);
                $inventoryService->setOnHand(
                    $variant,
                    (int) $validated['stock'],
                    $actor,
                    $validated['stock_reason'],
                );
                $this->persistImages($product, $storedImages);
            });
        } catch (Throwable $exception) {
            $this->deleteStoredImages($storedImages);
            throw $exception;
        }

        return to_route('admin.products.edit', $product)->with('status', '商品を更新しました。');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->forceFill([
            'status' => 'archived',
            'reading_recommendable' => false,
        ])->save();

        return to_route('admin.products.index')->with('status', '商品を販売停止にしました。');
    }

    public function destroyImage(Product $product, ProductImage $image): RedirectResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $disk = Storage::disk($image->storage_disk);
        if ($disk->exists($image->path) && ! $disk->delete($image->path)) {
            throw new RuntimeException('商品画像をストレージから削除できませんでした。');
        }
        $image->delete();

        return to_route('admin.products.edit', $product)->with('status', '商品画像を削除しました。');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Product $product = null, ?ProductVariant $variant = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:160', 'alpha_dash', Rule::unique('ec_products', 'slug')->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'reading_recommendable' => ['sometimes', 'boolean'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('ec_product_variants', 'sku')->ignore($variant?->id)],
            'variant_label' => ['nullable', 'string', 'max:255'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'stock_reason' => ['required', 'string', 'max:500'],
            'category_ids' => ['nullable', 'array', 'max:20'],
            'category_ids.*' => ['integer', 'distinct', 'exists:ec_categories,id'],
            'images' => ['nullable', 'array', 'max:8'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_alt_texts' => ['nullable', 'array', 'max:8'],
            'image_alt_texts.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $images
     * @param  array<int, string|null>  $altTexts
     * @return array<int, array{path: string, alt_text: string|null}>
     */
    private function storeImages(array $images, array $altTexts): array
    {
        $storedImages = [];

        try {
            foreach ($images as $index => $image) {
                $path = $image->storePublicly('ecommerce/products', 'public');
                if (! is_string($path)) {
                    throw new RuntimeException('商品画像をストレージへ保存できませんでした。');
                }

                $storedImages[] = [
                    'path' => $path,
                    'alt_text' => isset($altTexts[$index]) ? trim($altTexts[$index]) : null,
                ];
            }
        } catch (Throwable $exception) {
            $this->deleteStoredImages($storedImages);
            throw $exception;
        }

        return $storedImages;
    }

    /**
     * @param  array<int, array{path: string, alt_text: string|null}>  $storedImages
     */
    private function persistImages(Product $product, array $storedImages): void
    {
        foreach ($storedImages as $image) {
            $product->images()->create([
                'storage_disk' => 'public',
                'path' => $image['path'],
                'alt_text' => $image['alt_text'],
                'sort_order' => $product->images()->count(),
            ]);
        }
    }

    /**
     * @param  array<int, array{path: string, alt_text: string|null}>  $storedImages
     */
    private function deleteStoredImages(array $storedImages): void
    {
        $disk = Storage::disk('public');
        foreach ($storedImages as $image) {
            if ($disk->exists($image['path']) && ! $disk->delete($image['path'])) {
                report(new RuntimeException('商品画像のロールバック時にファイルを削除できませんでした。'));
            }
        }
    }
}

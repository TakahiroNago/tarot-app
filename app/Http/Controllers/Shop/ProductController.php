<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => [
                'nullable',
                'string',
                Rule::exists('ec_categories', 'slug')->where('is_active', true),
            ],
        ]);
        $search = trim($validated['search'] ?? '');

        $products = Product::query()
            ->where('status', 'published')
            ->whereHas('variants', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('inventoryBalance', fn ($query) => $query
                    ->whereColumn('on_hand', '>', 'reserved')))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhereHas('variants', function ($query) use ($search): void {
                            $query->where('sku', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when(isset($validated['category']), function ($query) use ($validated): void {
                $query->whereHas('categories', function ($query) use ($validated): void {
                    $query->where('slug', $validated['category'])
                        ->where('is_active', true);
                });
            })
            ->with([
                'images',
                'categories',
                'variants' => fn ($query) => $query->where('is_active', true)
                    ->with('inventoryBalance'),
            ])
            ->orderByDesc('created_at')
            ->paginate(12)
            ->withQueryString();

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('shop.products.index', [
            'products' => $products,
            'categories' => $categories,
            'search' => $search,
            'selectedCategory' => $validated['category'] ?? null,
        ]);
    }

    public function show(Product $product): View
    {
        abort_unless($product->status === 'published', 404);

        $product->load([
            'images',
            'categories',
            'variants' => fn ($query) => $query->where('is_active', true)
                ->with('inventoryBalance'),
        ]);

        return view('shop.products.show', compact('product'));
    }
}

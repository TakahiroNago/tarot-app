<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.categories.index', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', 'unique:ec_categories,slug'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        Category::query()->create([
            ...$validated,
            'is_active' => true,
        ]);

        return to_route('admin.categories.index')->with('status', 'カテゴリを登録しました。');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required',
                'string',
                'max:120',
                'alpha_dash',
                Rule::unique('ec_categories', 'slug')->ignore($category->id),
            ],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
        ]);

        $category->fill($validated)->save();

        return to_route('admin.categories.index')->with('status', 'カテゴリを更新しました。');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->forceFill(['is_active' => false])->save();

        return to_route('admin.categories.index')->with('status', 'カテゴリを非公開にしました。');
    }
}

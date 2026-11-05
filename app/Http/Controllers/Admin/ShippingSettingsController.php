<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\TaxRate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShippingSettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.shipping.index', [
            'shippingMethods' => ShippingMethod::query()->with('rates')->orderBy('name')->get(),
            'taxRates' => TaxRate::query()->orderBy('country_code')->orderByDesc('active_from')->get(),
        ]);
    }

    public function storeMethod(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_dash', 'unique:ec_shipping_methods,code'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        ShippingMethod::query()->create([...$validated, 'is_active' => true]);

        return to_route('admin.shipping.index')->with('status', '配送方法を登録しました。');
    }

    public function updateMethod(Request $request, ShippingMethod $shippingMethod): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ]);
        $shippingMethod->fill($validated)->save();

        return to_route('admin.shipping.index')->with('status', '配送方法を更新しました。');
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shipping_method_id' => [
                'required',
                'integer',
                Rule::exists('ec_shipping_methods', 'id')->where('is_active', true),
            ],
            'country_code' => ['required', 'string', 'regex:/\A[A-Z]{2}\z/'],
            'fee_minor' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ]);
        $duplicate = ShippingRate::query()
            ->where('shipping_method_id', $validated['shipping_method_id'])
            ->where('country_code', $validated['country_code'])
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'country_code' => '同じ配送方法・国の送料設定は既にあります。既存設定を編集してください。',
            ]);
        }

        ShippingRate::query()->create([
            ...$validated,
            'currency' => 'JPY',
            'is_active' => true,
        ]);

        return to_route('admin.shipping.index')->with('status', '国別送料を登録しました。');
    }

    public function updateRate(Request $request, ShippingRate $shippingRate): RedirectResponse
    {
        $validated = $request->validate([
            'country_code' => [
                'required',
                'string',
                'regex:/\A[A-Z]{2}\z/',
                Rule::unique('ec_shipping_rates', 'country_code')
                    ->where('shipping_method_id', $shippingRate->shipping_method_id)
                    ->ignore($shippingRate->id),
            ],
            'fee_minor' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['required', 'boolean'],
        ]);
        $shippingRate->fill($validated)->save();

        return to_route('admin.shipping.index')->with('status', '国別送料を更新しました。');
    }

    public function storeTaxRate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'regex:/\A[A-Z]{2}\z/'],
            'tax_basis_points' => ['required', 'integer', 'min:0', 'max:65535'],
            'shipping_taxable' => ['required', 'boolean'],
            'active_from' => [
                'required',
                'date',
                Rule::unique('ec_tax_rates', 'active_from')->where('country_code', $request->input('country_code')),
            ],
            'active_until' => ['nullable', 'date', 'after_or_equal:active_from'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($validated['is_active']) {
            $this->ensureTaxRateDoesNotOverlap(
                $validated['country_code'],
                $validated['active_from'],
                $validated['active_until'] ?? null,
            );
        }

        TaxRate::query()->create($validated);

        return to_route('admin.shipping.index')->with('status', '税ルールを登録しました。');
    }

    public function updateTaxRate(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $validated = $request->validate([
            'tax_basis_points' => ['required', 'integer', 'min:0', 'max:65535'],
            'shipping_taxable' => ['required', 'boolean'],
            'active_from' => [
                'required',
                'date',
                Rule::unique('ec_tax_rates', 'active_from')
                    ->where('country_code', $taxRate->country_code)
                    ->ignore($taxRate->id),
            ],
            'active_until' => ['nullable', 'date', 'after_or_equal:active_from'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($validated['is_active']) {
            $this->ensureTaxRateDoesNotOverlap(
                $taxRate->country_code,
                $validated['active_from'],
                $validated['active_until'] ?? null,
                $taxRate->id,
            );
        }

        $taxRate->fill($validated)->save();

        return to_route('admin.shipping.index')->with('status', '税ルールを更新しました。');
    }

    private function ensureTaxRateDoesNotOverlap(
        string $countryCode,
        string $activeFrom,
        ?string $activeUntil,
        ?int $ignoreId = null,
    ): void {
        $overlappingRates = TaxRate::query()
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->where(function ($query) use ($activeFrom): void {
                $query->whereNull('active_until')->orWhereDate('active_until', '>=', $activeFrom);
            })
            ->when($activeUntil !== null, fn ($query) => $query->whereDate('active_from', '<=', $activeUntil))
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId));

        if ($overlappingRates->exists()) {
            throw ValidationException::withMessages([
                'active_from' => '同じ国で有効期間が重なる税ルールは登録できません。',
            ]);
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'country_code', 'tax_basis_points', 'shipping_taxable',
    'active_from', 'active_until', 'is_active',
])]
class TaxRate extends Model
{
    protected $table = 'ec_tax_rates';

    protected function casts(): array
    {
        return [
            'tax_basis_points' => 'integer',
            'shipping_taxable' => 'boolean',
            'active_from' => 'date',
            'active_until' => 'date',
            'is_active' => 'boolean',
        ];
    }
}

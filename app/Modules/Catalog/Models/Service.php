<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Kernel\Dates\Frequency;
use App\Kernel\Money\Money;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Natura;
use App\Modules\Catalog\Data\ServiceData;
use App\Modules\Catalog\Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property int $unit_price_cents
 * @property string $currency
 * @property int $vat_basis_points
 * @property ?Natura $natura
 * @property ?Frequency $cadence
 * @property bool $is_active
 * @property int $sort
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected $table = 'catalog_services';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'vat_basis_points' => 'integer',
            'natura' => Natura::class,
            'cadence' => Frequency::class,
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function toData(): ServiceData
    {
        return new ServiceData(
            id: $this->id,
            name: $this->name,
            description: $this->description,
            unitPrice: new Money($this->unit_price_cents, $this->currency),
            vatRate: new VatRate($this->vat_basis_points),
            natura: $this->natura,
            cadence: $this->cadence,
            isActive: $this->is_active,
        );
    }
}

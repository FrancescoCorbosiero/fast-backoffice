<?php

declare(strict_types=1);

namespace App\Modules\Domains\Actions;

use App\Modules\Domains\Data\DomainData;
use App\Modules\Domains\Data\DomainInput;
use App\Modules\Domains\Models\Domain;
use Illuminate\Validation\ValidationException;

final readonly class UpdateDomain
{
    public function __construct(private CreateDomain $create) {}

    /**
     * @throws ValidationException
     */
    public function update(int $domainId, DomainInput $input): DomainData
    {
        $this->create->validate($input, $domainId);
        $domain = Domain::query()->findOrFail($domainId);
        $domain->fill(CreateDomain::attributes($input))->save();

        return $domain->toData();
    }
}

<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources;

use AlfaSix\Alfakit\Resources\Api\HttpDataSource;
use Illuminate\Http\Client\PendingRequest;

/**
 * A REST API data source (faked with `Http::fake()` in the tests).
 */
class CarrierSource extends HttpDataSource
{
    protected string $baseUrl = 'https://carriers.test/v1';

    protected string $endpoint = '/carriers';

    protected ?string $totalKey = 'meta.total';

    protected array $errorFields = ['carrier_name' => 'name'];

    protected function request(): PendingRequest
    {
        $request = parent::request()->withToken('secret-token');
        $tenant = $this->getTenant();

        return $tenant === null ? $request : $request->withHeaders(['X-Team' => (string) $tenant->getAttribute('slug')]);
    }

    protected function mapRecord(array $row): array
    {
        return ['id' => $row['id'] ?? null, 'name' => $row['carrier_name'] ?? null, 'code' => $row['code'] ?? null];
    }

    protected function mapPayload(array $data): array
    {
        return ['carrier_name' => $data['name'] ?? null, 'code' => $data['code'] ?? null];
    }
}

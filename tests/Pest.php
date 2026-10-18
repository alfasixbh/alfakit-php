<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

/**
 * Build a root schema with the given components, optionally bound to a model/record.
 *
 * @param  array<AlfaSix\Alfakit\Schemas\Components\Component>  $components
 */
function schema(array $components, ?Illuminate\Database\Eloquent\Model $record = null, ?string $model = null, string $operation = 'create'): AlfaSix\Alfakit\Schemas\Schema
{
    $schema = AlfaSix\Alfakit\Schemas\Schema::make()->components($components)->operation($operation);

    if ($record !== null) {
        $schema->record($record);
    } elseif ($model !== null) {
        $schema->model($model);
    }

    return $schema;
}

/**
 * Fetch an Inertia page as JSON and return its props.
 *
 * @return array<string, mixed>
 */
function inertiaProps(Illuminate\Testing\TestResponse $response): array
{
    $response->assertOk();

    return $response->json('props');
}

/**
 * Perform an Inertia GET.
 */
function inertiaGet(TestCase $test, string $url): Illuminate\Testing\TestResponse
{
    return $test->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
}

/**
 * Perform an Inertia POST (as the frontend action runner does).
 *
 * @param  array<string, mixed>  $data
 */
function inertiaPost(TestCase $test, string $url, array $data): Illuminate\Testing\TestResponse
{
    return $test->post($url, $data, ['X-Inertia' => 'true', 'X-Inertia-Version' => '', 'Accept' => 'application/json']);
}

/** A signed-up user for panel tests (shared so every test file can use it, also when run alone). */
function admin(): AlfaSix\Alfakit\Tests\Fixtures\Models\User
{
    return AlfaSix\Alfakit\Tests\Fixtures\Models\User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
}

/**
 * The `alfakit.navigation` of Inertia props with its `navigationState` (active items and
 * badges, keyed by item position) merged back in, as the renderers do.
 *
 * @param  array<string, mixed>  $alfakit
 * @return array<int, array<string, mixed>>
 */
function navigationWithState(array $alfakit): array
{
    $state = $alfakit['navigationState'] ?? [];
    $apply = static function (array $items, string $prefix) use (&$apply, $state): array {
        foreach ($items as $index => $item) {
            $id = "{$prefix}.{$index}";
            $item['active'] = in_array($id, $state['active'] ?? [], true) ?: null;
            $item = [...$item, ...($state['badges'][$id] ?? [])];

            if (isset($item['children'])) {
                $item['children'] = $apply($item['children'], $id);
            }

            $items[$index] = array_filter($item, static fn (mixed $value): bool => $value !== null);
        }

        return $items;
    };

    return array_map(static fn (int $index, array $group): array => [...$group, 'items' => $apply($group['items'], (string) $index)], array_keys($alfakit['navigation']), $alfakit['navigation']);
}

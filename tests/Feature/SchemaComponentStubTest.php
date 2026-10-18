<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Generators\ClassGenerator;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

it('generates a working schema component class from the stub', function (): void {
    $contents = app(ClassGenerator::class)->generate('components/schema-component', [
        'namespace' => 'App\\AlfakitStubTest',
        'class' => 'ShippingPanel',
        'type' => 'shipping-panel',
    ]);

    expect($contents)->not->toContain('{{');

    $path = sys_get_temp_dir().'/alfakit-schema-component-'.uniqid().'.php';
    file_put_contents($path, $contents);
    require $path;
    unlink($path);

    /** @var class-string<AlfaSix\Alfakit\Schemas\Components\Component> $class */
    $class = 'App\\AlfakitStubTest\\ShippingPanel';
    $component = $class::make([TextInput::make('street')])->heading('Shipping');
    $post = Post::query()->create(['title' => 'Hi', 'slug' => 'hi-'.uniqid()]);

    $serialized = schema([$component], record: $post, operation: 'edit')->fill()->toSchema()[0];

    expect($serialized['type'])->toBe('shipping-panel')
        ->and($serialized['heading'])->toBe('Shipping')
        ->and($serialized['schema'][0]['statePath'])->toBe('street')
        ->and($serialized['context'])->toBe(['operation' => 'edit', 'recordKey' => $post->getKey(), 'record' => null, 'data' => null, 'basePath' => null]);
});

it('ships a frontend stub for each renderer that renders the child schema', function (string $adapter): void {
    $contents = app(ClassGenerator::class)->generate("components/{$adapter}/schema-component", [
        'component' => 'ShippingPanel',
        'phpClass' => 'App\\Alfakit\\Schemas\\Components\\ShippingPanel',
        'key' => 'shipping-panel',
    ]);

    expect($contents)->toContain('SchemaChildren')
        ->and($contents)->toContain('useSchemaComponentContext')
        ->and($contents)->toContain("@alfakit/{$adapter}")
        ->and($contents)->not->toContain('{{ key }}')
        ->and($contents)->not->toContain('{{ component }}');
})->with(['react', 'vue', 'svelte']);

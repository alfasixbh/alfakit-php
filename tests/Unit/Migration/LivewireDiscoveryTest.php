<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\FilamentDiscovery;
use Illuminate\Filesystem\Filesystem;

it('finds Livewire components however their parent class is written (FM-B01)', function (): void {
    $files = new Filesystem;
    $base = sys_get_temp_dir().'/alfakit-livewire-discovery-'.uniqid();
    $files->ensureDirectoryExists($base.'/app/Livewire');

    $components = [
        'FullyQualified' => 'class FullyQualified extends \Livewire\Component {}',
        'Qualified' => 'class Qualified extends Livewire\Component {}',
        'Imported' => "use Livewire\\Component;\n\nclass Imported extends Component {}",
        'Aliased' => "use Livewire\\Component as LivewireComponent;\n\nclass Aliased extends LivewireComponent {}",
        'NotLivewire' => "use App\\Support\\Component;\n\nclass NotLivewire extends Component {}",
        'AliasNotUsed' => "use Livewire\\Component as Base;\n\nclass AliasNotUsed extends Component {}",
    ];

    foreach ($components as $name => $class) {
        $files->put("{$base}/app/Livewire/{$name}.php", "<?php\n\nnamespace App\\Livewire;\n\n{$class}\n");
    }

    try {
        $found = array_map(
            fn (string $path): string => basename($path, '.php'),
            FilamentDiscovery::scan($base, $files)->livewireClasses,
        );

        expect($found)->toEqualCanonicalizing(['Aliased', 'FullyQualified', 'Imported', 'Qualified']);
    } finally {
        $files->deleteDirectory($base);
    }
});

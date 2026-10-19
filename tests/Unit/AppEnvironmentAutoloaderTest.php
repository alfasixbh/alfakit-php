<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;

/*
 * `alfakit:setup` requires packages in a Composer subprocess and then keeps going in the
 * same PHP process (e.g. `alfakit:users` loads the User model that now uses spatie's
 * HasRoles), so the running autoloader must learn the new packages.
 */

it('teaches the running autoloader the packages a composer subprocess installed', function (): void {
    $vendor = sys_get_temp_dir().'/alfakit-vendor-'.uniqid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($vendor.'/composer');
    $files->ensureDirectoryExists($vendor.'/acme/fresh/src');

    $loader = new ClassLoader($vendor);
    $loader->register();

    // What `composer require acme/fresh` leaves behind after the loader was registered.
    file_put_contents($vendor.'/acme/fresh/src/FreshTrait.php', "<?php\nnamespace AcmeFreshPackage;\ntrait FreshTrait {}\n");
    file_put_contents($vendor.'/acme/fresh/src/Mapped.php', "<?php\nnamespace AcmeFreshClassmap;\nclass Mapped {}\n");
    file_put_contents($vendor.'/composer/autoload_psr4.php', "<?php\nreturn ['AcmeFreshPackage\\\\' => [".var_export($vendor.'/acme/fresh/src', true).']];');
    file_put_contents($vendor.'/composer/autoload_classmap.php', "<?php\nreturn ['AcmeFreshClassmap\\\\Mapped' => ".var_export($vendor.'/acme/fresh/src/Mapped.php', true).'];');

    try {
        expect(trait_exists('AcmeFreshPackage\\FreshTrait'))->toBeFalse();

        AppEnvironment::refreshAutoloader($vendor);

        expect(trait_exists('AcmeFreshPackage\\FreshTrait'))->toBeTrue()
            ->and(class_exists('AcmeFreshClassmap\\Mapped'))->toBeTrue();
    } finally {
        $loader->unregister();
        $files->deleteDirectory($vendor);
    }
});

it('does nothing for a vendor directory without a registered loader', function (): void {
    AppEnvironment::refreshAutoloader(sys_get_temp_dir().'/alfakit-missing-'.uniqid());

    expect(true)->toBeTrue();
});

<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\MigrationReport;
use AlfaSix\Alfakit\Migration\TokenRewriter;

it('moves an enum navigation group into getNavigationGroup()', function (): void {
    $code = <<<'CODE'
<?php

namespace App\Filament\Resources;

use App\Enums\NavigationGroup;
use Filament\Resources\Resource;
use UnitEnum;

class ProductResource extends Resource
{
    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Shop;
}
CODE;

    $out = (new TokenRewriter(new MigrationReport(sys_get_temp_dir())))->rewrite('app/Filament/Resources/ProductResource.php', $code);

    expect($out)
        ->toContain("public static function getNavigationGroup(): string|\\UnitEnum|null\n    {\n        return NavigationGroup::Shop;\n    }")
        ->not->toContain('$navigationGroup = NavigationGroup::Shop');

    // The result is valid PHP.
    $file = tempnam(sys_get_temp_dir(), 'nav').'.php';
    file_put_contents($file, $out);
    exec('php -l '.escapeshellarg($file), $lint, $status);
    unlink($file);

    expect($status)->toBe(0);
});

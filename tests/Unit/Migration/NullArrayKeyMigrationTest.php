<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\MigrationReport;
use AlfaSix\Alfakit\Migration\TokenRewriter;

it('turns null array keys into empty strings but keeps null match arms', function (): void {
    $code = <<<'CODE'
<?php

namespace App\Filament\Resources\Orders\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListOrders extends ListRecords
{
    public function getTabs(): array
    {
        return [
            null => Tab::make('All'),
            'new' => Tab::make(),
        ];
    }

    public function legacy(): array
    {
        return array(NULL => 'All', 'a' => fn () => [null => 1]);
    }

    public function label(?string $status): string
    {
        return match ($status) {
            null => 'All',
            default => $status,
        };
    }
}
CODE;

    $out = (new TokenRewriter(new MigrationReport(sys_get_temp_dir())))->rewrite('ListOrders.php', $code);

    expect($out)->toContain("'' => Tab::make('All')")
        ->and($out)->toContain("array('' => 'All', 'a' => fn () => ['' => 1])")
        ->and($out)->toContain("null => 'All',")
        ->and($out)->not->toContain('null => Tab::make');
});

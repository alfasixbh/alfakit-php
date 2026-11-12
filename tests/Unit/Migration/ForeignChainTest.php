<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\MigrationReport;
use AlfaSix\Alfakit\Migration\TokenRewriter;

it('leaves the methods of non-component chains alone', function (): void {
    $code = <<<'CODE'
<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Bus;

class Sync extends Page
{
    public function sync(): void
    {
        Bus::batch([new \App\Jobs\SyncOrders])->name('sync')->dispatch();
        Bus::chain([new \App\Jobs\SyncOrders])->dispatch();
        Order::query()->where('synced', false)->lazy()->each(fn (Order $order) => $order->touch());
        collect([1, 2])->lazy()->each(fn (int $id) => $id);
    }

    protected function fields(): array
    {
        return [
            TextInput::make('title')->reactive(),
            TextInput::make('slug')->lazy(),
        ];
    }
}
CODE;

    $out = (new TokenRewriter(new MigrationReport(sys_get_temp_dir())))->rewrite('app/Filament/Pages/Sync.php', $code);

    expect($out)
        ->toContain("Bus::batch([new \\App\\Jobs\\SyncOrders])->name('sync')->dispatch();")
        ->toContain('Bus::chain([new \\App\\Jobs\\SyncOrders])->dispatch();')
        ->toContain("Order::query()->where('synced', false)->lazy()->each(")
        ->toContain('collect([1, 2])->lazy()->each(')
        // Components still get the Filament renames.
        ->toContain("TextInput::make('title')->live()")
        ->toContain("TextInput::make('slug')->live(onBlur: true)")
        ->and(fn () => token_get_all($out, TOKEN_PARSE))->not->toThrow(ParseError::class);
});

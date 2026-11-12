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

it('keeps a public $data property the class uses itself, and the app\'s own Filament/ paths', function (): void {
    $uses = <<<'CODE'
<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class Preferences extends Page
{
    public ?array $data = [];

    public function save(): void
    {
        $theme = $this->data['theme'] ?? 'light';
        Storage::disk('local')->put('Filament/exports/theme.txt', $theme);
        $dir = app_path('Filament/Resources');
    }
}
CODE;

    $out = (new TokenRewriter(new MigrationReport(sys_get_temp_dir())))->rewrite('app/Filament/Pages/Preferences.php', $uses);

    expect($out)
        ->toContain('public ?array $data = [];')
        ->toContain("put('Filament/exports/theme.txt'")
        ->toContain("app_path('Alfakit/Resources')");

    // Only the Livewire form state: the property goes, as before.
    $unused = str_replace(["        \$theme = \$this->data['theme'] ?? 'light';\n", ', $theme)'], ['', ", 'x')"], $uses);
    $out = (new TokenRewriter(new MigrationReport(sys_get_temp_dir())))->rewrite('app/Filament/Pages/Preferences.php', $unused);

    expect($out)->not->toContain('$data');
});

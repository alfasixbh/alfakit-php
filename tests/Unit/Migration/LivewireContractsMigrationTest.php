<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\MigrationReport;
use AlfaSix\Alfakit\Migration\TokenRewriter;

it('removes the Livewire form contracts from custom pages, which have forms built in', function (): void {
    $code = <<<'CODE'
<?php

namespace App\Filament\Pages;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Stringable;

class Settings extends Page implements HasForms, HasActions, Stringable
{
    use InteractsWithForms, InteractsWithActions;

    public ?array $data = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')])->statePath('data');
    }

    public function __toString(): string
    {
        return 'settings';
    }
}
CODE;

    $report = new MigrationReport(sys_get_temp_dir());
    $out = (new TokenRewriter($report))->rewrite('app/Filament/Pages/Settings.php', $code);

    expect($out)
        ->toContain("class Settings extends Page implements Stringable\n{")
        ->not->toContain('HasForms')
        ->not->toContain('InteractsWith')
        ->not->toContain('HasActions')
        ->not->toContain('TODO(alfakit-migration)')
        ->and(fn () => token_get_all($out, TOKEN_PARSE))->not->toThrow(ParseError::class)
        ->and($report->manualCount())->toBe(0);

    // Without other interfaces the `implements` goes too.
    $single = (new TokenRewriter(new MigrationReport(sys_get_temp_dir())))->rewrite('Settings.php', str_replace([', HasActions, Stringable', 'use InteractsWithForms, InteractsWithActions;'], ['', 'use InteractsWithForms;'], $code));

    expect($single)->toMatch('/class Settings extends Page\n\{\n/')
        ->not->toContain('InteractsWithForms');
});

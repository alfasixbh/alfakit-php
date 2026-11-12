<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\MigrationReport;
use AlfaSix\Alfakit\Migration\TokenRewriter;

function migrateFilamentSource(string $code): string
{
    return (new TokenRewriter(new MigrationReport(sys_get_temp_dir())))->rewrite('app/Filament/Resources/PostResource.php', $code);
}

it('imports a renamed class under an alias when its new name is taken', function (): void {
    $out = migrateFilamentSource(<<<'CODE'
<?php

namespace App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Schema;

class PostResource extends Resource
{
    public static function form(Form $form): Form
    {
        return Schema::hasColumn('posts', 'slug') ? $form : $form;
    }
}
CODE);

    expect($out)->toContain('use AlfaSix\Alfakit\Schemas\Schema as AlfakitSchema;')
        ->toContain('use Illuminate\Support\Facades\Schema;')
        ->toContain('form(AlfakitSchema $form): AlfakitSchema')
        ->toContain("Schema::hasColumn('posts', 'slug')");

    $file = tempnam(sys_get_temp_dir(), 'alfakit').'.php';
    file_put_contents($file, $out);
    exec('php -l '.escapeshellarg($file).' 2>&1', $lint, $status);
    unlink($file);

    expect($status)->toBe(0);
});

it('rewrites table actions called first on the table and sorts them by class, not by name', function (): void {
    $out = migrateFilamentSource(<<<'CODE'
<?php

namespace App\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class PostResource extends Resource
{
    public static function table(Table $table): Table
    {
        return $table->toolbarActions([
            Action::make('openBulkEditor'),
            BulkActionGroup::make([
                DeleteBulkAction::make(),
            ]),
        ]);
    }
}
CODE);

    expect($out)->toContain('->headerActions(')
        ->toContain('->bulkActions(')
        ->not->toContain('BulkActionGroup::make')
        ->and(strpos($out, "Action::make('openBulkEditor')"))->toBeLessThan(strpos($out, '->bulkActions('));
});

it('still cleans trait uses that follow a conflict-resolution block', function (): void {
    $out = (new TokenRewriter(new MigrationReport(sys_get_temp_dir())))->rewrite('app/Filament/Pages/Settings.php', <<<'CODE'
<?php

namespace App\Filament\Pages;

use App\Concerns\A;
use App\Concerns\B;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

class Settings extends Page implements HasForms
{
    use A, B {
        A::boot insteadof B;
    }
    use InteractsWithForms;
}
CODE);

    expect($out)->not->toContain('use InteractsWithForms;');
});

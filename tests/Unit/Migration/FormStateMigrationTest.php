<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\MigrationReport;
use AlfaSix\Alfakit\Migration\TokenRewriter;

it('keeps the validation of a page form getState() call (FM-B02)', function (): void {
    $code = <<<'CODE'
<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Preferences extends Page
{
    public function save(): void
    {
        $data = $this->form->getState();
        $draft = $this->form->getState(false);
    }
}
CODE;

    $report = new MigrationReport(sys_get_temp_dir());
    $out = (new TokenRewriter($report))->rewrite('app/Filament/Pages/Preferences.php', $code);

    // Filament validates in getState() unless told not to; AlfaKit's validate() does.
    expect($out)
        ->toContain('$data = $this->getForm()->validate();')
        ->toContain('$draft = $this->getForm()->getState();')
        ->and(fn () => token_get_all($out, TOKEN_PARSE))->not->toThrow(ParseError::class);
});

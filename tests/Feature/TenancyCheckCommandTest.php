<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\TenancyCheckCommand;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\Check\CurrencyResource;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\Check\InvoiceResource;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\Check\NoteResource;

/*
 * `alfakit:tenancy:check` audits tenancy panels and exits non-zero for CI.
 */

function auditPanel(array $resources): void
{
    alfakit()->registerPanel(
        Panel::make('audit')->path('audit')->tenant(Team::class)->resources($resources),
    );
}

/** @return array<int, array{panel: string, check: string, subject: string, status: string, details: string}> */
function tenancyRows(array $arguments = []): array
{
    $command = app(TenancyCheckCommand::class);
    $command->setLaravel(app());
    $command->run(new Symfony\Component\Console\Input\ArrayInput($arguments), new Symfony\Component\Console\Output\NullOutput);

    return (fn (): array => $this->rows)->call($command);
}

it('passes a panel whose resources are all scoped', function (): void {
    $this->artisan('alfakit:tenancy:check', ['--panel' => 'app', '--strict' => true])
        ->expectsOutputToContain('scoped through team()')
        ->assertExitCode(0);

    expect(collect(tenancyRows(['--panel' => 'app']))->pluck('subject')->all())->toBe(['ProjectResource → Project']);
});

it('reports resources that cannot be scoped, shared models and raw queries', function (): void {
    auditPanel([InvoiceResource::class, NoteResource::class, CurrencyResource::class]);

    $rows = collect(tenancyRows(['--panel' => 'audit']))->keyBy(fn (array $row): string => $row['check'].' '.$row['subject']);

    expect($rows['resource InvoiceResource → Invoice']['status'])->toBe('OK')
        ->and($rows['resource NoteResource → Note']['status'])->toBe('FAIL')
        ->and($rows['resource NoteResource → Note']['details'])->toContain('no team() relationship')
        ->and($rows['resource CurrencyResource → Currency']['status'])->toBe('OK')
        ->and($rows['resource CurrencyResource → Currency']['details'])->toContain('shared')
        // Relationship fields and filters.
        ->and($rows['Select InvoiceResource → team_id']['status'])->toBe('OK')
        ->and($rows['Select InvoiceResource → team_id']['details'])->toContain('tenant model')
        ->and($rows['Select InvoiceResource → currency_id']['status'])->toBe('OK')
        ->and($rows['Select InvoiceResource → currency_id']['details'])->toContain('shared across tenants on purpose')
        ->and($rows['CheckboxList InvoiceResource → tags']['status'])->toBe('WARN')
        ->and($rows['CheckboxList InvoiceResource → tags']['details'])->toContain('Tag is not tenant-scoped')
        ->and($rows['SelectFilter InvoiceResource → currency']['status'])->toBe('OK')
        ->and($rows['AttachAction TagsRelationManager → tags']['status'])->toBe('WARN');

    // Static scan: DB:: is flagged, withoutGlobalScopes([SoftDeletingScope::class]) is not.
    $queries = $rows->filter(fn (array $row): bool => $row['check'] === 'query');

    expect($queries)->toHaveCount(1)
        ->and($queries->first()['subject'])->toContain('InvoiceResource.php:')
        ->and($queries->first()['details'])->toContain('DB::');

    // Every row belongs to the audited panel only.
    expect($rows->pluck('panel')->unique()->all())->toBe(['audit']);
});

it('exits with 1 on failures, and on warnings only with --strict', function (): void {
    auditPanel([InvoiceResource::class]);

    $this->artisan('alfakit:tenancy:check', ['--panel' => 'audit'])->assertExitCode(0);
    $this->artisan('alfakit:tenancy:check', ['--panel' => 'audit', '--strict' => true])->assertExitCode(1);

    auditPanel([NoteResource::class]);

    $this->artisan('alfakit:tenancy:check', ['--panel' => 'audit'])->assertExitCode(1);
});

it('treats models passed with --shared as intentionally shared', function (): void {
    auditPanel([InvoiceResource::class]);

    $rows = collect(tenancyRows(['--panel' => 'audit', '--shared' => ['Tag']]));

    expect($rows->where('status', 'WARN')->pluck('check')->all())->toBe(['query'])
        ->and($rows->firstWhere('check', 'CheckboxList')['details'])->toContain('shared across tenants on purpose');
});

it('says so when no panel uses tenancy', function (): void {
    $this->artisan('alfakit:tenancy:check', ['--panel' => 'admin'])
        ->expectsOutputToContain('does not use tenancy')
        ->assertExitCode(0);
});

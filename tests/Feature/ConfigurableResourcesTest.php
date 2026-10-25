<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Resources\ResourceConfiguration;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\Pages\EditRtEntry;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtArchiveConfiguration;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtArchiveResource;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtPostResource;

beforeEach(function (): void {
    $this->panel = RtPanelProvider::registerPanel();
    alfakit()->setCurrentPanel($this->panel);
    EditRtEntry::$seen = [];
    $this->actingAs(User::query()->create(['name' => 'Cfg', 'email' => 'cfg'.uniqid().'@example.com', 'password' => 'password']));
    $this->archived = Post::query()->create(['title' => 'Old', 'slug' => 'old-'.uniqid(), 'status' => 'archived']);
    $this->draft = Post::query()->create(['title' => 'Draft', 'slug' => 'draft-'.uniqid(), 'status' => 'draft']);
});

afterEach(function (): void {
    RtArchiveResource::setConfiguration(null);
});

/**
 * @return array<int, string>
 */
function cfgTitles(AlfaSix\Alfakit\Tests\TestCase $test, string $url): array
{
    $page = inertiaProps(inertiaGet($test, $url))['page'];
    $table = collect($page['content'])->firstWhere('key', 'table');

    return array_map(static fn (array $record): string => $record['cells']['title']['state'], $table['records']);
}

it('creates configurations of the resource class with make()', function (): void {
    $configuration = RtArchiveResource::make('archived');

    expect($configuration)->toBeInstanceOf(RtArchiveConfiguration::class)
        ->and($configuration->getResource())->toBe(RtArchiveResource::class)
        ->and($configuration->getKey())->toBe('archived')
        ->and(RtPostResource::make('x'))->toBeInstanceOf(ResourceConfiguration::class);
});

it('refuses keys that cannot be part of a URL', function (): void {
    RtArchiveResource::make('two words');
})->throws(InvalidArgumentException::class);

it('registers every configuration under its own URL', function (): void {
    expect(RtArchiveResource::getUrl())->toBe('http://localhost/rt/entries')
        ->and(RtArchiveResource::getUrl(configuration: 'archived'))->toBe('http://localhost/rt/entries/archived')
        ->and(RtArchiveResource::getUrl('edit', ['record' => $this->draft], configuration: 'drafts'))->toBe("http://localhost/rt/draft-entries/{$this->draft->id}/edit")
        ->and(RtArchiveResource::withConfiguration('archived', fn (): string => RtArchiveResource::getSlug()))->toBe('entries/archived')
        ->and(RtArchiveResource::hasConfiguration())->toBeFalse();
});

it('serves each registration with its configuration', function (): void {
    expect(cfgTitles($this, '/rt/entries'))->toEqualCanonicalizing(['Old', 'Draft'])
        ->and(cfgTitles($this, '/rt/entries/archived'))->toBe(['Old'])
        ->and(cfgTitles($this, '/rt/draft-entries'))->toBe(['Draft']);
});

it('routes a configured registration under the plain slug before the plain registration\'s view page', function (): void {
    expect(app('router')->getRoutes()->match(request()->create('/rt/entries/archived'))->getName())
        ->toBe('alfakit.rt.resources.entries.archived.index')
        ->and(cfgTitles($this, '/rt/entries/archived'))->toBe(['Old']);

    $this->get("/rt/entries/{$this->draft->id}")->assertOk();
});

it('resolves records through the configuration that serves the page', function (): void {
    $this->get("/rt/entries/archived/{$this->draft->id}/edit", ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertNotFound();

    inertiaGet($this, "/rt/entries/archived/{$this->archived->id}/edit")->assertOk();
});

it('lists one navigation item per registration', function (): void {
    $navigation = navigationWithState(inertiaProps(inertiaGet($this, '/rt/entries/archived'))['alfakit']);
    $items = collect($navigation)->flatMap(fn (array $group): array => $group['items'])->keyBy('label');

    expect($items->keys()->all())->toContain('Entries', 'Archived entries')
        ->and($items['Archived entries']['url'])->toBe('http://localhost/rt/entries/archived')
        ->and($items['Archived entries']['active'] ?? false)->toBeTrue()
        ->and($items['Entries']['active'] ?? false)->toBeFalse();
});

it('keeps the configuration for internal requests of the page', function (): void {
    $token = inertiaProps(inertiaGet($this, "/rt/entries/archived/{$this->archived->id}/edit"))['page']['token'];

    // A later request for the plain registration must not leak into the token's request.
    inertiaGet($this, '/rt/entries')->assertOk();

    inertiaPost($this, '/rt/_alfakit/actions', ['token' => $token, 'component' => 'page', 'action' => 'whoami'])->assertRedirect();

    expect(EditRtEntry::$seen)->toBe(['archived']);
});

it('runs code as another registration and restores the current one', function (): void {
    RtArchiveResource::withConfiguration('drafts', function (): void {
        expect(RtArchiveResource::getConfiguration()?->getKey())->toBe('drafts')
            ->and(RtArchiveResource::getUrl())->toBe('http://localhost/rt/draft-entries');
    }, $this->panel);

    expect(RtArchiveResource::getConfiguration())->toBeNull();
});

it('fails for an unknown configuration key', function (): void {
    RtArchiveResource::withConfiguration('missing', fn (): null => null, $this->panel);
})->throws(InvalidArgumentException::class);

it('keeps configured registrations out of getResources()', function (): void {
    expect($this->panel->getResources())->toBe([RtPostResource::class, RtArchiveResource::class])
        ->and(array_map(static fn (ResourceConfiguration $configuration): string => $configuration->getKey(), $this->panel->getResourceConfigurations(RtArchiveResource::class)))->toBe(['archived', 'drafts']);
});

it('searches every registration of a configurable resource with its configuration', function (): void {
    $groups = (new AlfaSix\Alfakit\GlobalSearch\GlobalSearchProvider($this->panel))->search('Old');
    $urls = collect($groups)->flatMap(fn (array $group): array => array_column($group['results'], 'url'))->all();

    expect($urls)->toContain("http://localhost/rt/entries/{$this->archived->id}/edit")
        ->toContain("http://localhost/rt/entries/archived/{$this->archived->id}/edit")
        ->not->toContain("http://localhost/rt/draft-entries/{$this->archived->id}/edit");
});

<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Localization\TextDirection;
use AlfaSix\Alfakit\Mail\MailDefinition;
use AlfaSix\Alfakit\Mail\Mails;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Html;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Every registered mail (`Mails::register()`: AlfaKit's, its plugins' and yours) with
 * sample data, rendered in light and dark, left to right and right to left. Add it to a
 * panel with `->pages([MailPreview::class])`.
 *
 * Who may open it: `MailPreview::authorize(fn (User $user): bool => ...)`; without a
 * callback, anyone signed in while the app runs locally or in debug mode, otherwise users
 * the `viewAlfakitMailPreview` gate allows. Previews never send mail or save data.
 */
class MailPreview extends Page
{
    public const GATE = 'viewAlfakitMailPreview';

    protected static ?string $slug = 'mail-preview';

    protected static ?string $navigationIcon = 'mail';

    protected static ?int $navigationSort = 900;

    protected static Width|string|null $maxContentWidth = Width::Full;

    protected static ?Closure $authorizeUsing = null;

    /**
     * Who may open the page: `fn (Authenticatable $user): bool` (null restores the default).
     */
    public static function authorize(?Closure $callback): void
    {
        static::$authorizeUsing = $callback;
    }

    public static function canAccess(): bool
    {
        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->user();

        if ($user === null) {
            return false;
        }

        if (static::$authorizeUsing !== null) {
            return (bool) app()->call(static::$authorizeUsing, ['user' => $user]);
        }

        return app()->isLocal() || (bool) config('app.debug') || Gate::forUser($user)->allows(self::GATE);
    }

    public static function getNavigationLabel(): string
    {
        $label = static::getNavigationOverride('label');

        return is_string($label) ? $label : (string) __('alfakit::mail.preview.navigation');
    }

    public function getTitle(): string
    {
        return (string) __('alfakit::mail.preview.title');
    }

    public function getSubheading(): ?string
    {
        return $this->getSelectedMail()?->getLabel() ?? (string) __('alfakit::mail.preview.description');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        $selected = $this->getSelectedMail();

        return $selected === null ? [] : [static::getUrl() => $this->getTitle(), $selected->getLabel()];
    }

    public function getSelectedMail(): ?MailDefinition
    {
        $key = request()->query('mail');
        $definition = is_string($key) ? Mails::get($key) : null;

        return $definition?->hasPreview() ? $definition : null;
    }

    public function table(Table $table): Table
    {
        if ($this->getSelectedMail() !== null) {
            return $table;
        }

        return $table
            ->records(static fn (): array => static::rows())
            ->columns([
                TextColumn::make('label')
                    ->label(__('alfakit::mail.preview.columns.mail'))
                    ->weight('medium')
                    ->description(static fn (array $record): ?string => $record['description'])
                    ->searchable()
                    ->sortable(),
                TextColumn::make('group')
                    ->label(__('alfakit::mail.preview.columns.group'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('id')
                    ->label(__('alfakit::mail.preview.columns.key'))
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('editable')
                    ->label(__('alfakit::mail.preview.columns.editable'))
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('alfakit::mail.preview.actions.preview'))
                    ->icon('eye')
                    ->url(static fn (array $record): string => static::getUrl(['mail' => $record['id']])),
            ])
            ->recordUrl(static fn (array $record): string => static::getUrl(['mail' => $record['id']]))
            ->defaultSort('group')
            ->paginated(false)
            ->emptyStateIcon('mail')
            ->emptyStateHeading(__('alfakit::mail.preview.empty'));
    }

    /**
     * @return array<string, array{id: string, label: string, group: ?string, description: ?string, editable: bool}>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (Mails::all() as $key => $definition) {
            if (! $definition->hasPreview()) {
                continue;
            }

            $rows[$key] = [
                'id' => $key,
                'label' => $definition->getLabel(),
                'group' => $definition->getGroup(),
                'description' => $definition->getDescription(),
                'editable' => $definition->isEditable(),
            ];
        }

        return $rows;
    }

    public function content(Schema $schema): Schema
    {
        $definition = $this->getSelectedMail();

        if ($definition === null) {
            return $schema;
        }

        $sections = [];

        foreach (static::variants() as $variant) {
            $sections[] = Section::make($variant['label'])
                ->description($variant['locale'])
                ->compact()
                ->schema([Html::make(static::frame($definition, $variant['locale'], $variant['dark'], $variant['label']))]);
        }

        return $schema->components([Grid::make(['default' => 1, 'xl' => 2])->schema($sections)]);
    }

    /**
     * The four renderings: light and dark, in a left-to-right and a right-to-left locale
     * (the panel's own when it has one, else English and Arabic).
     *
     * @return array<int, array{label: string, locale: string, dark: bool}>
     */
    public static function variants(): array
    {
        $current = App::getLocale();
        $ltr = TextDirection::detect($current)->isRtl() ? 'en' : $current;
        $rtl = 'ar';

        foreach (alfakit()->getCurrentOrDefaultPanel()->getLocales() as $locale) {
            if ($locale->getDirection()->isRtl()) {
                $rtl = $locale->getCode();

                break;
            }
        }

        return [
            ['label' => (string) __('alfakit::mail.preview.variants.light_ltr'), 'locale' => $ltr, 'dark' => false],
            ['label' => (string) __('alfakit::mail.preview.variants.dark_ltr'), 'locale' => $ltr, 'dark' => true],
            ['label' => (string) __('alfakit::mail.preview.variants.light_rtl'), 'locale' => $rtl, 'dark' => false],
            ['label' => (string) __('alfakit::mail.preview.variants.dark_rtl'), 'locale' => $rtl, 'dark' => true],
        ];
    }

    /**
     * Render a mail in `$locale` (light or dark) to its subject and HTML.
     *
     * @return array{subject: string, html: string}
     */
    public static function renderMail(MailDefinition $definition, string $locale, bool $dark = false): array
    {
        $render = static fn (): array => MailTheme::withLocale($locale, static function () use ($definition): array {
            $rendered = $definition->render();

            return ['subject' => $rendered['subject'], 'html' => $rendered['html']->toHtml()];
        });

        return $dark ? MailTheme::withDarkMode($render) : $render();
    }

    /**
     * A sandboxed frame (no scripts, no same-origin access) showing the rendered mail.
     */
    protected static function frame(MailDefinition $definition, string $locale, bool $dark, string $variant): HtmlString
    {
        try {
            $mail = static::renderMail($definition, $locale, $dark);
        } catch (Throwable $exception) {
            report($exception);

            return new HtmlString('<p role="alert">'.e(__('alfakit::mail.preview.error', ['message' => $exception->getMessage()])).'</p>');
        }

        $title = __('alfakit::mail.preview.frame', ['mail' => $definition->getLabel(), 'variant' => $variant]);

        return new HtmlString(
            '<p class="text-muted-foreground mb-2 text-sm"><span class="font-medium">'.e(__('alfakit::mail.preview.subject')).'</span> '
            .'<span dir="auto">'.e($mail['subject']).'</span></p>'
            .'<iframe sandbox="" referrerpolicy="no-referrer" loading="lazy" title="'.e($title).'" srcdoc="'.e($mail['html']).'" '
            .'style="width:100%;height:720px;border:0;border-radius:0.5rem;background:#fff;color-scheme:'.($dark ? 'dark' : 'light').'"></iframe>',
        );
    }
}

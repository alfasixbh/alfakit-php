<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Placeholder;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\ModelLabel;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

final class Category extends Model {}
final class BlogPost extends Model {}
final class Person extends Model {}
final class SeoPage extends Model {}
final class NewsItem extends Model {}
final class Status extends Model {}

final class CategoryLabelResource extends Resource
{
    protected static ?string $model = Category::class;
}

final class PersonLabelResource extends Resource
{
    protected static ?string $model = Person::class;
}

final class StaffLabelResource extends Resource
{
    protected static ?string $model = Person::class;

    protected static ?string $modelLabel = 'staff member';
}

it('turns names into labels', function (string $name, string $label, bool $relationship): void {
    expect(Labels::fromName($name, $relationship))->toBe($label);
})->with([
    ['title', 'Title', true],
    ['author_id', 'Author', true],
    ['authorId', 'Author', true],
    ['valid_identifier', 'Valid identifier', true],
    ['id', 'ID', true],
    ['cover_image_url', 'Cover image URL', true],
    ['seo_title', 'SEO title', true],
    ['isFeatured', 'Is featured', true],
    ['author.name', 'Author', true],
    ['tags.name', 'Tags', true],
    ['author.email', 'Author email', true],
    ['author.team.title', 'Author team', true],
    ['meta.og_title', 'Og title', false],
    ['settings.notify_by_sms', 'Notify by SMS', false],
]);

it('derives singular and plural model labels in English', function (string $class, string $singular, string $plural): void {
    expect(ModelLabel::singular($class))->toBe($singular)
        ->and(ModelLabel::plural($class))->toBe($plural);
})->with([
    [Category::class, 'category', 'categories'],
    [BlogPost::class, 'blog post', 'blog posts'],
    [Person::class, 'person', 'people'],
    [SeoPage::class, 'SEO page', 'SEO pages'],
    [NewsItem::class, 'news item', 'news items'],
    [Status::class, 'status', 'statuses'],
]);

it('pluralises with the rules of languages the inflector knows', function (): void {
    expect(Labels::pluralize('tag'))->toBe('tags')
        ->and(Labels::pluralize('Category'))->toBe('Categories')
        ->and(Labels::pluralize('child'))->toBe('children')
        ->and(Labels::pluralize('catégorie', 'fr'))->toBe('catégories')
        ->and(Labels::pluralize('journal', 'fr'))->toBe('journaux')
        ->and(Labels::pluralize('canción', 'es'))->toBe('canciones')
        ->and(Labels::pluralize('kategori', 'tr'))->toBe('kategoriler')
        ->and(Labels::pluralize('FAQ'))->toBe('FAQ')
        // No rules for these languages: never guess an English "s".
        ->and(Labels::pluralize('تصنيف', 'ar'))->toBe('تصنيف')
        ->and(Labels::pluralize('Kategorie', 'de'))->toBe('Kategorie');
});

it('uses translated model labels and keeps URLs in English', function (): void {
    $directory = lang_path('ar');
    File::ensureDirectoryExists($directory);
    File::put("{$directory}/models.php", "<?php return ['category' => ['singular' => 'تصنيف', 'plural' => 'تصنيفات'], 'person' => 'شخص|أشخاص'];");

    try {
        app()->setLocale('ar');

        expect(CategoryLabelResource::getModelLabel())->toBe('تصنيف')
            ->and(CategoryLabelResource::getPluralModelLabel())->toBe('تصنيفات')
            ->and(CategoryLabelResource::getNavigationLabel())->toBe('تصنيفات')
            ->and(PersonLabelResource::getPluralModelLabel())->toBe('أشخاص')
            ->and(CategoryLabelResource::getSlug())->toBe('categories')
            ->and(PersonLabelResource::getSlug())->toBe('people')
            ->and(CategoryLabelResource::getTenantRelationshipName())->toBe('categories');

        app()->setLocale('en');

        expect(CategoryLabelResource::getModelLabel())->toBe('category')
            ->and(CategoryLabelResource::getTitleCasePluralModelLabel())->toBe('Categories');
    } finally {
        File::delete("{$directory}/models.php");
        app()->setLocale('en');
    }
});

it('pluralises hand-written English labels and leaves the slug alone', function (): void {
    expect(StaffLabelResource::getModelLabel())->toBe('staff member')
        ->and(StaffLabelResource::getPluralModelLabel())->toBe('staff members')
        ->and(StaffLabelResource::getSlug())->toBe('people');
});

it('generates consistent labels for fields, columns, entries and filters', function (): void {
    expect(TextInput::make('author_id')->getLabel())->toBe('Author')
        ->and(TextColumn::make('author.name')->getLabel())->toBe('Author')
        ->and(TextEntry::make('tags.name')->getLabel())->toBe('Tags')
        ->and(SelectFilter::make('category_id')->getLabel())->toBe('Category');
});

it('lower-cases labels inside sentences without breaking acronyms or German nouns', function (): void {
    expect(Labels::forSentence('Title', 'en'))->toBe('title')
        ->and(Labels::forSentence('URL slug', 'en'))->toBe('URL slug')
        ->and(Labels::forSentence('Adresse', 'de'))->toBe('Adresse')
        ->and(TextInput::make('cover_url')->getValidationAttribute())->toBe('cover URL')
        ->and(schema([Repeater::make('addresses')->schema([TextInput::make('city')])])->getFieldByStatePath('addresses')->toSchema()['addActionLabel'])
        ->toBe('Add to addresses');
});

it('labels a Placeholder like fields, entries and columns', function (): void {
    expect(Placeholder::make('created_at')->getLabel())->toBe('Created at')
        ->and(Placeholder::make('cover_image_url')->getLabel())->toBe('Cover image URL');
});

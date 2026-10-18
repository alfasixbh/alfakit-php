<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use Illuminate\Validation\ValidationException;

it('keeps saved(false) fields validated and in the form state but out of getState()', function (): void {
    $password = TextInput::make('password');
    $confirmation = TextInput::make('password_confirmation')->required()->saved(false);
    $schema = schema([$password, $confirmation])->fill(['password' => 'secret']);

    expect(fn () => $schema->validate())->toThrow(ValidationException::class);

    $schema->rawState(['password' => 'secret', 'password_confirmation' => 'secret']);

    expect($schema->validate())->toBe(['password' => 'secret'])
        ->and($confirmation->getState())->toBe('secret')
        ->and($confirmation->isSaved())->toBeFalse()
        ->and(TextInput::make('a')->saved(fn (): bool => true)->isSaved())->toBeTrue();
});

it('saves a disabled field with saved() like dehydrated()', function (): void {
    $field = TextInput::make('code')->disabled()->saved();
    $schema = schema([$field])->fill(['code' => 'ABC']);

    expect($schema->getState())->toBe(['code' => 'ABC'])
        ->and($field->isDehydratedWhenDisabled())->toBeTrue()
        ->and(schema([TextInput::make('code')->disabled()])->fill(['code' => 'A'])->getState())->toBe([]);
});

it('does not save the relationship of a saved(false) field', function (): void {
    $post = Post::query()->create(['title' => 'P', 'slug' => 'p']);
    $tags = collect(['php', 'laravel'])->map(fn (string $name) => Tag::query()->create(['name' => $name]));

    $select = Select::make('tags')->relationship('tags', 'name')->multiple()->saved(false);
    $list = CheckboxList::make('tag_ids')->relationship('tags', 'name')->saved(false);
    $schema = schema([$select, $list], record: $post, operation: 'edit')->fill();
    $schema->rawState(['tags' => [(string) $tags[0]->id], 'tag_ids' => [(string) $tags[1]->id]]);
    $schema->saveRelationships();

    expect($post->tags()->count())->toBe(0);
});

it('saves the relationship of a hidden field only with saveRelationshipsWhenHidden()', function (): void {
    $post = Post::query()->create(['title' => 'P', 'slug' => 'p']);
    $tag = Tag::query()->create(['name' => 'php']);

    $hidden = Select::make('tags')->relationship('tags', 'name')->multiple()->hidden();
    schema([$hidden], record: $post, operation: 'edit')->rawState(['tags' => [(string) $tag->id]]);

    expect($hidden->shouldSaveRelationships())->toBeFalse();
    $hidden->saveRelationships();
    expect($post->tags()->count())->toBe(0);

    $kept = Select::make('tags')->relationship('tags', 'name')->multiple()->hidden()->saveRelationshipsWhenHidden();
    schema([$kept], record: $post, operation: 'edit')->rawState(['tags' => [(string) $tag->id]]);

    expect($kept->shouldSaveRelationships())->toBeTrue();
    $kept->saveRelationships();
    expect($post->tags()->pluck('name')->all())->toBe(['php']);
});

it('skips the rules of non-dehydrated fields with validatedWhenNotDehydrated(false)', function (): void {
    $validated = TextInput::make('a')->required()->dehydrated(false);
    $skipped = TextInput::make('b')->required()->dehydrated(false)->validatedWhenNotDehydrated(false);
    $notSaved = TextInput::make('c')->required()->saved(false)->validatedWhenNotDehydrated(false);
    $schema = schema([$validated, $skipped, $notSaved])->fill();

    expect(array_keys($schema->getValidator()->getRules()))->toBe(['a'])
        ->and($skipped->isValidatedWhenNotDehydrated())->toBeFalse()
        ->and(TextInput::make('d')->required()->validatedWhenNotDehydrated(false)->getValidationRulesByPath())->toHaveKey('d');
});

it('serialises allowHtmlValidationMessages', function (): void {
    expect(TextInput::make('a')->allowHtmlValidationMessages()->toSchema()['allowHtmlValidationMessages'])->toBeTrue()
        ->and(TextInput::make('a')->toSchema())->not->toHaveKey('allowHtmlValidationMessages');
});

it('serialises select messages, html labels and label wrapping', function (): void {
    $select = Select::make('status')
        ->options(['a' => '<b>Bold</b><img src=x onerror=alert(1)>', 'Group' => ['b' => '<i>B</i>']])
        ->allowHtml()
        ->searchable()
        ->noOptionsMessage('Nothing here')
        ->searchingMessage('Looking…')
        ->wrapOptionLabels(false);

    schema([$select])->fill(['status' => 'a']);
    $serialized = $select->toSchema();

    expect($serialized)->toMatchArray([
        'noOptionsMessage' => 'Nothing here',
        'searchingMessage' => 'Looking…',
        'allowHtml' => true,
        'wrapOptionLabels' => false,
    ])
        ->and($serialized['options'][0]['label'])->toContain('<b>Bold</b>')->not->toContain('onerror')
        ->and($serialized['options'][1]['options'][0]['label'])->toBe('<i>B</i>')
        ->and($serialized['selectedLabels']['a'])->not->toContain('onerror')
        ->and($select->getSearchResults('bold')['a'])->not->toContain('onerror');

    // Validation still knows the option although its label is sanitised.
    expect(fn () => schema([$select])->fill(['status' => 'a'])->validate())->not->toThrow(ValidationException::class);

    $plain = Select::make('status')->options(['a' => '<b>A</b>'])->toSchema();

    expect($plain['options'][0]['label'])->toBe('<b>A</b>')
        ->and($plain)->not->toHaveKeys(['allowHtml', 'wrapOptionLabels', 'noOptionsMessage']);
});

it('serialises affix icon colours on text inputs, selects and date pickers', function (): void {
    foreach ([TextInput::make('a'), Select::make('b'), DateTimePicker::make('c')] as $field) {
        $schema = $field->prefixIcon('at-sign')->prefixIconColor('primary')->suffixIcon('x')->suffixIconColor(fn (): string => 'danger')->toSchema();

        expect($schema)->toMatchArray(['prefixIconColor' => 'primary', 'suffixIconColor' => 'danger']);
    }
});

it('serialises checkbox list html labels, record descriptions and grid direction', function (): void {
    $post = Post::query()->create(['title' => 'P', 'slug' => 'p']);
    Tag::query()->create(['name' => 'php']);
    Tag::query()->create(['name' => 'laravel']);

    $list = CheckboxList::make('tags')
        ->relationship('tags', 'name')
        ->getOptionDescriptionFromRecordUsing(fn (Tag $record): string => "About {$record->name}")
        ->columns(2)
        ->gridDirection('column');

    schema([$list], record: $post, operation: 'edit')->fill();
    $serialized = $list->toSchema();

    expect($serialized['options'])->toBe([
        ['value' => '2', 'label' => 'laravel', 'description' => 'About laravel'],
        ['value' => '1', 'label' => 'php', 'description' => 'About php'],
    ])
        ->and($serialized['gridColumns'])->toBe(2)
        ->and($serialized['gridDirection'])->toBe('column');

    $html = CheckboxList::make('x')->options(['a' => '<em>A</em><script>x</script>'])->allowHtml()->toSchema();

    expect($html['allowHtml'])->toBeTrue()
        ->and($html['options'][0]['label'])->toBe('<em>A</em>');
});

it('customises the checkbox list bulk toggle actions', function (): void {
    $list = CheckboxList::make('x')
        ->options(['a' => 'A'])
        ->bulkToggleable()
        ->selectAllAction(fn (Action $action) => $action->label('Pick every one')->icon('check-check'))
        ->deselectAllAction(fn (Action $action) => $action->hidden());

    $serialized = $list->toSchema();

    expect($serialized['selectAllAction'])->toBe(['label' => 'Pick every one', 'icon' => 'check-check'])
        ->and($serialized['deselectAllAction'])->toMatchArray(['hidden' => true])
        ->and(CheckboxList::make('y')->bulkToggleable()->toSchema())->not->toHaveKeys(['selectAllAction', 'deselectAllAction']);
});

it('lays toggle buttons out in a grid', function (): void {
    $schema = ToggleButtons::make('size')->options(['s' => 'S', 'm' => 'M'])->columns(['default' => 2, 'lg' => 3])->gridDirection('row')->toSchema();

    expect($schema['gridColumns'])->toBe(3)
        ->and($schema['gridDirection'])->toBe('row')
        ->and(ToggleButtons::make('x')->gridDirection('diagonal')->toSchema())->not->toHaveKey('gridDirection');
});

it('serialises date-time picker steps, week start and default focused date', function (): void {
    $picker = DateTimePicker::make('starts_at')
        ->hoursStep(2)
        ->minutesStep(15)
        ->secondsStep(10)
        ->seconds()
        ->weekStartsOnSunday()
        ->defaultFocusedDate('2030-06-15 00:00:00');

    $schema = $picker->toSchema();

    expect($schema)->toMatchArray([
        'hoursStep' => 2,
        'minutesStep' => 15,
        'secondsStep' => 10,
        'firstDayOfWeek' => 0,
        'defaultFocusedDate' => '2030-06-15 00:00:00',
    ])
        ->and(DatePicker::make('d')->weekStartsOnMonday()->toSchema()['firstDayOfWeek'])->toBe(1)
        ->and(DatePicker::make('d')->minutesStep(15)->toSchema())->not->toHaveKey('minutesStep')
        ->and(DateTimePicker::make('d')->secondsStep(5)->toSchema())->not->toHaveKey('secondsStep')
        ->and(DatePicker::make('d')->defaultFocusedDate(now()->setDate(2031, 1, 2))->toSchema()['defaultFocusedDate'])->toBe('2031-01-02');
});

it('takes the first day of the week from the panel locale by default', function (): void {
    expect(DatePicker::make('d')->getFirstDayOfWeek())->toBe(alfakit()->getCurrentLocale()->getFirstDayOfWeek())
        ->and(DatePicker::make('d')->firstDayOfWeek(fn (): int => 6)->getFirstDayOfWeek())->toBe(6);
});

it('disables grammarly on a textarea through input attributes', function (): void {
    $attributes = Textarea::make('bio')->disableGrammarly()->extraInputAttributes(['rows' => 3])->toSchema()['extraInputAttributes'];

    expect($attributes)->toBe(['data-gramm' => 'false', 'data-gramm_editor' => 'false', 'data-enable-grammarly' => 'false', 'rows' => 3])
        ->and(Textarea::make('bio')->toSchema())->not->toHaveKey('extraInputAttributes');
});

it('saves hidden relationship fields through the schema only when opted in', function (): void {
    $post = Post::query()->create(['title' => 'P', 'slug' => 'p']);
    $tag = Tag::query()->create(['name' => 'php']);

    schema([Select::make('tags')->relationship('tags', 'name')->multiple()->hidden()], record: $post, operation: 'edit')
        ->rawState(['tags' => [(string) $tag->id]])
        ->saveRelationships();

    expect($post->tags()->count())->toBe(0);

    schema([Select::make('tags')->relationship('tags', 'name')->multiple()->hidden()->saveRelationshipsWhenHidden()], record: $post, operation: 'edit')
        ->rawState(['tags' => [(string) $tag->id]])
        ->saveRelationships();

    expect($post->tags()->pluck('name')->all())->toBe(['php']);

    schema([Select::make('tags')->relationship('tags', 'name')->multiple()->saved(false)], record: $post, operation: 'edit')
        ->rawState(['tags' => []])
        ->saveRelationships();

    expect($post->tags()->count())->toBe(1);
});

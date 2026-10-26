<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\MoneyInput;
use AlfaSix\Alfakit\Localization\Languages;
use AlfaSix\Alfakit\Localization\Locale;
use AlfaSix\Alfakit\Localization\TextDirection;
use AlfaSix\Alfakit\Localization\TranslationChecker;
use AlfaSix\Alfakit\Localization\Translations;
use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\File;

it('detects the writing direction of any locale', function (string $locale, TextDirection $direction): void {
    expect(TextDirection::detect($locale))->toBe($direction);
})->with([
    ['en', TextDirection::Ltr],
    ['ar', TextDirection::Rtl],
    ['ar_BH', TextDirection::Rtl],
    ['he', TextDirection::Rtl],
    ['fa', TextDirection::Rtl],
    ['ur', TextDirection::Rtl],
    ['dv', TextDirection::Rtl],
    ['ckb', TextDirection::Rtl],
    ['pa_Arab', TextDirection::Rtl],
    ['az-Arab', TextDirection::Rtl],
    ['sr_Latn', TextDirection::Ltr],
    ['ku', TextDirection::Ltr],
    ['ja', TextDirection::Ltr],
]);

it('normalises codes and derives names, tags and week starts', function (): void {
    expect(Locale::normalize('pt-br'))->toBe('pt_BR')
        ->and(Locale::normalize('zh-hant-tw'))->toBe('zh_Hant_TW')
        ->and(Locale::make('pt-BR')->getTag())->toBe('pt-BR')
        ->and(Locale::make('de')->getLabel())->toBe('Deutsch')
        ->and(Locale::make('ar')->getLabel())->toBe('العربية')
        ->and(Locale::make('fr')->getLabelIn('en'))->toBe('French')
        ->and(Locale::make('ar')->getFirstDayOfWeek())->toBe(6)
        ->and(Locale::make('he')->getFirstDayOfWeek())->toBe(0)
        ->and(Locale::make('de')->getFirstDayOfWeek())->toBe(1)
        ->and(Locale::make('ar')->label('عربي')->firstDayOfWeek(0)->toSchema())->toBe([
            'code' => 'ar', 'tag' => 'ar', 'label' => 'عربي', 'direction' => 'rtl',
        ]);
});

it('knows currency precision, names and symbols from ICU', function (): void {
    expect(Currency::decimals('USD'))->toBe(2)
        ->and(Currency::decimals('bhd'))->toBe(3)
        ->and(Currency::decimals('JPY'))->toBe(0)
        ->and(Currency::name('EUR', 'en'))->toBe('Euro')
        ->and(Currency::symbol('EUR', 'de'))->toBe('€')
        ->and(Currency::codes())->toContain('BHD', 'USD', 'JPY')->not->toContain('DEM')
        ->and(Currency::options(['USD', 'EUR'], 'en'))->toBe(['USD' => 'US Dollar (USD)', 'EUR' => 'Euro (EUR)']);
});

it('converts minor units without losing precision', function (): void {
    expect(Currency::fromMinorUnits(12345, 'BHD'))->toBe('12.345')
        ->and(Currency::fromMinorUnits(-5, 'USD'))->toBe('-0.05')
        ->and(Currency::fromMinorUnits(1500, 'JPY'))->toBe('1500')
        ->and(Currency::toMinorUnits('12.345', 'BHD'))->toBe(12345)
        ->and(Currency::toMinorUnits('0.1', 'USD') + Currency::toMinorUnits('0.2', 'USD'))->toBe(30)
        ->and(Currency::format(12345, 'BHD', 'en', inMinorUnits: true))->toBe("BHD\u{00A0}12.345")
        ->and(Currency::format(1234.5, 'EUR', 'de'))->toBe("1.234,50\u{00A0}€");
});

it('rejects invalid currency codes', function (): void {
    Currency::decimals('dollars');
})->throws(InvalidArgumentException::class);

it('formats money columns in the currency and its precision', function (): void {
    $state = fn (TextColumn $column, mixed $value): mixed => $column->formatState($value);

    expect($state(TextColumn::make('price')->money('EUR'), 1234.5))->toBe('€1,234.50')
        ->and($state(TextColumn::make('price')->money('BHD', inMinorUnits: true), 12500))->toBe("BHD\u{00A0}12.500")
        ->and($state(TextColumn::make('price')->money('USD', divideBy: 100), 1999))->toBe('$19.99')
        ->and($state(TextColumn::make('price')->money(), 10))->toBe('$10.00');
});

it("formats numeric() columns in the app's language outside panels", function (): void {
    $previous = [app()->getLocale(), Illuminate\Support\Number::defaultLocale()];
    // Outside panels nothing calls Number::useLocale(): only the app's locale is German.
    app()->setLocale('de');
    Illuminate\Support\Number::useLocale('en');

    try {
        expect(TextColumn::make('views')->numeric()->formatState(1234567))->toBe('1.234.567')
            ->and(TextColumn::make('views')->numeric(decimalPlaces: 2)->formatState(1234.5))->toBe('1.234,50')
            ->and(TextColumn::make('views')->numeric(locale: 'en')->formatState(1234567))->toBe('1,234,567');
    } finally {
        app()->setLocale($previous[0]);
        Illuminate\Support\Number::useLocale($previous[1]);
    }
});

it('hydrates and dehydrates money inputs in major or minor units', function (): void {
    $form = schema([
        MoneyInput::make('price')->currency('BHD')->minorUnits(),
        MoneyInput::make('fee')->currency('JPY')->minValue(0),
    ]);

    $form->fill(['price' => 12500, 'fee' => 300]);

    expect($form->getRawState())->toMatchArray(['price' => '12.500', 'fee' => '300'])
        ->and($form->getState())->toMatchArray(['price' => 12500, 'fee' => '300']);

    $field = $form->getFieldByStatePath('price');

    expect($field->getValidationRules())->toContain('numeric', 'decimal:0,3')
        ->and($field->toSchema())->toMatchArray(['type' => 'MoneyInput', 'currency' => 'BHD', 'decimals' => 3]);
});

it('reports a money amount below minValue() as a validation error, not a silent 0 (W-V3-11)', function (): void {
    // The renderers send the amount as typed (no clamping); the server's min rule explains it.
    $form = schema([MoneyInput::make('price')->currency('EUR')->minValue(0)->maxValue(100)]);

    $form->fill(['price' => -5]);

    try {
        $form->validate();
        $this->fail('A negative amount passed minValue(0).');
    } catch (Illuminate\Validation\ValidationException $exception) {
        expect($exception->errors())->toHaveKey('price');
    }

    expect($form->getFieldByStatePath('price')->toSchema())->toMatchArray(['min' => 0, 'max' => 100]);
});

it('converts date-times between the display and storage timezones', function (): void {
    config(['app.timezone' => 'UTC']);

    $form = schema([
        DateTimePicker::make('starts_at')->timezone('Asia/Bahrain'),
        DatePicker::make('birthday')->timezone('Asia/Bahrain'),
    ]);

    $form->fill(['starts_at' => '2026-01-01 09:00:00', 'birthday' => '2000-02-29']);

    expect($form->getRawState())->toMatchArray(['starts_at' => '2026-01-01 12:00', 'birthday' => '2000-02-29'])
        ->and($form->getState())->toMatchArray(['starts_at' => '2026-01-01 09:00', 'birthday' => '2000-02-29']);
});

it('offers localised currency, language and timezone options', function (): void {
    expect(Languages::options(['de', 'ar'], 'en'))->toBe(['de' => 'German · Deutsch', 'ar' => 'Arabic · العربية'])
        ->and(Languages::options(['en'], 'en'))->toBe(['en' => 'English'])
        ->and(Languages::codes())->toContain('sw', 'ar', 'zh')
        ->and(Languages::timezones(['Asia/Bahrain']))->toBe(['Asia/Bahrain' => 'Asia/Bahrain (UTC+03:00)']);
});

it('layers registered translation directories under app overrides', function (): void {
    $pack = sys_get_temp_dir().'/alfakit-pack-'.uniqid();
    File::ensureDirectoryExists("{$pack}/xx");
    File::put("{$pack}/xx/ui.php", "<?php return ['save' => 'Pack save', 'cancel' => 'Pack cancel'];");

    $overrides = lang_path('vendor/alfakit/xx');
    File::ensureDirectoryExists($overrides);
    File::put("{$overrides}/ui.php", "<?php return ['cancel' => 'App cancel'];");

    Translations::register($pack);

    try {
        expect(__('alfakit::ui.save', [], 'xx'))->toBe('Pack save')
            ->and(__('alfakit::ui.cancel', [], 'xx'))->toBe('App cancel')
            ->and(__('alfakit::ui.close', [], 'xx'))->toBe('Close')
            ->and(Translations::availableLocales())->toContain('en', 'xx');
    } finally {
        File::deleteDirectory($pack);
        File::deleteDirectory(lang_path('vendor/alfakit/xx'));
    }
});

it('falls back to the base language pack for regional and script variants', function (): void {
    $pack = sys_get_temp_dir().'/alfakit-variant-'.uniqid();
    File::ensureDirectoryExists("{$pack}/xv");
    File::ensureDirectoryExists("{$pack}/xv_YY");
    File::put("{$pack}/xv/ui.php", "<?php return ['save' => 'Base save', 'cancel' => 'Base cancel'];");
    File::put("{$pack}/xv_YY/ui.php", "<?php return ['cancel' => 'Variant cancel'];");

    $overrides = lang_path('vendor/alfakit/xv');
    File::ensureDirectoryExists($overrides);
    File::put("{$overrides}/ui.php", "<?php return ['submit' => 'App base submit'];");

    Translations::register($pack);

    try {
        // ar_EG has no pack of its own: its strings come from ar, not from English.
        expect(__('alfakit::ui.save', [], 'xv_YY'))->toBe('Base save')
            ->and(__('alfakit::ui.cancel', [], 'xv_YY'))->toBe('Variant cancel')
            ->and(__('alfakit::ui.submit', [], 'xv_YY'))->toBe('App base submit')
            ->and(__('alfakit::ui.save', [], 'xv-ZZ'))->toBe('Base save')
            ->and(__('alfakit::ui.close', [], 'xv_YY'))->toBe('Close');
    } finally {
        File::deleteDirectory($pack);
        File::deleteDirectory(lang_path('vendor/alfakit/xv'));
    }
});

it('reports missing keys and placeholder mistakes', function (): void {
    $pack = sys_get_temp_dir().'/alfakit-check-'.uniqid();
    File::ensureDirectoryExists("{$pack}/yy");
    File::put("{$pack}/yy/ui.php", "<?php return ['save' => 'Speichern', 'table' => ['showing' => ':first bis :last'], 'obsolete' => 'x'];");
    Translations::register($pack);

    try {
        $report = (new TranslationChecker(app('translator')->getLoader()))->check('yy');

        expect($report['missing'])->toContain('ui.cancel')->not->toContain('ui.save')
            ->and($report['placeholders'])->toBe(['ui.table.showing'])
            ->and($report['extra'])->toBe(['ui.obsolete'])
            ->and(TranslationChecker::placeholders('Hi :Name, :count of :name'))->toBe(['count', 'name']);
    } finally {
        File::deleteDirectory($pack);
    }
});

it('translates the calendar month navigation labels in every pack (W-V3-06)', function (): void {
    Translations::register(dirname(__DIR__, 4).'/translations/lang');

    expect(__('alfakit::ui.form.previous_month'))->toBe('Previous month')
        ->and(__('alfakit::ui.form.next_month'))->toBe('Next month')
        ->and(__('alfakit::ui.form.previous_month', [], 'ar'))->toBe('الشهر السابق')
        ->and(__('alfakit::ui.form.next_month', [], 'de'))->toBe('Nächster Monat');
});

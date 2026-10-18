<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use Illuminate\Support\Str;

/**
 * How `make:alfakit-field --from-shadcn=<component>` binds an installed shadcn component
 * (shadcn/ui, shadcn-vue, shadcn-svelte) to the field's state: its imports, the control
 * markup, the state's TypeScript type and the PHP validation rule. Unknown components get
 * a generic binding to finish by hand.
 */
final class ShadcnField
{
    /** The components with a ready-made binding. */
    public const KNOWN = ['input', 'textarea', 'switch', 'checkbox', 'slider', 'calendar', 'input-otp'];

    /**
     * @param  array<string, string>  $replacements  `imports`, `control`, `valueType`, `rule`
     */
    private function __construct(public readonly string $component, public readonly bool $known, public readonly array $replacements) {}

    /**
     * The installed file of a shadcn component in the app, or null when it is not installed:
     * `resources/js/components/ui/<name>.tsx` (React) or `…/ui/<name>/index.ts` (Vue, Svelte).
     */
    public static function installedPath(string $adapter, string $component): ?string
    {
        $base = resource_path('js/components/ui/'.$component);
        $candidates = $adapter === 'react'
            ? ["{$base}.tsx", "{$base}.jsx", "{$base}/index.tsx", "{$base}/index.ts"]
            : ["{$base}/index.ts", "{$base}/index.js"];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function for(string $adapter, string $component): self
    {
        $component = Str::kebab($component);
        $known = self::bindings($adapter)[$component] ?? null;

        if ($known === null) {
            // `input-otp` → `InputOTP`, `toggle-group` → `ToggleGroup`.
            $main = str_replace('Otp', 'OTP', Str::studly($component));

            $known = match ($adapter) {
                'react' => [
                    'imports' => "import { {$main} } from '@/components/ui/{$component}'",
                    'control' => "{/* Bind the value to {$main}'s own props: check its file in resources/js/components/ui. */}\n      <{$main} id={id} value={value ?? ''} onChange={(event: { target: { value: string } }) => setValue(event.target.value)} onBlur={blur} disabled={disabled} {...aria} />",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
                'vue' => [
                    'imports' => "import { {$main} } from '@/components/ui/{$component}'",
                    'control' => "<!-- Bind the value to {$main}'s own props: check resources/js/components/ui/{$component}. -->\n    <{$main} :id=\"id\" :model-value=\"value ?? undefined\" :disabled=\"disabled\" v-bind=\"aria\" @update:model-value=\"setValue\" @blur=\"blur\" />",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
                default => [
                    'imports' => "  import { {$main} } from '@/components/ui/{$component}'",
                    'control' => "<!-- Bind the value to {$main}'s own props: check resources/js/components/ui/{$component}. -->\n  <{$main}\n    id={field.id}\n    bind:value={() => field.value ?? '', (value) => field.setValue(value)}\n    disabled={field.disabled}\n    onblur={field.blur}\n    {...field.aria}\n  />",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
            };

            return new self($component, false, $known);
        }

        return new self($component, true, $known);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function bindings(string $adapter): array
    {
        return match ($adapter) {
            'react' => [
                'input' => [
                    'imports' => "import { Input } from '@/components/ui/input'",
                    'control' => "<Input\n        id={id}\n        name={component.name}\n        dir=\"auto\"\n        value={value ?? ''}\n        placeholder={component.placeholder}\n        disabled={disabled}\n        readOnly={component.readOnly}\n        onChange={(event) => setValue(event.target.value)}\n        onBlur={blur}\n        {...aria}\n      />",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
                'textarea' => [
                    'imports' => "import { Textarea } from '@/components/ui/textarea'",
                    'control' => "<Textarea\n        id={id}\n        name={component.name}\n        dir=\"auto\"\n        value={value ?? ''}\n        disabled={disabled}\n        readOnly={component.readOnly}\n        onChange={(event) => setValue(event.target.value)}\n        onBlur={blur}\n        {...aria}\n      />",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
                'switch' => [
                    'imports' => "import { Switch } from '@/components/ui/switch'",
                    'control' => "<Switch\n        id={id}\n        checked={value === true}\n        onCheckedChange={setValue}\n        onBlur={blur}\n        disabled={disabled}\n        {...aria}\n      />",
                    'valueType' => 'boolean | null',
                    'rule' => 'boolean',
                ],
                'checkbox' => [
                    'imports' => "import { Checkbox } from '@/components/ui/checkbox'",
                    'control' => "<Checkbox\n        id={id}\n        checked={value === true}\n        onCheckedChange={(checked) => setValue(checked === true)}\n        onBlur={blur}\n        disabled={disabled}\n        {...aria}\n      />",
                    'valueType' => 'boolean | null',
                    'rule' => 'boolean',
                ],
                'slider' => [
                    'imports' => "import { Slider } from '@/components/ui/slider'",
                    'control' => "<Slider\n        id={id}\n        value={[value ?? 0]}\n        onValueChange={([next]) => setValue(next ?? null)}\n        onValueCommit={blur}\n        disabled={disabled}\n        {...aria}\n      />",
                    'valueType' => 'number | null',
                    'rule' => 'numeric',
                ],
                'calendar' => [
                    // The state is stored as `Y-m-d`, like DatePicker's default format.
                    'imports' => "import { Calendar } from '@/components/ui/calendar'\nimport { format, parseISO } from 'date-fns'",
                    'control' => "<Calendar\n        id={id}\n        mode=\"single\"\n        selected={value ? parseISO(value) : undefined}\n        onSelect={(date) => {\n          setValue(date ? format(date, 'yyyy-MM-dd') : null)\n          blur()\n        }}\n        disabled={disabled}\n        {...aria}\n      />",
                    'valueType' => 'string | null',
                    'rule' => 'date',
                ],
                'input-otp' => [
                    'imports' => "import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp'",
                    'control' => "<InputOTP\n        id={id}\n        maxLength={6}\n        value={value ?? ''}\n        onChange={setValue}\n        onBlur={blur}\n        disabled={disabled}\n        {...aria}\n      >\n        <InputOTPGroup>\n          {[0, 1, 2, 3, 4, 5].map((index) => (\n            <InputOTPSlot key={index} index={index} />\n          ))}\n        </InputOTPGroup>\n      </InputOTP>",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
            ],
            'vue' => [
                'input' => [
                    'imports' => "import { Input } from '@/components/ui/input'",
                    'control' => "<Input\n      :id=\"id\"\n      :name=\"props.component.name\"\n      dir=\"auto\"\n      :model-value=\"value ?? ''\"\n      :placeholder=\"props.component.placeholder\"\n      :disabled=\"disabled\"\n      :readonly=\"props.component.readOnly\"\n      v-bind=\"aria\"\n      @update:model-value=\"(next) => setValue(String(next))\"\n      @blur=\"blur\"\n    />",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
                'textarea' => [
                    'imports' => "import { Textarea } from '@/components/ui/textarea'",
                    'control' => "<Textarea\n      :id=\"id\"\n      :name=\"props.component.name\"\n      dir=\"auto\"\n      :model-value=\"value ?? ''\"\n      :disabled=\"disabled\"\n      :readonly=\"props.component.readOnly\"\n      v-bind=\"aria\"\n      @update:model-value=\"(next) => setValue(String(next))\"\n      @blur=\"blur\"\n    />",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
                'switch' => [
                    'imports' => "import { Switch } from '@/components/ui/switch'",
                    'control' => "<Switch\n      :id=\"id\"\n      :model-value=\"value === true\"\n      :disabled=\"disabled\"\n      v-bind=\"aria\"\n      @update:model-value=\"setValue\"\n      @blur=\"blur\"\n    />",
                    'valueType' => 'boolean | null',
                    'rule' => 'boolean',
                ],
                'checkbox' => [
                    'imports' => "import { Checkbox } from '@/components/ui/checkbox'",
                    'control' => "<Checkbox\n      :id=\"id\"\n      :model-value=\"value === true\"\n      :disabled=\"disabled\"\n      v-bind=\"aria\"\n      @update:model-value=\"(checked) => setValue(checked === true)\"\n      @blur=\"blur\"\n    />",
                    'valueType' => 'boolean | null',
                    'rule' => 'boolean',
                ],
                'slider' => [
                    'imports' => "import { Slider } from '@/components/ui/slider'",
                    'control' => "<Slider\n      :id=\"id\"\n      :model-value=\"[value ?? 0]\"\n      :disabled=\"disabled\"\n      v-bind=\"aria\"\n      @update:model-value=\"(next) => setValue(next?.[0] ?? null)\"\n      @value-commit=\"blur\"\n    />",
                    'valueType' => 'number | null',
                    'rule' => 'numeric',
                ],
                'calendar' => [
                    // `CalendarDate.toString()` is `Y-m-d`, like DatePicker's default format.
                    'imports' => "import { Calendar } from '@/components/ui/calendar'\nimport { parseDate, type DateValue } from '@internationalized/date'",
                    'control' => "<Calendar\n      :id=\"id\"\n      :model-value=\"value ? parseDate(value) : undefined\"\n      :disabled=\"disabled\"\n      v-bind=\"aria\"\n      @update:model-value=\"(date?: DateValue) => { setValue(date ? date.toString() : null); blur() }\"\n    />",
                    'valueType' => 'string | null',
                    'rule' => 'date',
                ],
                'input-otp' => [
                    'imports' => "import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp'",
                    'control' => "<InputOTP\n      :id=\"id\"\n      :maxlength=\"6\"\n      :model-value=\"value ?? ''\"\n      :disabled=\"disabled\"\n      v-bind=\"aria\"\n      @update:model-value=\"setValue\"\n      @blur=\"blur\"\n    >\n      <InputOTPGroup>\n        <InputOTPSlot v-for=\"index in 6\" :key=\"index\" :index=\"index - 1\" />\n      </InputOTPGroup>\n    </InputOTP>",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
            ],
            default => [
                'input' => [
                    'imports' => "  import { Input } from '@/components/ui/input'",
                    'control' => "<Input\n    id={field.id}\n    name={component.name}\n    dir=\"auto\"\n    bind:value={() => field.value ?? '', (value) => field.setValue(value)}\n    placeholder={component.placeholder}\n    disabled={field.disabled}\n    readonly={component.readOnly}\n    onblur={field.blur}\n    {...field.aria}\n  />",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
                'textarea' => [
                    'imports' => "  import { Textarea } from '@/components/ui/textarea'",
                    'control' => "<Textarea\n    id={field.id}\n    name={component.name}\n    dir=\"auto\"\n    bind:value={() => field.value ?? '', (value) => field.setValue(value)}\n    disabled={field.disabled}\n    readonly={component.readOnly}\n    onblur={field.blur}\n    {...field.aria}\n  />",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
                'switch' => [
                    'imports' => "  import { Switch } from '@/components/ui/switch'",
                    'control' => "<Switch\n    id={field.id}\n    bind:checked={() => field.value === true, (checked) => field.setValue(checked)}\n    disabled={field.disabled}\n    onblur={field.blur}\n    {...field.aria}\n  />",
                    'valueType' => 'boolean | null',
                    'rule' => 'boolean',
                ],
                'checkbox' => [
                    'imports' => "  import { Checkbox } from '@/components/ui/checkbox'",
                    'control' => "<Checkbox\n    id={field.id}\n    bind:checked={() => field.value === true, (checked) => field.setValue(checked)}\n    disabled={field.disabled}\n    onblur={field.blur}\n    {...field.aria}\n  />",
                    'valueType' => 'boolean | null',
                    'rule' => 'boolean',
                ],
                'slider' => [
                    'imports' => "  import { Slider } from '@/components/ui/slider'",
                    'control' => "<Slider\n    id={field.id}\n    type=\"single\"\n    bind:value={() => field.value ?? 0, (value) => field.setValue(value)}\n    onValueCommit={field.blur}\n    disabled={field.disabled}\n    {...field.aria}\n  />",
                    'valueType' => 'number | null',
                    'rule' => 'numeric',
                ],
                'calendar' => [
                    // `CalendarDate.toString()` is `Y-m-d`, like DatePicker's default format.
                    'imports' => "  import { Calendar } from '@/components/ui/calendar'\n  import { parseDate, type DateValue } from '@internationalized/date'",
                    'control' => "<Calendar\n    id={field.id}\n    type=\"single\"\n    bind:value={\n      () => (field.value ? parseDate(field.value) : undefined),\n      (date: DateValue | undefined) => {\n        field.setValue(date ? date.toString() : null)\n        field.blur()\n      }\n    }\n    disabled={field.disabled}\n    {...field.aria}\n  />",
                    'valueType' => 'string | null',
                    'rule' => 'date',
                ],
                'input-otp' => [
                    'imports' => "  import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp'",
                    'control' => "<InputOTP\n    id={field.id}\n    maxlength={6}\n    bind:value={() => field.value ?? '', (value) => field.setValue(value)}\n    disabled={field.disabled}\n    onblur={field.blur}\n    {...field.aria}\n  >\n    <InputOTPGroup>\n      {#each { length: 6 } as _, index (index)}\n        <InputOTPSlot {index} />\n      {/each}\n    </InputOTPGroup>\n  </InputOTP>",
                    'valueType' => 'string | null',
                    'rule' => 'string',
                ],
            ],
        };
    }
}

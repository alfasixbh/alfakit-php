<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Support\Html;
use Closure;

/**
 * `allowHtml()`: option labels are HTML. They are sanitised on the server
 * (`Support\Html::sanitize()`) before they reach the browser, so a label built from user
 * data cannot inject scripts.
 */
trait CanAllowHtmlOptions
{
    protected bool|Closure $isHtmlAllowed = false;

    public function allowHtml(bool|Closure $condition = true): static
    {
        $this->isHtmlAllowed = $condition;

        return $this;
    }

    public function isHtmlAllowed(): bool
    {
        return (bool) $this->evaluate($this->isHtmlAllowed);
    }

    /**
     * Sanitise the labels of serialised options (and option groups) when HTML is allowed.
     *
     * @param  array<int, array<string, mixed>>  $options
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeSerializedOptionLabels(array $options): array
    {
        if (! $this->isHtmlAllowed()) {
            return $options;
        }

        return array_map(function (array $option): array {
            if (isset($option['options']) && is_array($option['options'])) {
                $option['options'] = $this->sanitizeSerializedOptionLabels($option['options']);

                return $option;
            }

            if (isset($option['label'])) {
                $option['label'] = Html::sanitize((string) $option['label']);
            }

            return $option;
        }, $options);
    }

    /**
     * @param  array<array-key, mixed>  $labels  value => label
     * @return array<array-key, mixed>
     */
    protected function sanitizeOptionLabelMap(array $labels): array
    {
        if (! $this->isHtmlAllowed()) {
            return $labels;
        }

        return array_map(static fn (mixed $label): string => Html::sanitize((string) $label), $labels);
    }
}

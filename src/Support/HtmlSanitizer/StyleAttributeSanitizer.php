<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\HtmlSanitizer;

use AlfaSix\Alfakit\Support\Html;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps only the inline styles the rich editor writes: `color` (text colour),
 * `background-color` (highlight) and `text-align` (alignment), each with a plain value.
 * Everything else (`position`, `background-image: url()`, `expression()`, custom
 * properties) is dropped, so stored content can't overlay the page or load resources.
 */
final class StyleAttributeSanitizer implements AttributeSanitizerInterface
{
    private const ALIGNMENTS = ['start', 'end', 'center', 'justify', 'left', 'right'];

    public function getSupportedElements(): ?array
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function getSupportedAttributes(): array
    {
        return ['style', 'data-color'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ($attribute === 'data-color') {
            return Html::isSafeColor($value) ? trim($value) : null;
        }

        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $propertyValue] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);

            $allowed = match ($property) {
                'color', 'background-color' => Html::isSafeColor($propertyValue),
                'text-align' => in_array(strtolower($propertyValue), self::ALIGNMENTS, true),
                default => false,
            };

            if ($allowed) {
                $kept[$property] = $property.': '.($property === 'text-align' ? strtolower($propertyValue) : $propertyValue);
            }
        }

        return $kept === [] ? null : implode('; ', $kept);
    }
}

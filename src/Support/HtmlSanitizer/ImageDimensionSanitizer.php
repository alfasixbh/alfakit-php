<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps image `width` and `height` (written by `RichEditor::resizableImages()`) only as
 * plain pixel sizes (`320`, `320px`) or percentages up to 100%, so stored content can't
 * use them to blow up the page.
 */
final class ImageDimensionSanitizer implements AttributeSanitizerInterface
{
    public const MAX_PIXELS = 10000;

    /**
     * @return list<string>
     */
    public function getSupportedElements(): array
    {
        return ['img'];
    }

    /**
     * @return list<string>
     */
    public function getSupportedAttributes(): array
    {
        return ['width', 'height'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $value = strtolower(trim($value));

        if (preg_match('/^(\d{1,5})(?:\.\d+)?(px)?$/', $value, $match) === 1) {
            $pixels = (int) $match[1];

            return $pixels > 0 && $pixels <= self::MAX_PIXELS ? (string) $pixels : null;
        }

        if (preg_match('/^(\d{1,3})%$/', $value, $match) === 1 && (int) $match[1] > 0 && (int) $match[1] <= 100) {
            return $match[1].'%';
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Restricts `data:` media URLs to images (`data:image/png;base64,...`). The scheme
 * check itself is done by Symfony's URL sanitiser, which runs first.
 */
final class ImageSourceSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function getSupportedAttributes(): array
    {
        return ['src', 'poster', 'lowsrc', 'background'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if (preg_match('/^\s*data:/i', $value) !== 1) {
            return $value;
        }

        return preg_match('#^\s*data:image/(png|jpe?g|gif|webp|avif|bmp|svg\+xml)[;,]#i', $value) === 1 ? $value : null;
    }
}

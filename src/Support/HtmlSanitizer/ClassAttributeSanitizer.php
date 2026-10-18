<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps only the `class` tokens the rich editor emits (`alfakit-mention`,
 * `alfakit-merge-tag`, `language-php`), so stored content cannot borrow the panel's
 * utility classes to overlay the page (`fixed inset-0 z-50`).
 */
final class ClassAttributeSanitizer implements AttributeSanitizerInterface
{
    /**
     * @param  list<string>  $patterns  Regular expressions a class token must match.
     */
    public function __construct(
        private readonly array $patterns = ['/^alfakit-[a-z0-9-]+$/', '/^language-[\w+-]+$/'],
    ) {}

    public function getSupportedElements(): ?array
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function getSupportedAttributes(): array
    {
        return ['class'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $tokens = array_filter(
            preg_split('/\s+/', trim($value)) ?: [],
            fn (string $token): bool => $this->isAllowed($token),
        );

        return $tokens === [] ? null : implode(' ', $tokens);
    }

    private function isAllowed(string $token): bool
    {
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $token) === 1) {
                return true;
            }
        }

        return false;
    }
}

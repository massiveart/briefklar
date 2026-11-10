<?php

declare(strict_types=1);

namespace App\Explanation;

/**
 * Languages the app is offered in. The UI locale (route prefix) and the language of the
 * explanation are the same: an English page explains a German letter in English.
 *
 * Adding a language: add a case here, translations/*.<locale>.yaml and the locale in
 * config/packages/translation.yaml (enabled_locales).
 */
enum ExplanationLanguage: string
{
    case German = 'de';
    case English = 'en';

    /**
     * Name used in the instruction to the model.
     */
    public function englishName(): string
    {
        return match ($this) {
            self::German => 'German',
            self::English => 'English',
        };
    }

    /**
     * Name shown in the language switcher, in the language itself.
     */
    public function nativeName(): string
    {
        return match ($this) {
            self::German => 'Deutsch',
            self::English => 'English',
        };
    }

    /**
     * @return non-empty-list<string> the first one is the fallback
     */
    public static function locales(): array
    {
        return array_map(static fn (self $l): string => $l->value, self::cases());
    }
}

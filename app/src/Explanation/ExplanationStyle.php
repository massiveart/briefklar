<?php

declare(strict_types=1);

namespace App\Explanation;

/**
 * Prompt variant of the explanation. Each case maps to one agent in config/packages/ai.yaml.
 */
enum ExplanationStyle: string
{
    case Standard = 'standard';
    case EasyLanguage = 'easy_language';
}

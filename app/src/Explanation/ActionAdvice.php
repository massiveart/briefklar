<?php

declare(strict_types=1);

namespace App\Explanation;

/**
 * What the result page may say under "What do you have to do?".
 *
 * Models answer actionRequired and actions inconsistently (e.g. actionRequired false next to
 * "Pay 1,245.00 euros"). Telling someone "you do not have to do anything" about a letter that asks
 * for money is the worst possible mistake, so it is only said when every field agrees.
 */
enum ActionAdvice: string
{
    /** The model named concrete steps; they are shown whatever actionRequired says. */
    case Steps = 'steps';

    /** No steps, nothing required, no amount and no deadline. */
    case NothingToDo = 'nothing_to_do';

    /** No steps, but the letter asks for something, states an amount or a deadline. */
    case Unclear = 'unclear';

    public static function of(LetterExplanation $explanation): self
    {
        if ([] !== $explanation->actions) {
            return self::Steps;
        }

        if ($explanation->actionRequired || null !== $explanation->amount || null !== $explanation->deadline) {
            return self::Unclear;
        }

        return self::NothingToDo;
    }
}

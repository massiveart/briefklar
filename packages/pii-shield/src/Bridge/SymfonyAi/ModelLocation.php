<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Bridge\SymfonyAi;

/**
 * Where the detection model runs. Detection necessarily sends the *unredacted* text to the model,
 * so this decides whether personal data leaves your infrastructure.
 */
enum ModelLocation: string
{
    /** Same machine, e.g. Ollama on localhost. */
    case Local = 'local';

    /** Your own infrastructure, e.g. a GPU server running Ollama or vLLM. */
    case OnPremise = 'on_premise';

    /** A third-party provider (OpenAI, Anthropic, Mistral, ...). Needs a DPA; possibly a third-country transfer. */
    case External = 'external';
}

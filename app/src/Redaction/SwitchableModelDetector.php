<?php

declare(strict_types=1);

namespace App\Redaction;

use Briefklar\PiiShield\Detector\DetectorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds model-based detection (pii-shield ModelDetector, ADR 0006) to the regex detectors,
 * switched at runtime with PII_MODEL_DETECTION, so a fresh clone runs without a local model.
 *
 * The model always runs on the local Ollama (service app.pii.detection_model in services.yaml),
 * whichever provider explains the letter: detection is the one step that sees the unredacted text.
 * When enabled and the model fails, ModelDetector throws and nothing is sent (fail closed).
 */
#[Autoconfigure(tags: ['pii_shield.detector'])]
final readonly class SwitchableModelDetector implements DetectorInterface
{
    public function __construct(
        #[Autowire(service: 'app.pii.detection_model')]
        private DetectorInterface $model,
        #[Autowire('%env(bool:PII_MODEL_DETECTION)%')]
        private bool $enabled,
    ) {
    }

    public function detect(string $text): array
    {
        return $this->enabled ? $this->model->detect($text) : [];
    }
}

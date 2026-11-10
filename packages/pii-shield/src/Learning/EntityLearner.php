<?php

declare(strict_types=1);

namespace Briefklar\PiiShield\Learning;

use Briefklar\PiiShield\Detector\DetectorInterface;

/**
 * Teach pii-shield new entity types from examples ("mask things like this too").
 *
 * A pattern is derived from the examples unless one is given. Values without a structure
 * (plain words such as pet names) cannot become a safe pattern; they are learned as model-only
 * types and reach the model detector as few-shot examples.
 */
final class EntityLearner
{
    public function __construct(
        private readonly CustomEntityStoreInterface $store,
        private readonly PatternLearner $patterns = new PatternLearner(),
    ) {
    }

    /**
     * Learns and saves the type (replacing one with the same name).
     *
     * @param list<string> $examples
     * @param list<string> $counterExamples
     *
     * @throws LearningFailedException
     */
    public function learn(string $name, string $description, array $examples, array $counterExamples = [], ?string $pattern = null): LearningResult
    {
        $result = $this->preview($name, $description, $examples, $counterExamples, $pattern);
        $this->store->save($result->type);

        return $result;
    }

    /**
     * Same as learn(), without saving: for trying out examples.
     *
     * @param list<string> $examples
     * @param list<string> $counterExamples
     *
     * @throws LearningFailedException
     */
    public function preview(string $name, string $description, array $examples, array $counterExamples = [], ?string $pattern = null): LearningResult
    {
        $note = null;
        if (null === $pattern || '' === trim($pattern)) {
            try {
                $pattern = $this->patterns->learn($examples, $counterExamples);
            } catch (LearningFailedException $e) {
                if (PatternLearner::PLAIN_WORDS !== $e->getCode()) {
                    throw $e;
                }
                $pattern = null;
                $note = 'No pattern: plain words would match ordinary text. Only model-based detection finds this type.';
            }
        }

        return new LearningResult(new CustomEntityType($name, $description, $examples, $counterExamples, $pattern), $note);
    }

    public function forget(string $name): void
    {
        $this->store->remove($name);
    }

    /**
     * @return list<CustomEntityType>
     */
    public function types(): array
    {
        return $this->store->all();
    }

    /**
     * A detector for all learned patterns; picks up types learned later.
     */
    public function detector(): DetectorInterface
    {
        return new LearnedTypesDetector($this->store);
    }
}

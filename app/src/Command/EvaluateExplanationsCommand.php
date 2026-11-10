<?php

declare(strict_types=1);

namespace App\Command;

use App\Explanation\Evaluation\ExplanationRules;
use App\Explanation\ExplanationFailedException;
use App\Explanation\ExplanationLanguage;
use App\Explanation\ExplanationStyle;
use App\Explanation\LetterExplainer;
use App\Extraction\ExtractedText;
use App\Extraction\ExtractionMethod;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the synthetic fixture letters through the configured provider and model (AI_PLATFORM,
 * AI_MODEL) and checks every answer against ExplanationRules.
 *
 * Goes through the real agent pipeline, pii-shield included: the provider sees placeholders only.
 * Never use it with real letters; it reads fixtures/letters/*.txt only.
 */
#[AsCommand(name: 'app:evaluate-explanations', description: 'Checks the configured model against the prompt rules on the fixture letters')]
final readonly class EvaluateExplanationsCommand
{
    public function __construct(
        private LetterExplainer $explainer,
        private ExplanationRules $rules,
        #[Autowire('%kernel.project_dir%/../fixtures/letters')]
        private string $fixturesDir,
    ) {
    }

    /**
     * @param list<string> $fixtures
     */
    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Argument(description: 'Fixture names, e.g. 01-strafverfuegung (default: all)')]
        array $fixtures = [],
        #[Option(description: 'standard, easy_language or all')]
        string $style = 'all',
        #[Option(description: 'de, en or all')]
        string $language = 'all',
    ): int {
        $styles = 'all' === $style ? ExplanationStyle::cases() : [ExplanationStyle::from($style)];
        $languages = 'all' === $language ? ExplanationLanguage::cases() : [ExplanationLanguage::from($language)];
        $fixtures = [] !== $fixtures ? $fixtures : $this->allFixtures();

        $rows = [];
        $kept = 0;
        $applicable = 0;
        $failed = 0;
        foreach ($fixtures as $fixture) {
            $letter = $this->read($fixture);
            foreach ($styles as $s) {
                foreach ($languages as $l) {
                    $start = microtime(true);
                    try {
                        $result = $this->explainer->explain(new ExtractedText($letter, ExtractionMethod::PdfTextLayer), $s, $l);
                    } catch (ExplanationFailedException $e) {
                        ++$failed;
                        $rows[] = [$fixture, $s->value, $l->value, \sprintf('%.1f', microtime(true) - $start), '<error>'.$e->getMessage().'</error>'];
                        continue;
                    }
                    $seconds = microtime(true) - $start;

                    $broken = [];
                    foreach ($this->rules->check($result, $letter) as $rule => $ok) {
                        if (null === $ok) {
                            continue;
                        }
                        ++$applicable;
                        $ok ? ++$kept : $broken[] = $rule;
                    }
                    $rows[] = [$fixture, $s->value, $l->value, \sprintf('%.1f', $seconds), [] === $broken ? '<info>all kept</info>' : '<comment>'.implode(', ', $broken).'</comment>'];

                    if ($output->isVerbose()) {
                        $io->writeln((string) json_encode($result->explanation, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
                    }
                }
            }
        }

        $io->table(['Fixture', 'Style', 'Lang', 'Seconds', 'Broken rules'], $rows);
        $io->writeln(\sprintf('Rules kept: %d of %d. Failed calls: %d.', $kept, $applicable, $failed));

        return $kept === $applicable && 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function allFixtures(): array
    {
        $files = glob($this->fixturesDir.'/*.txt');

        return array_map(static fn (string $f): string => basename($f, '.txt'), false === $files ? [] : $files);
    }

    private function read(string $fixture): string
    {
        $path = $this->fixturesDir.'/'.basename($fixture).'.txt';
        $text = is_file($path) ? file_get_contents($path) : false;
        if (false === $text) {
            throw new \InvalidArgumentException(\sprintf('Fixture "%s" not found in %s.', $fixture, $this->fixturesDir));
        }

        return $text;
    }
}

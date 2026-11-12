<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs against ScriptedPlatform (canned answers), so it checks the command, not a model.
 */
final class EvaluateExplanationsCommandTest extends KernelTestCase
{
    public function testReportsAllRulesKeptForAGoodAnswer(): void
    {
        $tester = $this->evaluate(['fixtures' => ['01-strafverfuegung'], '--style' => 'standard', '--language' => 'de']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('all kept', $tester->getDisplay());
        self::assertStringContainsString('Rules kept: 7 of 7. Failed calls: 0.', $tester->getDisplay());
    }

    public function testReportsBrokenRulesAndFails(): void
    {
        // The canned answer for letter 02 states a deadline that is not in the letter and invents ⟦IBAN_1⟧.
        $tester = $this->evaluate(['fixtures' => ['02-finanzamt-vorschreibung'], '--style' => 'standard', '--language' => 'de']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('deadline verbatim, placeholders restored', $tester->getDisplay());
    }

    public function testRunsEveryStyleAndLanguageByDefault(): void
    {
        $tester = $this->evaluate(['fixtures' => ['01-strafverfuegung']]);

        self::assertSame(4, substr_count($tester->getDisplay(), '01-strafverfuegung'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function evaluate(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:evaluate-explanations'));
        $tester->execute($input);

        return $tester;
    }
}

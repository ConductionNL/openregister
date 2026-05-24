<?php

/**
 * Unit tests for RetentionEvaluator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4-3
 */

declare(strict_types=1);

namespace Unit\Service\Archival;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Stringable;

/**
 * Minimal in-test capturing logger.
 *
 * Replaces `Psr\Log\Test\CapturingLogger` which is not always available depending
 * on the psr/log version bundled with the project.
 */
final class CapturingLogger extends AbstractLogger
{

    public array $records = [];

    public function log($level, string|Stringable $message, array $context=[]): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }//end log()

    public function hasWarningThatContains(string $needle): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === 'warning' && str_contains($record['message'], $needle)) {
                return true;
            }
        }

        return false;
    }//end hasWarningThatContains()
}//end class

final class RetentionEvaluatorTest extends TestCase
{
    private function makeEvaluator(?CapturingLogger $logger=null): RetentionEvaluator
    {
        return new RetentionEvaluator(logger: ($logger ?? new NullLogger()));
    }//end makeEvaluator()

    private function created(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }//end created()

    public function testFirstMatchingRuleWins(): void
    {
        $evaluator = $this->makeEvaluator();
        $result    = $evaluator->evaluate(
            annotation: [
                'retention' => [
                    'default' => 'P30D',
                    'rules'   => [
                        ['condition' => 'statusCode < 400', 'retention' => 'PT1H'],
                        ['condition' => 'statusCode >= 400', 'retention' => 'P30D'],
                    ],
                ],
            ],
            row: ['statusCode' => 200],
            createdAt: $this->created()
        );

        self::assertSame('PT1H', $result['effectiveRetention']);
        self::assertSame(0, $result['matchedRule']);
        self::assertSame('2026-01-01T01:00:00+00:00', $result['expiresAt']);
    }//end testFirstMatchingRuleWins()

    public function testFallsBackToDefaultWhenNoRuleMatches(): void
    {
        $evaluator = $this->makeEvaluator();
        $result    = $evaluator->evaluate(
            annotation: [
                'retention' => [
                    'default' => 'P30D',
                    'rules'   => [
                        ['condition' => 'statusCode < 400', 'retention' => 'PT1H'],
                    ],
                ],
            ],
            row: ['statusCode' => 500],
            createdAt: $this->created()
        );

        self::assertSame('P30D', $result['effectiveRetention']);
        self::assertNull($result['matchedRule']);
        self::assertSame('2026-01-31T00:00:00+00:00', $result['expiresAt']);
    }//end testFallsBackToDefaultWhenNoRuleMatches()

    public function testSecondRuleMatchesWhenFirstDoesNot(): void
    {
        $evaluator = $this->makeEvaluator();
        $result    = $evaluator->evaluate(
            annotation: [
                'retention' => [
                    'default' => 'P30D',
                    'rules'   => [
                        ['condition' => 'statusCode < 400', 'retention' => 'PT1H'],
                        ['condition' => 'statusCode >= 400', 'retention' => 'P7D'],
                    ],
                ],
            ],
            row: ['statusCode' => 500],
            createdAt: $this->created()
        );

        self::assertSame('P7D', $result['effectiveRetention']);
        self::assertSame(1, $result['matchedRule']);
    }//end testSecondRuleMatchesWhenFirstDoesNot()

    public function testMalformedConditionIsLoggedAndSkippedNotCrashing(): void
    {
        $logger    = new CapturingLogger();
        $evaluator = $this->makeEvaluator($logger);

        $result = $evaluator->evaluate(
            annotation: [
                'retention' => [
                    'default' => 'P30D',
                    'rules'   => [
                        ['condition' => 'this is not a condition', 'retention' => 'PT1H'],
                        ['condition' => 'statusCode < 400', 'retention' => 'P1D'],
                    ],
                ],
            ],
            row: ['statusCode' => 200],
            createdAt: $this->created()
        );

        // Falls through to the second rule (which is well-formed and matches).
        self::assertSame('P1D', $result['effectiveRetention']);
        self::assertSame(1, $result['matchedRule']);
        self::assertTrue($logger->hasWarningThatContains('Malformed retention condition'));
    }//end testMalformedConditionIsLoggedAndSkippedNotCrashing()

    public function testAnnotationWithoutRetentionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeEvaluator()->evaluate(
            annotation: [],
            row: [],
            createdAt: $this->created()
        );
    }//end testAnnotationWithoutRetentionThrows()

    public function testAnnotationWithoutDefaultThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeEvaluator()->evaluate(
            annotation: ['retention' => ['rules' => []]],
            row: [],
            createdAt: $this->created()
        );
    }//end testAnnotationWithoutDefaultThrows()

    public function testEmptyRulesFallsBackToDefault(): void
    {
        $result = $this->makeEvaluator()->evaluate(
            annotation: ['retention' => ['default' => 'PT1H']],
            row: [],
            createdAt: $this->created()
        );

        self::assertSame('PT1H', $result['effectiveRetention']);
        self::assertNull($result['matchedRule']);
        self::assertSame('2026-01-01T01:00:00+00:00', $result['expiresAt']);
    }//end testEmptyRulesFallsBackToDefault()
}//end class

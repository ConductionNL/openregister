<?php

declare(strict_types=1);

/**
 * RetentionEvaluator Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4.3
 */

namespace Unit\Service\Archival;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Archival\RetentionConditionEvaluator;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RetentionEvaluator.
 */
class RetentionEvaluatorTest extends TestCase
{

    private RetentionEvaluator              $evaluator;
    private RetentionConditionEvaluator&MockObject $conditionEvaluator;
    private LoggerInterface&MockObject      $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->conditionEvaluator = $this->createMock(RetentionConditionEvaluator::class);
        $this->evaluator          = new RetentionEvaluator(
            conditionEvaluator: $this->conditionEvaluator,
            logger: $this->logger
        );
    }

    /**
     * First matching rule wins; returns its retention duration and index.
     */
    public function testFirstMatchingRuleWins(): void
    {
        $annotation = [
            'retention' => [
                'default' => 'P30D',
                'rules'   => [
                    ['condition' => 'statusCode < 400', 'retention' => 'PT1H'],
                    ['condition' => 'statusCode >= 400', 'retention' => 'P30D'],
                ],
            ],
        ];

        $this->conditionEvaluator
            ->expects($this->once())
            ->method('evaluate')
            ->with('statusCode < 400', ['statusCode' => 200])
            ->willReturn(true);

        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $result    = $this->evaluator->evaluate(
            annotation: $annotation,
            row: ['statusCode' => 200],
            createdAt: $createdAt
        );

        $this->assertSame('PT1H', $result['effectiveRetention']);
        $this->assertSame(0, $result['matchedRule']);
        $this->assertSame('2026-01-01T01:00:00+00:00', $result['expiresAt']);
    }

    /**
     * Falls back to default when no rule matches; matchedRule is null.
     */
    public function testFallsBackToDefaultWhenNoMatch(): void
    {
        $annotation = [
            'retention' => [
                'default' => 'P30D',
                'rules'   => [
                    ['condition' => 'statusCode < 400', 'retention' => 'PT1H'],
                ],
            ],
        ];

        $this->conditionEvaluator
            ->expects($this->once())
            ->method('evaluate')
            ->with('statusCode < 400', ['statusCode' => 500])
            ->willReturn(false);

        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $result    = $this->evaluator->evaluate(
            annotation: $annotation,
            row: ['statusCode' => 500],
            createdAt: $createdAt
        );

        $this->assertSame('P30D', $result['effectiveRetention']);
        $this->assertNull($result['matchedRule']);
        $this->assertSame('2026-01-31T00:00:00+00:00', $result['expiresAt']);
    }

    /**
     * Annotation with no rules uses default.
     */
    public function testNoRulesUsesDefault(): void
    {
        $annotation = [
            'retention' => ['default' => 'P7D'],
        ];

        $this->conditionEvaluator->expects($this->never())->method('evaluate');

        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $result    = $this->evaluator->evaluate(
            annotation: $annotation,
            row: [],
            createdAt: $createdAt
        );

        $this->assertSame('P7D', $result['effectiveRetention']);
        $this->assertNull($result['matchedRule']);
    }

    /**
     * Malformed condition is caught (logged) and skipped — does not crash the sweep.
     */
    public function testMalformedConditionIsSkippedNotCrashing(): void
    {
        $annotation = [
            'retention' => [
                'default' => 'P30D',
                'rules'   => [
                    ['condition' => 'bad condition', 'retention' => 'PT1H'],
                ],
            ],
        ];

        $this->conditionEvaluator
            ->expects($this->once())
            ->method('evaluate')
            ->willThrowException(new InvalidArgumentException('parse error'));

        $this->logger->expects($this->once())->method('warning');

        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $result    = $this->evaluator->evaluate(
            annotation: $annotation,
            row: [],
            createdAt: $createdAt
        );

        // Malformed rule skipped → falls back to default.
        $this->assertSame('P30D', $result['effectiveRetention']);
        $this->assertNull($result['matchedRule']);
    }
}//end class

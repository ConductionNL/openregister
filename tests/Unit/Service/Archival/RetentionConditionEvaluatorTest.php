<?php

declare(strict_types=1);

/**
 * RetentionConditionEvaluator Unit Tests
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

use InvalidArgumentException;
use OCA\OpenRegister\Service\Archival\RetentionConditionEvaluator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RetentionConditionEvaluator.
 */
class RetentionConditionEvaluatorTest extends TestCase
{

    private RetentionConditionEvaluator  $evaluator;
    private LoggerInterface&MockObject   $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->evaluator = new RetentionConditionEvaluator(logger: $this->logger);
    }

    /**
     * Numeric less-than: 200 < 400 → true.
     */
    public function testNumericLessThanTrue(): void
    {
        $result = $this->evaluator->evaluate(
            condition: 'statusCode < 400',
            row: ['statusCode' => 200]
        );
        $this->assertTrue($result);
    }

    /**
     * Numeric less-than: 200 >= 400 → false.
     */
    public function testNumericGreaterOrEqualFalse(): void
    {
        $result = $this->evaluator->evaluate(
            condition: 'statusCode >= 400',
            row: ['statusCode' => 200]
        );
        $this->assertFalse($result);
    }

    /**
     * String equality with single-quoted literal.
     */
    public function testStringEqualityTrue(): void
    {
        $result = $this->evaluator->evaluate(
            condition: "status == 'success'",
            row: ['status' => 'success']
        );
        $this->assertTrue($result);
    }

    /**
     * Missing field evaluates to false without throwing.
     */
    public function testMissingFieldReturnsFalse(): void
    {
        $result = $this->evaluator->evaluate(
            condition: 'statusCode < 400',
            row: ['foo' => 'bar']
        );
        $this->assertFalse($result);
    }

    /**
     * Malformed condition throws InvalidArgumentException.
     */
    public function testMalformedConditionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->evaluator->evaluate(
            condition: 'statusCode 400',
            row: ['statusCode' => 200]
        );
    }

    /**
     * Boolean literal comparison.
     */
    public function testBooleanLiteralTrue(): void
    {
        $result = $this->evaluator->evaluate(
            condition: 'archived == true',
            row: ['archived' => true]
        );
        $this->assertTrue($result);
    }

    /**
     * Null literal comparison.
     */
    public function testNullLiteralFalse(): void
    {
        $result = $this->evaluator->evaluate(
            condition: 'deletedAt == null',
            row: ['deletedAt' => 'some-date']
        );
        $this->assertFalse($result);
    }
}//end class

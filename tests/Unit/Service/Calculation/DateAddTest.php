<?php

/**
 * OpenRegister DateAddTest
 *
 * Unit tests for the `dateAdd` primitive in CalculationEvaluator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dateAdd calculation primitive.
 *
 * Covers: amount+unit (days/weeks/months/years), ISO-8601 duration form,
 * time preservation, null/empty/unparseable date, bad unit, invalid duration,
 * prop references, missing keys, and validator integration.
 */
class DateAddTest extends TestCase
{

    private CalculationEvaluator $eval;

    /** @var IUserSession&MockObject */
    private $userSession;


    /**
     * Set up the evaluator with a stub session.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $resolver   = new PlaceholderResolver($this->userSession);
        $this->eval = new CalculationEvaluator($resolver);

    }//end setUp()


    // -----------------------------------------------------------------------
    // amount + unit
    // -----------------------------------------------------------------------

    /**
     * Add 30 days to a plain date.
     *
     * @return void
     */
    public function testAddDays(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => '2026-01-01', 'amount' => 30, 'unit' => 'days']]);
        $this->assertSame('2026-01-31', $result);

    }//end testAddDays()


    /**
     * Add 2 weeks (folded to 14 days).
     *
     * @return void
     */
    public function testAddWeeks(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => '2026-01-01', 'amount' => 2, 'unit' => 'weeks']]);
        $this->assertSame('2026-01-15', $result);

    }//end testAddWeeks()


    /**
     * Add 3 months.
     *
     * @return void
     */
    public function testAddMonths(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => '2026-01-15', 'amount' => 3, 'unit' => 'months']]);
        $this->assertSame('2026-04-15', $result);

    }//end testAddMonths()


    /**
     * Add 1 year.
     *
     * @return void
     */
    public function testAddYears(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => '2026-06-15', 'amount' => 1, 'unit' => 'years']]);
        $this->assertSame('2027-06-15', $result);

    }//end testAddYears()


    /**
     * The amount may itself be an object property reference.
     *
     * @return void
     */
    public function testAmountFromProp(): void
    {
        $object = ['startDate' => '2026-01-01', 'term' => 10];
        $result = $this->eval->evaluate(
            $object,
            ['dateAdd' => ['date' => ['prop' => 'startDate'], 'amount' => ['prop' => 'term'], 'unit' => 'days']]
        );
        $this->assertSame('2026-01-11', $result);

    }//end testAmountFromProp()


    // -----------------------------------------------------------------------
    // ISO-8601 duration
    // -----------------------------------------------------------------------

    /**
     * Add an ISO-8601 duration "P30D".
     *
     * @return void
     */
    public function testAddIsoDuration(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => '2026-01-01', 'duration' => 'P30D']]);
        $this->assertSame('2026-01-31', $result);

    }//end testAddIsoDuration()


    /**
     * A composite ISO-8601 duration "P1Y6M".
     *
     * @return void
     */
    public function testAddCompositeIsoDuration(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => '2026-01-01', 'duration' => 'P1Y6M']]);
        $this->assertSame('2027-07-01', $result);

    }//end testAddCompositeIsoDuration()


    /**
     * The duration may be resolved from a property (the caseType pattern).
     *
     * @return void
     */
    public function testDurationFromProp(): void
    {
        $object = ['@ref' => ['caseType' => ['processingDeadline' => 'P30D']], 'startDate' => '2026-01-01'];
        $result = $this->eval->evaluate(
            $object,
            ['dateAdd' => ['date' => ['prop' => 'startDate'], 'duration' => ['prop' => '@ref.caseType.processingDeadline']]]
        );
        $this->assertSame('2026-01-31', $result);

    }//end testDurationFromProp()


    // -----------------------------------------------------------------------
    // Time preservation
    // -----------------------------------------------------------------------

    /**
     * A date-time input preserves its time component in the result.
     *
     * @return void
     */
    public function testPreservesTime(): void
    {
        $result = $this->eval->evaluate(
            [],
            ['dateAdd' => ['date' => '2026-01-01T09:30:00+00:00', 'amount' => 1, 'unit' => 'days']]
        );
        $this->assertSame('2026-01-02T09:30:00+00:00', $result);

    }//end testPreservesTime()


    // -----------------------------------------------------------------------
    // Null / error propagation
    // -----------------------------------------------------------------------

    /**
     * Null date → returns null (no throw).
     *
     * @return void
     */
    public function testNullDateReturnsNull(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => null, 'amount' => 30, 'unit' => 'days']]);
        $this->assertNull($result);

    }//end testNullDateReturnsNull()


    /**
     * Empty date string → returns null.
     *
     * @return void
     */
    public function testEmptyDateReturnsNull(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => '', 'amount' => 30, 'unit' => 'days']]);
        $this->assertNull($result);

    }//end testEmptyDateReturnsNull()


    /**
     * Unparseable date → returns null.
     *
     * @return void
     */
    public function testUnparseableDateReturnsNull(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => 'not-a-date', 'amount' => 30, 'unit' => 'days']]);
        $this->assertNull($result);

    }//end testUnparseableDateReturnsNull()


    /**
     * Bad unit → returns null (no interval can be built).
     *
     * @return void
     */
    public function testBadUnitReturnsNull(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => '2026-01-01', 'amount' => 5, 'unit' => 'fortnights']]);
        $this->assertNull($result);

    }//end testBadUnitReturnsNull()


    /**
     * Invalid ISO duration string → returns null.
     *
     * @return void
     */
    public function testInvalidDurationReturnsNull(): void
    {
        $result = $this->eval->evaluate([], ['dateAdd' => ['date' => '2026-01-01', 'duration' => 'banana']]);
        $this->assertNull($result);

    }//end testInvalidDurationReturnsNull()


    /**
     * Missing date key throws.
     *
     * @return void
     */
    public function testMissingDateThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->eval->evaluate([], ['dateAdd' => ['amount' => 30, 'unit' => 'days']]);

    }//end testMissingDateThrows()


    /**
     * Neither amount+unit nor duration supplied → throws.
     *
     * @return void
     */
    public function testMissingDurationAndAmountThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->eval->evaluate([], ['dateAdd' => ['date' => '2026-01-01']]);

    }//end testMissingDurationAndAmountThrows()


    // -----------------------------------------------------------------------
    // Validator integration
    // -----------------------------------------------------------------------

    /**
     * Valid dateAdd annotation passes validation.
     *
     * @return void
     */
    public function testValidatorAcceptsDateAdd(): void
    {
        $v      = new CalculationAnnotationValidator();
        $errors = $v->validate([
            'x-openregister-calculations' => [
                'deadline' => [
                    'type'       => 'date',
                    'materialise' => true,
                    'expression' => [
                        'dateAdd' => [
                            'date'   => ['prop' => 'startDate'],
                            'amount' => 30,
                            'unit'   => 'days',
                        ],
                    ],
                ],
            ],
            'properties' => [
                'startDate' => ['type' => 'string'],
            ],
        ]);
        $this->assertSame([], $errors);

    }//end testValidatorAcceptsDateAdd()


}//end class

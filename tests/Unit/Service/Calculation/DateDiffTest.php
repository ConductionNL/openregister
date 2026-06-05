<?php

/**
 * OpenRegister DateDiffTest
 *
 * Unit tests for the `dateDiff` primitive in CalculationEvaluator.
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
 * Tests for the dateDiff calculation primitive.
 *
 * Covers: positive diff, negative diff, all 7 units, @self field references,
 * "now" sentinel, null propagation, invalid unit, missing keys, leap years,
 * end-of-month month diffs, DST boundary (days/seconds), and validator integration.
 */
class DateDiffTest extends TestCase
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
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Build a dateDiff expression.
     *
     * @param mixed  $from From operand (literal / "now").
     * @param mixed  $to   To operand (literal / "now").
     * @param string $unit Unit string.
     *
     * @return array<string, mixed>
     */
    private function expr(mixed $from, mixed $to, string $unit): array
    {
        return ['dateDiff' => ['from' => $from, 'to' => $to, 'unit' => $unit]];

    }//end expr()


    // -----------------------------------------------------------------------
    // Unit: seconds
    // -----------------------------------------------------------------------

    /**
     * Test positive seconds difference.
     *
     * @return void
     */
    public function testPositiveSeconds(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01T00:00:00Z', '2026-01-01T00:01:00Z', 'seconds'));
        $this->assertSame(60, $result);

    }//end testPositiveSeconds()


    /**
     * Test negative seconds difference.
     *
     * @return void
     */
    public function testNegativeSeconds(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01T00:01:00Z', '2026-01-01T00:00:00Z', 'seconds'));
        $this->assertSame(-60, $result);

    }//end testNegativeSeconds()


    // -----------------------------------------------------------------------
    // Unit: minutes
    // -----------------------------------------------------------------------

    /**
     * Test positive minutes difference.
     *
     * @return void
     */
    public function testPositiveMinutes(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01T00:00:00Z', '2026-01-01T02:30:00Z', 'minutes'));
        $this->assertSame(150, $result);

    }//end testPositiveMinutes()


    /**
     * Test negative minutes difference.
     *
     * @return void
     */
    public function testNegativeMinutes(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01T02:30:00Z', '2026-01-01T00:00:00Z', 'minutes'));
        $this->assertSame(-150, $result);

    }//end testNegativeMinutes()


    // -----------------------------------------------------------------------
    // Unit: hours
    // -----------------------------------------------------------------------

    /**
     * Test positive hours difference.
     *
     * @return void
     */
    public function testPositiveHours(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z', 'hours'));
        $this->assertSame(24, $result);

    }//end testPositiveHours()


    /**
     * Test negative hours difference.
     *
     * @return void
     */
    public function testNegativeHours(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-02T00:00:00Z', '2026-01-01T00:00:00Z', 'hours'));
        $this->assertSame(-24, $result);

    }//end testNegativeHours()


    // -----------------------------------------------------------------------
    // Unit: days
    // -----------------------------------------------------------------------

    /**
     * Test positive days difference.
     *
     * @return void
     */
    public function testPositiveDays(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01', '2026-01-11', 'days'));
        $this->assertSame(10, $result);

    }//end testPositiveDays()


    /**
     * Test negative days difference.
     *
     * @return void
     */
    public function testNegativeDays(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-11', '2026-01-01', 'days'));
        $this->assertSame(-10, $result);

    }//end testNegativeDays()


    /**
     * Test days difference of zero when from == to.
     *
     * @return void
     */
    public function testSameDateDaysIsZero(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-03-15', '2026-03-15', 'days'));
        $this->assertSame(0, $result);

    }//end testSameDateDaysIsZero()


    /**
     * Leap year: days from 2024-02-28 to 2024-03-01 is 2 (through Feb 29).
     *
     * @return void
     */
    public function testLeapYearDays(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2024-02-28', '2024-03-01', 'days'));
        $this->assertSame(2, $result);

    }//end testLeapYearDays()


    /**
     * Non-leap year: days from 2023-02-28 to 2023-03-01 is 1.
     *
     * @return void
     */
    public function testNonLeapYearDays(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2023-02-28', '2023-03-01', 'days'));
        $this->assertSame(1, $result);

    }//end testNonLeapYearDays()


    // -----------------------------------------------------------------------
    // Unit: weeks
    // -----------------------------------------------------------------------

    /**
     * Test positive weeks difference.
     *
     * @return void
     */
    public function testPositiveWeeks(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01', '2026-01-15', 'weeks'));
        $this->assertSame(2, $result);

    }//end testPositiveWeeks()


    /**
     * Test negative weeks difference.
     *
     * @return void
     */
    public function testNegativeWeeks(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-15', '2026-01-01', 'weeks'));
        $this->assertSame(-2, $result);

    }//end testNegativeWeeks()


    /**
     * Partial week (10 days) truncates to 1 whole week.
     *
     * @return void
     */
    public function testPartialWeekTruncates(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01', '2026-01-11', 'weeks'));
        $this->assertSame(1, $result);

    }//end testPartialWeekTruncates()


    // -----------------------------------------------------------------------
    // Unit: months
    // -----------------------------------------------------------------------

    /**
     * Test positive months difference.
     *
     * @return void
     */
    public function testPositiveMonths(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01', '2026-04-01', 'months'));
        $this->assertSame(3, $result);

    }//end testPositiveMonths()


    /**
     * Test negative months difference.
     *
     * @return void
     */
    public function testNegativeMonths(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-04-01', '2026-01-01', 'months'));
        $this->assertSame(-3, $result);

    }//end testNegativeMonths()


    /**
     * End-of-month: Jan 31 → Feb 28 is 0 months (day 28 < day 31, DateInterval rounds down).
     *
     * @return void
     */
    public function testEndOfMonthJanToFeb(): void
    {
        // PHP DateInterval: 2026-01-31 diff 2026-02-28 → 0 months, 28 days.
        $result = $this->eval->evaluate([], $this->expr('2026-01-31', '2026-02-28', 'months'));
        $this->assertSame(0, $result);

    }//end testEndOfMonthJanToFeb()


    /**
     * End-of-month: Jan 31 → Mar 31 is exactly 2 months.
     *
     * @return void
     */
    public function testEndOfMonthJanToMar(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-31', '2026-03-31', 'months'));
        $this->assertSame(2, $result);

    }//end testEndOfMonthJanToMar()


    /**
     * Cross-year: Dec → Jan is 1 month.
     *
     * @return void
     */
    public function testCrossYearOneMonth(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2025-12-01', '2026-01-01', 'months'));
        $this->assertSame(1, $result);

    }//end testCrossYearOneMonth()


    // -----------------------------------------------------------------------
    // Unit: years
    // -----------------------------------------------------------------------

    /**
     * Test positive years difference.
     *
     * @return void
     */
    public function testPositiveYears(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2020-06-15', '2026-06-15', 'years'));
        $this->assertSame(6, $result);

    }//end testPositiveYears()


    /**
     * Test negative years difference.
     *
     * @return void
     */
    public function testNegativeYears(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-06-15', '2020-06-15', 'years'));
        $this->assertSame(-6, $result);

    }//end testNegativeYears()


    /**
     * Leap year boundary: Feb 29 → Feb 28 next year = 0 years (not yet a full year).
     *
     * @return void
     */
    public function testLeapYearBoundaryYears(): void
    {
        // 2024-02-29 to 2025-02-28: DateInterval y=0, m=11, d=30.
        $result = $this->eval->evaluate([], $this->expr('2024-02-29', '2025-02-28', 'years'));
        $this->assertSame(0, $result);

    }//end testLeapYearBoundaryYears()


    /**
     * Leap year boundary: Feb 29 → Mar 1 next year is exactly 1 year.
     *
     * @return void
     */
    public function testLeapYearBoundaryFullYear(): void
    {
        // 2024-02-29 to 2025-03-01: DateInterval y=1, m=0, d=1.
        $result = $this->eval->evaluate([], $this->expr('2024-02-29', '2025-03-01', 'years'));
        $this->assertSame(1, $result);

    }//end testLeapYearBoundaryFullYear()


    // -----------------------------------------------------------------------
    // DST boundary (days/seconds)
    // -----------------------------------------------------------------------

    /**
     * DST spring-forward (Europe/Amsterdam): 2026-03-29 01:00 → 03:00.
     * The 24h wall-clock day has only 23 elapsed seconds-hours.
     * dateDiff in 'days' uses timestamp delta / 86400, so this day = 0 days.
     *
     * @return void
     */
    public function testDstSpringForwardDays(): void
    {
        // Use explicit UTC timestamps to avoid TZ dependency in CI.
        // Simulate: from = midnight UTC, to = midnight UTC + 23h (82800s).
        $result = $this->eval->evaluate(
            [],
            $this->expr('2026-03-29T00:00:00+00:00', '2026-03-29T23:00:00+00:00', 'days')
        );
        // 82800 / 86400 = 0 whole days.
        $this->assertSame(0, $result);

    }//end testDstSpringForwardDays()


    /**
     * Two full UTC days → 2 days regardless of DST.
     *
     * @return void
     */
    public function testTwoFullUtcDays(): void
    {
        $result = $this->eval->evaluate(
            [],
            $this->expr('2026-03-28T00:00:00+00:00', '2026-03-30T00:00:00+00:00', 'days')
        );
        $this->assertSame(2, $result);

    }//end testTwoFullUtcDays()


    // -----------------------------------------------------------------------
    // @self field reference
    // -----------------------------------------------------------------------

    /**
     * Test that @self.dueDate resolves via the object payload.
     *
     * @return void
     */
    public function testAtSelfFieldReference(): void
    {
        $object = ['@self' => ['dueDate' => '2026-06-01']];
        $result = $this->eval->evaluate(
            $object,
            ['dateDiff' => ['from' => '2026-05-01', 'to' => ['prop' => '@self.dueDate'], 'unit' => 'days']]
        );
        $this->assertSame(31, $result);

    }//end testAtSelfFieldReference()


    /**
     * Test object property reference for the 'to' operand.
     *
     * @return void
     */
    public function testPropReference(): void
    {
        $object = ['dueDate' => '2026-06-01'];
        $result = $this->eval->evaluate(
            $object,
            ['dateDiff' => ['from' => '2026-05-01', 'to' => ['prop' => 'dueDate'], 'unit' => 'days']]
        );
        $this->assertSame(31, $result);

    }//end testPropReference()


    // -----------------------------------------------------------------------
    // "now" sentinel
    // -----------------------------------------------------------------------

    /**
     * dateDiff with "now" as from: result is >= 0 for a future to date.
     *
     * We use a date well in the future to ensure it's always after "now".
     *
     * @return void
     */
    public function testNowSentinel(): void
    {
        $result = $this->eval->evaluate([], $this->expr('now', '2099-12-31', 'days'));
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);

    }//end testNowSentinel()


    /**
     * dateDiff with "now" as to: result is <= 0 for a future from date.
     *
     * @return void
     */
    public function testNowSentinelAsTo(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2099-12-31', 'now', 'days'));
        $this->assertIsInt($result);
        $this->assertLessThan(0, $result);

    }//end testNowSentinelAsTo()


    // -----------------------------------------------------------------------
    // Null propagation
    // -----------------------------------------------------------------------

    /**
     * Null from → returns null.
     *
     * @return void
     */
    public function testNullFromReturnsNull(): void
    {
        $result = $this->eval->evaluate([], $this->expr(null, '2026-01-01', 'days'));
        $this->assertNull($result);

    }//end testNullFromReturnsNull()


    /**
     * Null to → returns null.
     *
     * @return void
     */
    public function testNullToReturnsNull(): void
    {
        $result = $this->eval->evaluate([], $this->expr('2026-01-01', null, 'days'));
        $this->assertNull($result);

    }//end testNullToReturnsNull()


    /**
     * Invalid date string → returns null rather than throwing.
     *
     * @return void
     */
    public function testUnparseableDateReturnsNull(): void
    {
        $result = $this->eval->evaluate([], $this->expr('not-a-date', '2026-01-01', 'days'));
        $this->assertNull($result);

    }//end testUnparseableDateReturnsNull()


    /**
     * Missing object property (resolves to null) → returns null.
     *
     * @return void
     */
    public function testMissingPropReturnsNull(): void
    {
        $result = $this->eval->evaluate(
            [],
            ['dateDiff' => ['from' => '2026-01-01', 'to' => ['prop' => 'nonexistent'], 'unit' => 'days']]
        );
        $this->assertNull($result);

    }//end testMissingPropReturnsNull()


    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    /**
     * Invalid unit string throws EvaluationException.
     *
     * @return void
     */
    public function testInvalidUnitThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessageMatches('/invalid/i');
        $this->eval->evaluate([], $this->expr('2026-01-01', '2026-01-10', 'fortnights'));

    }//end testInvalidUnitThrows()


    /**
     * Non-array args throws EvaluationException.
     *
     * @return void
     */
    public function testNonArrayArgsThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->eval->evaluate([], ['dateDiff' => 'invalid']);

    }//end testNonArrayArgsThrows()


    /**
     * Missing 'from' key throws EvaluationException.
     *
     * @return void
     */
    public function testMissingFromKeyThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->eval->evaluate([], ['dateDiff' => ['to' => '2026-01-10', 'unit' => 'days']]);

    }//end testMissingFromKeyThrows()


    /**
     * Missing 'to' key throws EvaluationException.
     *
     * @return void
     */
    public function testMissingToKeyThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->eval->evaluate([], ['dateDiff' => ['from' => '2026-01-01', 'unit' => 'days']]);

    }//end testMissingToKeyThrows()


    /**
     * Missing 'unit' key throws EvaluationException.
     *
     * @return void
     */
    public function testMissingUnitKeyThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->eval->evaluate([], ['dateDiff' => ['from' => '2026-01-01', 'to' => '2026-01-10']]);

    }//end testMissingUnitKeyThrows()


    // -----------------------------------------------------------------------
    // Annotation validator integration
    // -----------------------------------------------------------------------

    /**
     * Valid dateDiff annotation passes validation.
     *
     * @return void
     */
    public function testValidatorAcceptsValidDateDiff(): void
    {
        $v      = new CalculationAnnotationValidator();
        $errors = $v->validate([
            'x-openregister-calculations' => [
                'daysRemaining' => [
                    'type'       => 'integer',
                    'expression' => [
                        'dateDiff' => [
                            'from' => 'now',
                            'to'   => ['prop' => 'dueDate'],
                            'unit' => 'days',
                        ],
                    ],
                ],
            ],
            'properties' => [
                'dueDate' => ['type' => 'string'],
            ],
        ]);
        $this->assertSame([], $errors);

    }//end testValidatorAcceptsValidDateDiff()


    /**
     * Invalid unit in annotation produces a validation error.
     *
     * @return void
     */
    public function testValidatorRejectsInvalidUnit(): void
    {
        $v      = new CalculationAnnotationValidator();
        $errors = $v->validate([
            'x-openregister-calculations' => [
                'bad' => [
                    'type'       => 'integer',
                    'expression' => [
                        'dateDiff' => [
                            'from' => 'now',
                            'to'   => '2026-12-31',
                            'unit' => 'fortnights',
                        ],
                    ],
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('calculation-dateDiff-invalid-unit', $codes);

    }//end testValidatorRejectsInvalidUnit()


    /**
     * Missing keys in dateDiff annotation produces a validation error.
     *
     * @return void
     */
    public function testValidatorRejectsMissingKeys(): void
    {
        $v      = new CalculationAnnotationValidator();
        $errors = $v->validate([
            'x-openregister-calculations' => [
                'bad' => [
                    'type'       => 'integer',
                    'expression' => [
                        'dateDiff' => ['from' => 'now', 'to' => '2026-12-31'],
                        // missing 'unit'
                    ],
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('calculation-dateDiff-missing-keys', $codes);

    }//end testValidatorRejectsMissingKeys()


    /**
     * Unknown prop reference in dateDiff 'to' is caught by validator.
     *
     * @return void
     */
    public function testValidatorCatchesUnknownPropInTo(): void
    {
        $v      = new CalculationAnnotationValidator();
        $errors = $v->validate([
            'x-openregister-calculations' => [
                'daysRemaining' => [
                    'type'       => 'integer',
                    'expression' => [
                        'dateDiff' => [
                            'from' => 'now',
                            'to'   => ['prop' => 'nonExistentField'],
                            'unit' => 'days',
                        ],
                    ],
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('calculation-prop-unknown', $codes);

    }//end testValidatorCatchesUnknownPropInTo()


}//end class

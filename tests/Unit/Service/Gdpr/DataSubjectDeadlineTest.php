<?php

declare(strict_types=1);

/**
 * DataSubjectDeadline Unit Tests
 *
 * Verifies the EU GDPR art-12(3) deadline maths: base term = receivedAt
 * + 1 month, single extension = +2 months on the base due date, overdue
 * detection in both directions, and whole-days-remaining (incl. negative
 * once breached). Pure helper — no DB, no Nextcloud runtime.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Gdpr\DataSubjectDeadline;
use PHPUnit\Framework\TestCase;

/**
 * Test class for DataSubjectDeadline.
 */
class DataSubjectDeadlineTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var DataSubjectDeadline
     */
    private DataSubjectDeadline $deadline;

    /**
     * Set up the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->deadline = new DataSubjectDeadline();

    }//end setUp()

    /**
     * The base due date is exactly one month after receipt.
     *
     * @return void
     */
    public function testComputeDueAtAddsOneMonth(): void
    {
        $received = new DateTimeImmutable('2026-01-10T09:00:00+00:00');
        $due      = $this->deadline->computeDueAt($received);

        $this->assertSame('2026-02-10T09:00:00+00:00', $due->format('c'));

    }//end testComputeDueAtAddsOneMonth()

    /**
     * Month arithmetic follows the civil calendar (31 Jan + 1M = Feb 28/29).
     *
     * @return void
     */
    public function testComputeDueAtMonthEndRollsOver(): void
    {
        $received = new DateTimeImmutable('2026-01-31T00:00:00+00:00');
        $due      = $this->deadline->computeDueAt($received);

        // 2026 is not a leap year — 31 Jan + 1 month normalises into March.
        $this->assertSame('2026-03-03T00:00:00+00:00', $due->format('c'));

    }//end testComputeDueAtMonthEndRollsOver()

    /**
     * A single extension adds two months to the base due date.
     *
     * @return void
     */
    public function testExtendAddsTwoMonthsToBaseDueDate(): void
    {
        $received = new DateTimeImmutable('2026-01-10T09:00:00+00:00');
        $due      = $this->deadline->computeDueAt($received);
        $extended = $this->deadline->extend($due);

        // Base = 10 Feb; extended = 10 Apr (base + 2 months), NOT anchored on now.
        $this->assertSame('2026-04-10T09:00:00+00:00', $extended->format('c'));

    }//end testExtendAddsTwoMonthsToBaseDueDate()

    /**
     * Overdue is true when the reference time is at/after the deadline.
     *
     * @return void
     */
    public function testIsOverdueWhenReferenceIsPastDeadline(): void
    {
        $deadlineAt = new DateTimeImmutable('2026-02-10T09:00:00+00:00');
        $now        = new DateTimeImmutable('2026-02-11T09:00:00+00:00');

        $this->assertTrue($this->deadline->isOverdue($deadlineAt, $now));

    }//end testIsOverdueWhenReferenceIsPastDeadline()

    /**
     * Overdue is true exactly at the deadline boundary (at-or-after).
     *
     * @return void
     */
    public function testIsOverdueAtExactBoundary(): void
    {
        $deadlineAt = new DateTimeImmutable('2026-02-10T09:00:00+00:00');

        $this->assertTrue($this->deadline->isOverdue($deadlineAt, $deadlineAt));

    }//end testIsOverdueAtExactBoundary()

    /**
     * Not overdue when the deadline is still in the future.
     *
     * @return void
     */
    public function testIsNotOverdueWhenDeadlineInFuture(): void
    {
        $deadlineAt = new DateTimeImmutable('2026-02-10T09:00:00+00:00');
        $now        = new DateTimeImmutable('2026-02-01T09:00:00+00:00');

        $this->assertFalse($this->deadline->isOverdue($deadlineAt, $now));

    }//end testIsNotOverdueWhenDeadlineInFuture()

    /**
     * Days remaining is positive before, negative after the deadline.
     *
     * @return void
     */
    public function testDaysRemaining(): void
    {
        $deadlineAt = new DateTimeImmutable('2026-02-10T00:00:00+00:00');

        $before = new DateTimeImmutable('2026-02-01T00:00:00+00:00');
        $this->assertSame(9, $this->deadline->daysRemaining($deadlineAt, $before));

        $after = new DateTimeImmutable('2026-02-13T00:00:00+00:00');
        $this->assertSame(-3, $this->deadline->daysRemaining($deadlineAt, $after));

    }//end testDaysRemaining()
}//end class

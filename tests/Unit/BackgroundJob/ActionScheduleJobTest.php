<?php

namespace Unit\BackgroundJob;

use PHPUnit\Framework\TestCase;

/**
 * Tests for ActionScheduleJob cron expression evaluation logic.
 *
 * Note: Full integration testing of the run() method requires Nextcloud's ITimeFactory
 * and database infrastructure. This test covers the cron evaluation concept.
 */
class ActionScheduleJobTest extends TestCase
{
    public function testCronExpressionEvaluationConcept(): void
    {
        // This test validates the cron-expression library usage concept.
        // The dragonmantank/cron-expression library is available in Nextcloud core.
        if (class_exists(\Cron\CronExpression::class) === false) {
            $this->markTestSkipped('dragonmantank/cron-expression not available in test context');
        }

        $cron = new \Cron\CronExpression('*/5 * * * *');
        $isDue = $cron->isDue();

        // isDue returns bool.
        $this->assertIsBool($isDue);

        // getNextRunDate returns a DateTime.
        $next = $cron->getNextRunDate();
        $this->assertInstanceOf(\DateTime::class, $next);
    }

    public function testScheduleMatchingLogic(): void
    {
        // Validate the comparison logic used in ActionScheduleJob::run().
        $lastExecuted = new \DateTime('2026-03-25 07:55:00');
        $now          = new \DateTime('2026-03-25 08:00:30');

        if (class_exists(\Cron\CronExpression::class) === false) {
            $this->markTestSkipped('dragonmantank/cron-expression not available');
        }

        $cron    = new \Cron\CronExpression('0 8 * * *');
        $nextRun = $cron->getNextRunDate($lastExecuted);

        // Next run after 07:55 with "0 8 * * *" should be 08:00.
        $this->assertSame('2026-03-25', $nextRun->format('Y-m-d'));
        $this->assertSame('08', $nextRun->format('H'));

        // 08:00:30 >= 08:00:00, so it should be due.
        $this->assertTrue($nextRun <= $now);
    }
}

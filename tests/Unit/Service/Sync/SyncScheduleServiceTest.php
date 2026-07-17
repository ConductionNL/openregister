<?php

/**
 * Unit tests for SyncScheduleService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Sync;

use DateTime;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Sync\SyncScheduleService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sync\SyncScheduleService
 */
class SyncScheduleServiceTest extends TestCase
{

    private SyncScheduleService $service;

    protected function setUp(): void
    {
        $this->service = new SyncScheduleService();
    }//end setUp()

    private function makeSource(
        bool $enabled,
        ?DateTime $lastSync,
        ?int $interval,
        ?string $status=null
    ): Source {
        $source = new Source();
        $source->setSyncEnabled($enabled);
        $source->setLastSyncDate($lastSync);
        $source->setSyncInterval($interval);
        if ($status !== null) {
            $source->setLastSyncStatus($status);
        }

        return $source;
    }//end makeSource()

    public function testDisabledSourceIsNeverDue(): void
    {
        $source = $this->makeSource(enabled: false, lastSync: null, interval: 24);
        $this->assertFalse($this->service->isDueForSync($source, new DateTime()));
    }//end testDisabledSourceIsNeverDue()

    public function testNeverSyncedEnabledSourceIsDue(): void
    {
        $source = $this->makeSource(enabled: true, lastSync: null, interval: 24);
        $this->assertTrue($this->service->isDueForSync($source, new DateTime()));
    }//end testNeverSyncedEnabledSourceIsDue()

    public function testWithinIntervalIsNotDue(): void
    {
        $now      = new DateTime('2026-03-19T12:00:00Z');
        $lastSync = new DateTime('2026-03-19T10:00:00Z');
        // 2 hours passed, interval 24 → not due.
        $source = $this->makeSource(enabled: true, lastSync: $lastSync, interval: 24);
        $this->assertFalse($this->service->isDueForSync($source, $now));
    }//end testWithinIntervalIsNotDue()

    public function testIntervalElapsedIsDue(): void
    {
        $now      = new DateTime('2026-03-20T11:00:00Z');
        $lastSync = new DateTime('2026-03-19T10:00:00Z');
        // 25 hours passed, interval 24 → due.
        $source = $this->makeSource(enabled: true, lastSync: $lastSync, interval: 24);
        $this->assertTrue($this->service->isDueForSync($source, $now));
    }//end testIntervalElapsedIsDue()

    public function testRunningSourceIsSkipped(): void
    {
        $now      = new DateTime('2026-03-20T11:00:00Z');
        $lastSync = new DateTime('2026-03-19T10:00:00Z');
        // Even though interval elapsed, a running sync must not be re-queued.
        $source = $this->makeSource(enabled: true, lastSync: $lastSync, interval: 24, status: 'running');
        $this->assertFalse($this->service->isDueForSync($source, $now));
    }//end testRunningSourceIsSkipped()

    public function testNullIntervalUsesDefault(): void
    {
        $now      = new DateTime('2026-03-20T11:00:00Z');
        // 25 hours passed; default interval is 24 → due.
        $lastSync = new DateTime('2026-03-19T10:00:00Z');
        $source   = $this->makeSource(enabled: true, lastSync: $lastSync, interval: null);
        $this->assertTrue($this->service->isDueForSync($source, $now));

        // 2 hours passed; default interval 24 → not due.
        $lastSyncRecent = new DateTime('2026-03-20T09:00:00Z');
        $sourceRecent   = $this->makeSource(enabled: true, lastSync: $lastSyncRecent, interval: null);
        $this->assertFalse($this->service->isDueForSync($sourceRecent, $now));
    }//end testNullIntervalUsesDefault()

    public function testSelectDueSourcesFiltersCorrectly(): void
    {
        $now = new DateTime('2026-03-20T12:00:00Z');

        $due       = $this->makeSource(enabled: true, lastSync: null, interval: 6);
        $notDue    = $this->makeSource(enabled: true, lastSync: new DateTime('2026-03-20T11:00:00Z'), interval: 6);
        $disabled  = $this->makeSource(enabled: false, lastSync: null, interval: 6);
        $running   = $this->makeSource(enabled: true, lastSync: null, interval: 6, status: 'running');

        $selected = $this->service->selectDueSources([$due, $notDue, $disabled, $running], $now);

        $this->assertCount(1, $selected);
        $this->assertSame($due, $selected[0]);
    }//end testSelectDueSourcesFiltersCorrectly()
}//end class

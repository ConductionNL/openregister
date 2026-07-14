<?php

/**
 * Search-trail deferral tests.
 *
 * Proves logSearchTrail() buffers entries instead of writing them inside
 * the search request, flushSearchTrails() persists the buffer after the
 * response (fail-soft per entry), and the effective recording mode is
 * memoized so the settings are read once per request instead of twice per
 * search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for deferred search-trail recording in SearchQueryHandler.
 */
class SearchTrailDeferralTest extends TestCase
{
    private ViewMapper&MockObject $viewMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private SettingsService&MockObject $settingsService;
    private LoggerInterface&MockObject $logger;
    private IRequest&MockObject $request;
    private SearchTrailService&MockObject $searchTrailService;
    private SearchQueryHandler $handler;

    /**
     * Set up handler with mocked dependencies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->viewMapper         = $this->createMock(ViewMapper::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->settingsService    = $this->createMock(SettingsService::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->request            = $this->createMock(IRequest::class);
        $this->searchTrailService = $this->createMock(SearchTrailService::class);

        $this->handler = new SearchQueryHandler(
            viewMapper: $this->viewMapper,
            schemaMapper: $this->schemaMapper,
            settingsService: $this->settingsService,
            logger: $this->logger,
            request: $this->request,
            searchTrailService: $this->searchTrailService
        );
    }//end setUp()

    /**
     * Configure the retention settings the handler reads.
     *
     * @param bool   $enabled Whether search trails are enabled.
     * @param string $mode    Recording mode ('all', '_search', 'none').
     *
     * @return void
     */
    private function configureSettings(bool $enabled=true, string $mode='all'): void
    {
        $this->settingsService->method('getRetentionSettingsOnly')->willReturn([
            'searchTrailsEnabled'      => $enabled,
            'searchTrailRecordingMode' => $mode,
        ]);
    }//end configureSettings()

    // ─── Recording-mode memoization ──────────────────────────────────

    /**
     * The recording mode is read from settings exactly once per request,
     * regardless of how many times it is consulted (mode gate in
     * recordSearchTrail + enabled gate in logSearchTrail).
     *
     * @return void
     */
    public function testRecordingModeIsMemoizedAcrossCalls(): void
    {
        $this->settingsService->expects($this->once())
            ->method('getRetentionSettingsOnly')
            ->willReturn([
                'searchTrailsEnabled'      => true,
                'searchTrailRecordingMode' => 'all',
            ]);

        $this->assertSame('all', $this->handler->getEffectiveRecordingMode());
        $this->assertSame('all', $this->handler->getEffectiveRecordingMode());

        // logSearchTrail consults the same memoized mode — still one read.
        $this->handler->logSearchTrail(['_search' => 'x'], 1, 1, 1.0);
    }//end testRecordingModeIsMemoizedAcrossCalls()

    // ─── Deferral ────────────────────────────────────────────────────

    /**
     * logSearchTrail() must NOT write the trail synchronously — the entry is
     * buffered for the post-response flush.
     *
     * @return void
     */
    public function testLogSearchTrailDoesNotWriteSynchronously(): void
    {
        $this->configureSettings();

        $this->searchTrailService->expects($this->never())->method('createSearchTrail');

        $this->handler->logSearchTrail(['_search' => 'deferred'], 5, 42, 12.5, 'database');
    }//end testLogSearchTrailDoesNotWriteSynchronously()

    /**
     * flushSearchTrails() persists the buffered entry with the exact values
     * captured at log time and reports one persisted row.
     *
     * @return void
     */
    public function testFlushPersistsBufferedEntries(): void
    {
        $this->configureSettings();

        $this->searchTrailService->expects($this->once())
            ->method('createSearchTrail')
            ->with(
                ['_search' => 'deferred'],
                5,
                42,
                12.5,
                'database'
            )
            ->willReturn(new SearchTrail());

        $this->handler->logSearchTrail(['_search' => 'deferred'], 5, 42, 12.5, 'database');

        $this->assertSame(1, $this->handler->flushSearchTrails());
    }//end testFlushPersistsBufferedEntries()

    /**
     * The buffer is cleared by the flush: a second flush persists nothing.
     *
     * @return void
     */
    public function testSecondFlushPersistsNothing(): void
    {
        $this->configureSettings();
        $this->searchTrailService->method('createSearchTrail')->willReturn(new SearchTrail());

        $this->handler->logSearchTrail(['_search' => 'once'], 1, 1, 1.0);

        $this->assertSame(1, $this->handler->flushSearchTrails());
        $this->assertSame(0, $this->handler->flushSearchTrails());
    }//end testSecondFlushPersistsNothing()

    /**
     * Multiple searches in one request buffer multiple entries; one flush
     * persists them all.
     *
     * @return void
     */
    public function testFlushPersistsAllBufferedEntries(): void
    {
        $this->configureSettings();

        $this->searchTrailService->expects($this->exactly(3))
            ->method('createSearchTrail')
            ->willReturn(new SearchTrail());

        $this->handler->logSearchTrail(['_search' => 'one'], 1, 1, 1.0);
        $this->handler->logSearchTrail(['_search' => 'two'], 2, 2, 2.0);
        $this->handler->logSearchTrail(['_search' => 'three'], 3, 3, 3.0);

        $this->assertSame(3, $this->handler->flushSearchTrails());
    }//end testFlushPersistsAllBufferedEntries()

    // ─── Recording disabled ──────────────────────────────────────────

    /**
     * With trails disabled ('none'), nothing is buffered and the flush
     * persists nothing.
     *
     * @return void
     */
    public function testDisabledTrailsBufferNothing(): void
    {
        $this->configureSettings(enabled: false);

        $this->searchTrailService->expects($this->never())->method('createSearchTrail');

        $this->handler->logSearchTrail(['_search' => 'ignored'], 1, 1, 1.0);

        $this->assertSame(0, $this->handler->flushSearchTrails());
    }//end testDisabledTrailsBufferNothing()

    // ─── Fail-soft flush ─────────────────────────────────────────────

    /**
     * A failing insert during the flush is logged and dropped — the trail is
     * best-effort and losing a row is acceptable; throwing is not.
     *
     * @return void
     */
    public function testFlushIsFailSoftPerEntry(): void
    {
        $this->configureSettings();

        $this->searchTrailService->method('createSearchTrail')
            ->willThrowException(new \RuntimeException('DB gone away'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Failed to record search trail'), $this->anything());

        $this->handler->logSearchTrail(['_search' => 'lost'], 1, 1, 1.0);

        $this->assertSame(0, $this->handler->flushSearchTrails());
    }//end testFlushIsFailSoftPerEntry()
}//end class

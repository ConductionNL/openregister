<?php

/**
 * Tests for SearchTrailRecorder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\SearchTrailRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Object\SearchTrailRecorder
 */
class SearchTrailRecorderTest extends TestCase
{
    /**
     * Record a search under a mode and return the arguments the handler was given.
     *
     * @param string $mode   The recording mode.
     * @param array  $query  The query.
     * @param array  $result The result.
     *
     * @return array|null The logSearchTrail arguments, null when nothing was recorded.
     */
    private function recorded(string $mode, array $query, array $result=[]): ?array
    {
        $entry   = null;
        $handler = $this->createMock(SearchQueryHandler::class);
        $handler->method('getEffectiveRecordingMode')->willReturn($mode);
        $handler->method('logSearchTrail')->willReturnCallback(
            function () use (&$entry): void {
                $entry = func_get_args();
            }
        );

        $recorder = new SearchTrailRecorder($handler, $this->createMock(LoggerInterface::class), 0.0);
        $recorder->record(query: $query, result: $result);

        return $entry;
    }

    public function testModeNoneRecordsNothing(): void
    {
        $this->assertNull($this->recorded('none', ['_search' => 'term']));
    }

    public function testSearchModeSkipsAQueryWithoutATerm(): void
    {
        $this->assertNull($this->recorded('_search', ['_search' => '   ']));
        $this->assertNull($this->recorded('_search', []));
    }

    public function testSearchModeRecordsATerm(): void
    {
        $entry = $this->recorded('_search', ['_search' => 'term']);

        $this->assertNotNull($entry);
        $this->assertSame(['_search' => 'term'], $entry[0]);
    }

    public function testAllModeRecordsWithoutATerm(): void
    {
        $entry = $this->recorded('all', []);

        $this->assertNotNull($entry);
        $this->assertSame([], $entry[0]);
    }

    public function testTheEntryCarriesCountsAndTheIndexSource(): void
    {
        $query = ['_limit' => 2];
        $entry = $this->recorded('all', $query, ['results' => ['a', 'b'], 'total' => '7', '@self' => ['source' => 'index']]);

        $this->assertSame([$query, 2, 7], array_slice($entry, 0, 3));
        $this->assertSame('index', $entry[4]);
    }

    public function testAnyOtherSourceCountsAsDatabase(): void
    {
        $this->assertSame('database', $this->recorded('all', [], ['@self' => ['source' => 'magic_mapper']])[4]);
        $this->assertSame('database', $this->recorded('all', [])[4]);
    }

    public function testTheResponseTimeIsInMilliseconds(): void
    {
        $before = microtime(true);
        $entry  = $this->recorded('all', []);

        $this->assertGreaterThanOrEqual($before * 1000, $entry[3]);
    }

    public function testAFailedRecordingIsLoggedAndNotThrown(): void
    {
        $handler = $this->createMock(SearchQueryHandler::class);
        $handler->method('getEffectiveRecordingMode')->willThrowException(new RuntimeException('trail down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('recordSearchTrail failed: trail down'));

        (new SearchTrailRecorder($handler, $logger, 0.0))->record(query: [], result: []);
    }
}

<?php

/**
 * Unit tests for SyncConflictResolver.
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
use OCA\OpenRegister\Service\Sync\SyncConflictResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sync\SyncConflictResolver
 */
class SyncConflictResolverTest extends TestCase
{

    private SyncConflictResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SyncConflictResolver();
    }//end setUp()

    public function testNoLocalChangeAlwaysAppliesSource(): void
    {
        foreach (SyncConflictResolver::strategies() as $strategy) {
            $this->assertSame(
                SyncConflictResolver::APPLY_SOURCE,
                $this->resolver->resolve(strategy: $strategy, localChanged: false),
                "Strategy {$strategy} should apply source when local is unchanged"
            );
        }
    }//end testNoLocalChangeAlwaysAppliesSource()

    public function testSourceWinsOverwritesLocal(): void
    {
        $this->assertSame(
            SyncConflictResolver::APPLY_SOURCE,
            $this->resolver->resolve(strategy: SyncConflictResolver::SOURCE_WINS, localChanged: true)
        );
    }//end testSourceWinsOverwritesLocal()

    public function testLocalWinsKeepsLocal(): void
    {
        $this->assertSame(
            SyncConflictResolver::KEEP_LOCAL,
            $this->resolver->resolve(strategy: SyncConflictResolver::LOCAL_WINS, localChanged: true)
        );
    }//end testLocalWinsKeepsLocal()

    public function testManualDefers(): void
    {
        $this->assertSame(
            SyncConflictResolver::DEFER,
            $this->resolver->resolve(strategy: SyncConflictResolver::MANUAL, localChanged: true)
        );
    }//end testManualDefers()

    public function testNewestWinsSourceNewer(): void
    {
        $decision = $this->resolver->resolve(
            strategy: SyncConflictResolver::NEWEST_WINS,
            localChanged: true,
            sourceModified: new DateTime('2026-03-18T16:00:00Z'),
            localModified: new DateTime('2026-03-18T14:00:00Z')
        );
        $this->assertSame(SyncConflictResolver::APPLY_SOURCE, $decision);
    }//end testNewestWinsSourceNewer()

    public function testNewestWinsLocalNewer(): void
    {
        $decision = $this->resolver->resolve(
            strategy: SyncConflictResolver::NEWEST_WINS,
            localChanged: true,
            sourceModified: new DateTime('2026-03-18T10:00:00Z'),
            localModified: new DateTime('2026-03-18T14:00:00Z')
        );
        $this->assertSame(SyncConflictResolver::KEEP_LOCAL, $decision);
    }//end testNewestWinsLocalNewer()

    public function testNewestWinsTiePrefersSource(): void
    {
        $ts       = new DateTime('2026-03-18T12:00:00Z');
        $decision = $this->resolver->resolve(
            strategy: SyncConflictResolver::NEWEST_WINS,
            localChanged: true,
            sourceModified: $ts,
            localModified: clone $ts
        );
        $this->assertSame(SyncConflictResolver::APPLY_SOURCE, $decision);
    }//end testNewestWinsTiePrefersSource()

    public function testNewestWinsMissingTimestampsDefers(): void
    {
        $decision = $this->resolver->resolve(
            strategy: SyncConflictResolver::NEWEST_WINS,
            localChanged: true,
            sourceModified: null,
            localModified: null
        );
        $this->assertSame(SyncConflictResolver::DEFER, $decision);
    }//end testNewestWinsMissingTimestampsDefers()

    public function testUnknownStrategyDefers(): void
    {
        $this->assertSame(
            SyncConflictResolver::DEFER,
            $this->resolver->resolve(strategy: 'made-up', localChanged: true)
        );
        $this->assertFalse($this->resolver->isValidStrategy('made-up'));
    }//end testUnknownStrategyDefers()
}//end class

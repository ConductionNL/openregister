<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService}.
 *
 * Grouping / threshold / normalisation / window / fail-safe matrices for the
 * pure GDPR art-35 detection engine, plus the idempotency contract (flagged
 * cases count toward their group but are reported separately so the job never
 * re-writes them).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Gdpr;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService;
use PHPUnit\Framework\TestCase;

/**
 * DpiaPatternDetectionServiceTest.
 */
class DpiaPatternDetectionServiceTest extends TestCase
{

    private DpiaPatternDetectionService $service;

    private DateTimeImmutable $now;

    private const CONFIG = [
        'threshold'  => 3,
        'windowDays' => 30,
        'groupBy'    => ['type', 'scope'],
    ];


    protected function setUp(): void
    {
        $this->service = new DpiaPatternDetectionService();
        $this->now     = new DateTimeImmutable('2026-07-06T12:00:00+00:00');

    }//end setUp()


    /**
     * A group reaching the threshold inside the window triggers; flagged
     * members count but are reported separately (idempotency contract).
     *
     * @return void
     */
    public function testThresholdCrossingReportsGroupWithUnflaggedMembers(): void
    {
        $cases  = [
            $this->case(uuid: 'a', type: 'access', scope: 'Camera Data', daysAgo: 1),
            $this->case(uuid: 'b', type: 'access', scope: 'camera data', daysAgo: 10),
            $this->case(uuid: 'c', type: 'access', scope: '  Camera   Data ', daysAgo: 20, flagged: true),
            $this->case(uuid: 'd', type: 'erasure', scope: 'camera data', daysAgo: 5),
        ];
        $groups = $this->service->detect(cases: $cases, config: self::CONFIG, now: $this->now);

        $this->assertCount(1, $groups);
        $this->assertSame('type=access|scope=camera data', $groups[0]['key']);
        $this->assertSame(3, $groups[0]['count']);
        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $groups[0]['caseUuids']);
        // The already-flagged case is counted but never re-written.
        $this->assertEqualsCanonicalizing(['a', 'b'], $groups[0]['unflaggedUuids']);

    }//end testThresholdCrossingReportsGroupWithUnflaggedMembers()


    /**
     * Cases outside the rolling window do not count toward a group.
     *
     * @return void
     */
    public function testWindowBoundsMembership(): void
    {
        $cases  = [
            $this->case(uuid: 'a', type: 'access', scope: 's', daysAgo: 1),
            $this->case(uuid: 'b', type: 'access', scope: 's', daysAgo: 15),
            $this->case(uuid: 'c', type: 'access', scope: 's', daysAgo: 45),
        ];
        $groups = $this->service->detect(cases: $cases, config: self::CONFIG, now: $this->now);

        // Only two cases inside the 30-day window — below the threshold of 3.
        $this->assertSame([], $groups);

    }//end testWindowBoundsMembership()


    /**
     * Below-threshold groups stay untouched; a fully-flagged group still
     * triggers but exposes no unflagged members (re-runs are no-ops).
     *
     * @return void
     */
    public function testIdempotentReRunExposesNoUnflaggedMembers(): void
    {
        $cases  = [
            $this->case(uuid: 'a', type: 'access', scope: 's', daysAgo: 1, flagged: true),
            $this->case(uuid: 'b', type: 'access', scope: 's', daysAgo: 2, flagged: true),
            $this->case(uuid: 'c', type: 'access', scope: 's', daysAgo: 3, flagged: true),
        ];
        $groups = $this->service->detect(cases: $cases, config: self::CONFIG, now: $this->now);

        $this->assertCount(1, $groups);
        $this->assertSame([], $groups[0]['unflaggedUuids']);

    }//end testIdempotentReRunExposesNoUnflaggedMembers()


    /**
     * Fail-safe: unusable configuration (no pack / no block / bad values)
     * never produces a group — no false DPIA flags.
     *
     * @return void
     */
    public function testFailSafeConfigurationMatrix(): void
    {
        $cases = [
            $this->case(uuid: 'a', type: 'access', scope: 's', daysAgo: 1),
            $this->case(uuid: 'b', type: 'access', scope: 's', daysAgo: 2),
            $this->case(uuid: 'c', type: 'access', scope: 's', daysAgo: 3),
        ];

        foreach ([[], ['threshold' => 0, 'windowDays' => 30], ['threshold' => 3, 'windowDays' => 0]] as $config) {
            $this->assertSame(
                [],
                $this->service->detect(cases: $cases, config: $config, now: $this->now)
            );
        }

    }//end testFailSafeConfigurationMatrix()


    /**
     * Pack-driven thresholds apply without code changes (config as data).
     *
     * @return void
     */
    public function testPackDrivenThreshold(): void
    {
        $cases = [
            $this->case(uuid: 'a', type: 'access', scope: 's', daysAgo: 1),
            $this->case(uuid: 'b', type: 'access', scope: 's', daysAgo: 2),
        ];

        $strict = $this->service->detect(cases: $cases, config: self::CONFIG, now: $this->now);
        $this->assertSame([], $strict);

        $lowered = $this->service->detect(
            cases: $cases,
            config: array_merge(self::CONFIG, ['threshold' => 2]),
            now: $this->now
        );
        $this->assertCount(1, $lowered);

    }//end testPackDrivenThreshold()


    /**
     * Normalisation: trim / lowercase / collapse whitespace; non-scalar
     * values normalise to '' and group together without crashing.
     *
     * @return void
     */
    public function testNormalisation(): void
    {
        $this->assertSame('camera data', $this->service->normalise('  Camera   DATA '));
        $this->assertSame('', $this->service->normalise(['not' => 'scalar']));
        $this->assertSame('', $this->service->normalise(null));

    }//end testNormalisation()


    /**
     * Build a case payload.
     *
     * @param string $uuid    The case uuid.
     * @param string $type    The request type.
     * @param string $scope   The scope value (grouping characteristic).
     * @param int    $daysAgo Days before `now` the case was received.
     * @param bool   $flagged Whether dpiaRequired is already true.
     *
     * @return array<string, mixed>
     */
    private function case(string $uuid, string $type, string $scope, int $daysAgo, bool $flagged=false): array
    {
        return [
            '@uuid'        => $uuid,
            'type'         => $type,
            'scope'        => $scope,
            'receivedAt'   => $this->now->modify(sprintf('-%d days', $daysAgo))->format(DATE_ATOM),
            'dpiaRequired' => $flagged,
        ];

    }//end case()
}//end class

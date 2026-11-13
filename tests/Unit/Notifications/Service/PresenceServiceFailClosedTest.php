<?php

/**
 * Unit tests for the departure, sweep and never-seen branches of PresenceService.
 *
 * A presence write that fails must never break the page: it is logged and
 * reported as "nothing changed".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Notifications\Db\ObjectPresence;
use OCA\OpenRegister\Notifications\Db\ObjectPresenceMapper;
use OCA\OpenRegister\Notifications\Service\PresenceBeat;
use OCA\OpenRegister\Notifications\Service\PresenceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\PresenceService
 * @uses   \OCA\OpenRegister\Notifications\Db\ObjectPresence
 * @uses   \OCA\OpenRegister\Notifications\Service\PresenceBeat
 */
final class PresenceServiceFailClosedTest extends TestCase {

	private const OBJ = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	/** @var ObjectPresenceMapper&MockObject */
	private $mapper;

	/** @var LoggerInterface&MockObject */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(ObjectPresenceMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(): PresenceService {
		return new PresenceService($this->mapper, $this->logger);
	}

	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-18T12:00:00+00:00', new DateTimeZone('UTC'));
	}

	public function testADepartureWithoutAReaderOrObjectWritesNothing(): void {
		$this->mapper->expects($this->never())->method('removeOne');

		$this->assertFalse($this->service()->depart(userId: ' ', objectUuid: self::OBJ));
		$this->assertFalse($this->service()->depart(userId: 'anna', objectUuid: ''));
	}

	public function testADepartureReportsWhetherTheReaderWasPresent(): void {
		$this->mapper->expects($this->once())->method('removeOne')->with('anna', self::OBJ)->willReturn(true);

		$this->assertTrue($this->service()->depart(userId: 'anna', objectUuid: self::OBJ));
	}

	public function testAFailedDepartureIsLoggedAndReportsNothingChanged(): void {
		$this->mapper->method('removeOne')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[PresenceService] a departure could not be written: db down', $this->anything());

		$this->assertFalse($this->service()->depart(userId: 'anna', objectUuid: self::OBJ));
	}

	public function testASweepThatCannotReadIsLoggedAndExpiresNobody(): void {
		$this->mapper->method('findStale')->willThrowException(new RuntimeException('db down'));
		$this->mapper->expects($this->never())->method('pruneStale');
		$this->logger->expects($this->once())
			->method('warning')
			->with('[PresenceService] stale presence could not be swept: db down', $this->anything());

		$this->assertSame([], $this->service()->expire(now: $this->now()));
	}

	public function testASweepThatCannotPruneExpiresNobody(): void {
		$stale = new ObjectPresence();
		$stale->setUserId('anna');
		$stale->setObjectUuid(self::OBJ);
		$this->mapper->method('findStale')->willReturn([$stale]);
		$this->mapper->method('pruneStale')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame([], $this->service()->expire(now: $this->now()));
	}

	public function testARowNeverSeenBeforeIsAnArrival(): void {
		$existing = new ObjectPresence();
		$existing->setUserId('anna');
		$existing->setObjectUuid(self::OBJ);
		$this->mapper->method('findOne')->willReturn($existing);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->mapper->expects($this->never())->method('insert');

		$beat = $this->service()->heartbeat(beat: new PresenceBeat(userId: 'anna', objectUuid: self::OBJ, now: $this->now()));

		$this->assertTrue($beat['arrived']);
		$this->assertSame($existing, $beat['presence']);
		$this->assertSame('2026-09-18T12:00:00+00:00', $existing->getArrivedAt()->format(DATE_ATOM));
	}
}

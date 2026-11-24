<?php

/**
 * Unit tests for the Core port the read state counts unread sub-resources through.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\ObjectReadStateMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\UnreadCountsInterface;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\UnreadCountCalculator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The read state (Core) asks the port; the Processes counter answers it and binds itself in the Processes map.
 */
final class UnreadCountsPortTest extends TestCase {

	public function testTheCounterAnswersThePort(): void {
		$this->assertTrue(is_subclass_of(UnreadCountCalculator::class, UnreadCountsInterface::class));
	}

	public function testTheProcessesMapBindsThePortToTheCounter(): void {
		$this->assertSame(UnreadCountCalculator::class, FlowWiring::BINDINGS[UnreadCountsInterface::class]);
	}

	public function testTheReadStateHandsTheReadersRowToThePort(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$row = new ObjectReadState();

		$mapper = $this->createMock(ObjectReadStateMapperInterface::class);
		$mapper->expects($this->once())->method('findOne')->with('alice', 'obj-1')->willReturn($row);

		$counter = $this->createMock(UnreadCountsInterface::class);
		$counter->expects($this->once())
			->method('countsFor')
			->with($this->identicalTo($object), $this->identicalTo($row))
			->willReturn(['notes' => 2]);

		$this->assertSame(['notes' => 2], $this->service(mapper: $mapper, counter: $counter)->unreadCounts($object));
	}

	public function testAnAnonymousReaderAsksNothing(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');

		$counter = $this->createMock(UnreadCountsInterface::class);
		$counter->expects($this->never())->method('countsFor');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$service = new ReadStateService($this->createMock(ObjectReadStateMapperInterface::class), $session, $counter, $this->createMock(LoggerInterface::class));

		$this->assertSame([], $service->unreadCounts($object));
	}

	/**
	 * A read state service acting as alice.
	 *
	 * @param ObjectReadStateMapperInterface $mapper  The read state rows.
	 * @param UnreadCountsInterface          $counter The port.
	 *
	 * @return ReadStateService
	 */
	private function service(ObjectReadStateMapperInterface $mapper, UnreadCountsInterface $counter): ReadStateService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ReadStateService($mapper, $session, $counter, $this->createMock(LoggerInterface::class));
	}
}

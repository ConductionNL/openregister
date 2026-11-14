<?php

/**
 * Unit tests for the per-app comments entity collections CommentsEntityListener
 * registers next to `openregister`: what each closure accepts, that it reads
 * the object as the current user, and what happens when a name is taken or
 * the claims cannot be read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @spec openspec/changes/platform-comments-entity/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Listener\CommentsEntityListener;
use OCA\OpenRegister\Service\CommentsCollectionCatalog;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Comments\CommentsEntityEvent;
use OutOfBoundsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\CommentsEntityListener
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class CommentsEntityListenerAppCollectionsTest extends TestCase {

	private MagicMapperInterface&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	private CommentsCollectionCatalog&MockObject $catalog;

	/**
	 * Collections registered, name => closure.
	 *
	 * @var array<string,callable>
	 */
	private array $registered = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(MagicMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->catalog = $this->createMock(CommentsCollectionCatalog::class);
	}

	private function handle(?string $takenName = null): void {
		$event = $this->createMock(CommentsEntityEvent::class);
		$event->method('addEntityCollection')->willReturnCallback(
			function (string $name, \Closure $closure) use ($takenName): void {
				if ($name === $takenName) {
					throw new OutOfBoundsException('Duplicate entity name "' . $name . '"');
				}

				$this->registered[$name] = $closure;
			}
		);

		(new CommentsEntityListener($this->mapper, $this->logger, $this->catalog))->handle($event);
	}

	private function object(int $registerId, int $schemaId): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister((string)$registerId);
		$object->setSchema((string)$schemaId);
		return $object;
	}

	public function testEachClaimingAppGetsItsOwnCollectionNextToOpenregister(): void {
		$this->catalog->method('names')->willReturn(['openregister', 'dossiq', 'pipelinq']);

		$this->handle();

		$this->assertSame(['openregister', 'dossiq', 'pipelinq'], array_keys($this->registered));
	}

	public function testAnAppsCollectionAcceptsItsOwnObjectAndRefusesAForeignOne(): void {
		$this->catalog->method('names')->willReturn(['openregister', 'dossiq']);
		$this->mapper->method('find')->willReturnMap(
			[
				['zaak-1', null, null, false, true, true, $this->object(3, 7)],
				['lead-1', null, null, false, true, true, $this->object(3, 8)],
			]
		);
		$this->catalog->expects($this->exactly(2))->method('accepts')->willReturnCallback(
			static fn (string $collection, int $registerId, int $schemaId): bool => ($collection === 'dossiq' && $registerId === 3 && $schemaId === 7)
		);

		$this->handle();

		$this->assertTrue(($this->registered['dossiq'])('zaak-1'));
		$this->assertFalse(($this->registered['dossiq'])('lead-1'));
	}

	public function testAnObjectTheUserMayNotReadIsRefusedByEveryCollection(): void {
		$this->catalog->method('names')->willReturn(['openregister', 'dossiq']);
		$this->mapper->expects($this->exactly(2))->method('find')->with('hidden-1')
			->willThrowException(new DoesNotExistException('not found for this user'));
		$this->catalog->expects($this->never())->method('accepts');
		$this->logger->expects($this->exactly(2))->method('debug')
			->with('Object UUID not found for comments entity: hidden-1', $this->arrayHasKey('exception'));

		$this->handle();

		$this->assertFalse(($this->registered['dossiq'])('hidden-1'));
		$this->assertFalse(($this->registered['openregister'])('hidden-1'));
	}

	public function testATakenNameIsSkippedAndTheOthersStillRegister(): void {
		$this->catalog->method('names')->willReturn(['openregister', 'deck', 'dossiq']);
		$this->logger->expects($this->once())->method('warning')->with(
			'CommentsEntityListener: comments collection name already taken; skipped',
			['collection' => 'deck', 'exception' => 'Duplicate entity name "deck"']
		);

		$this->handle(takenName: 'deck');

		$this->assertSame(['openregister', 'dossiq'], array_keys($this->registered));
	}

	public function testUnreadableClaimsLeaveOnlyTheOpenregisterCollection(): void {
		$this->catalog->method('names')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'CommentsEntityListener: the claiming apps could not be read; only the openregister comments collection is registered',
			['exception' => 'registry down']
		);

		$this->handle();

		$this->assertSame(['openregister'], array_keys($this->registered));
	}
}

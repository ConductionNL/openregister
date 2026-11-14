<?php

/**
 * Unit tests for MdtoContainment.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\MdtoContainment;
use OCA\OpenRegister\Service\Edepot\MdtoSourceReader;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The records a record contains and the records it is part of.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\MdtoContainment
 *
 * @spec openspec/changes/archival-conformance/tasks.md#task-1.3
 */
class MdtoContainmentTest extends TestCase {

	private MagicMapperInterface&MockObject $objects;

	private MdtoSourceReader&MockObject $sourceReader;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(MagicMapperInterface::class);
		$this->sourceReader = $this->createMock(MdtoSourceReader::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A part keeps the name the row gave it; one without is looked up past RBAC and tenancy.
	 *
	 * @return void
	 */
	public function testPartsKeepTheirNameOrAreLookedUp(): void {
		$parent = $this->record(uuid: 'parent');
		$child = $this->record(uuid: 'child-2');
		$this->sourceReader->method('parts')->with($parent)->willReturn([['id' => 'child-1', 'name' => 'Aanvraag'], ['id' => 'child-2', 'name' => null]]);
		$this->objects->expects(self::once())->method('find')
			->with('child-2', null, null, false, false, false)
			->willReturn($child);
		$this->sourceReader->method('name')->with($child)->willReturn('Besluit');

		self::assertSame(
			[['name' => 'Aanvraag', 'id' => 'child-1'], ['name' => 'Besluit', 'id' => 'child-2']],
			$this->containment()->partsOf(object: $parent)
		);
	}//end testPartsKeepTheirNameOrAreLookedUp()

	/**
	 * A part that cannot be found, or has no name, is named by its id.
	 *
	 * @return void
	 */
	public function testAPartThatCannotBeReadIsNamedByItsId(): void {
		$nameless = $this->record(uuid: 'child-2');
		$this->sourceReader->method('parts')->willReturn([['id' => 'gone', 'name' => null], ['id' => 'child-2', 'name' => null]]);
		$this->objects->method('find')->willReturnCallback(
			static function (string|int $identifier) use ($nameless): ObjectEntity {
				if ($identifier === 'gone') {
					throw new DoesNotExistException('gone');
				}

				return $nameless;
			}
		);
		$this->sourceReader->method('name')->willReturn('');

		self::assertSame(
			[['name' => 'gone', 'id' => 'gone'], ['name' => 'child-2', 'id' => 'child-2']],
			$this->containment()->partsOf(object: $this->record(uuid: 'parent'))
		);
	}//end testAPartThatCannotBeReadIsNamedByItsId()

	/**
	 * Only a referencing record whose declared parts list this one is a whole, each once.
	 *
	 * @return void
	 */
	public function testAWholeIsAReferencingRecordThatListsThisOneAsAPart(): void {
		$child = $this->record(uuid: 'child-1');
		$parent = $this->record(uuid: 'parent');
		$mentions = $this->record(uuid: 'mentions');
		$this->objects->expects(self::once())->method('findByRelationUsingRelationsColumn')->with('child-1')
			->willReturn([$parent, $mentions, $parent, $child]);
		$this->sourceReader->method('parts')->willReturnCallback(
			static fn (ObjectEntity $object): array => match ($object->getUuid()) {
				'parent' => [['id' => 'child-0', 'name' => null], ['id' => 'child-1', 'name' => null]],
				default => [],
			}
		);
		$this->sourceReader->method('name')->with($parent)->willReturn('Dossier Kerkstraat 1');

		self::assertSame([['name' => 'Dossier Kerkstraat 1', 'id' => 'parent']], $this->containment()->wholesOf(object: $child));
	}//end testAWholeIsAReferencingRecordThatListsThisOneAsAPart()

	/**
	 * A failing reverse lookup leaves the reference out and is logged; a record without uuid has none.
	 *
	 * @return void
	 */
	public function testAFailingLookupLeavesTheWholesOut(): void {
		$this->objects->method('findByRelationUsingRelationsColumn')->willThrowException(new RuntimeException('database away'));
		$this->logger->expects(self::once())->method('warning')
			->with('[MdtoContainment] Could not look up the records that contain {uuid}: {error}', ['uuid' => 'child-1', 'error' => 'database away']);

		self::assertSame([], $this->containment()->wholesOf(object: $this->record(uuid: 'child-1')));
		self::assertSame([], $this->containment()->wholesOf(object: $this->record(uuid: '')));
	}//end testAFailingLookupLeavesTheWholesOut()

	/**
	 * The service under test.
	 *
	 * @return MdtoContainment The service.
	 */
	private function containment(): MdtoContainment {
		return new MdtoContainment(objects: $this->objects, sourceReader: $this->sourceReader, logger: $this->logger);
	}//end containment()

	/**
	 * A record with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity&MockObject The record.
	 */
	private function record(string $uuid): ObjectEntity&MockObject {
		$object = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['getUuid'])->getMock();
		$object->method('getUuid')->willReturn($uuid);

		return $object;
	}//end record()
}//end class

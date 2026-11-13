<?php

/**
 * The relation-index finder and the save-path writer behind the merge relinker.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace Unit\Service\Merge;

use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Merge\ReferenceMove;
use OCA\OpenRegister\Service\Merge\RelationIndexReferenceFinder;
use OCA\OpenRegister\Service\Merge\SavePathReferenceWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(RelationIndexReferenceFinder::class)]
#[CoversClass(SavePathReferenceWriter::class)]
#[UsesClass(ReferenceMove::class)]
class ReferenceFinderWriterTest extends TestCase {

	private function object(string $uuid, ?string $register, ?string $schema, ?array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister($register);
		$object->setSchema($schema);
		$object->setObject($data);

		return $object;
	}//end object()

	private function move(): ReferenceMove {
		return new ReferenceMove(objectUuid: 'conn-1', register: 'catalogus', schema: 'connection', path: 'module', before: 'loser', after: 'survivor');
	}//end move()

	public function testTheFinderListsEveryReferencingObjectButTheRecordItself(): void {
		$relations = $this->createMock(MagicRelationReaderInterface::class);
		$relations->expects($this->once())->method('findByRelation')->with('loser')->willReturn(
			[
				$this->object('suite-1', '5', '12', ['applications' => ['loser']]),
				$this->object('loser', '5', '11', ['title' => 'self']),
				$this->object('conn-1', null, '', null),
			]
		);

		$found = (new RelationIndexReferenceFinder($relations))->referencingObjects('loser');

		$this->assertCount(2, $found);
		// getObject() carries the object's id beside its stored properties.
		$this->assertSame(['suite-1', '5', '12', ['applications' => ['loser']]], [$found[0]->uuid, $found[0]->register, $found[0]->schema, array_diff_key($found[0]->data, ['id' => true])]);
		$this->assertSame(['conn-1', null, null], [$found[1]->uuid, $found[1]->register, $found[1]->schema]);
		$this->assertSame([], array_diff_key($found[1]->data, ['id' => true]));
	}//end testTheFinderListsEveryReferencingObjectButTheRecordItself()

	public function testTheFinderAsksNothingForAnEmptyUuid(): void {
		$relations = $this->createMock(MagicRelationReaderInterface::class);
		$relations->expects($this->never())->method('findByRelation');

		$this->assertSame([], (new RelationIndexReferenceFinder($relations))->referencingObjects(''));
	}//end testTheFinderAsksNothingForAnEmptyUuid()

	public function testTheWriterSavesTheMovedFieldWithRbacOn(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->with('conn-1', null, true, true)->willReturn(
			$this->object('conn-1', 'catalogus', 'connection', ['module' => 'loser', 'checkedAt' => '2026-11-13 10:00:00'])
		);
		$objects->expects($this->once())->method('saveObject')->with(
			$this->callback(
				static fn (ObjectEntity $entity): bool => array_diff_key(($entity->getObject() ?? []), ['id' => true]) === ['module' => 'survivor', 'checkedAt' => '2026-11-13T10:00:00+00:00']
			),
			$this->callback(static fn (ObjectSaveOptions $options): bool => $options->uuid === 'conn-1'),
			true,
			true
		)->willReturnArgument(0);

		$this->assertTrue((new SavePathReferenceWriter($objects, $this->createMock(LoggerInterface::class)))->write($this->move(), 'steward'));
	}//end testTheWriterSavesTheMovedFieldWithRbacOn()

	public function testARefusedSaveIsNotWritten(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn($this->object('conn-1', 'catalogus', 'connection', ['module' => 'loser']));
		$objects->method('saveObject')->willThrowException(new NotAuthorizedException('You may not change this object.'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertFalse((new SavePathReferenceWriter($objects, $logger))->write($this->move(), 'steward'));
	}//end testARefusedSaveIsNotWritten()

	public function testAnObjectTheStewardCannotReadIsNotWritten(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn(null);
		$objects->expects($this->never())->method('saveObject');

		$this->assertFalse((new SavePathReferenceWriter($objects, $this->createMock(LoggerInterface::class)))->write($this->move(), 'steward'));
	}//end testAnObjectTheStewardCannotReadIsNotWritten()

	public function testAFailedSaveIsReportedAndNotWritten(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn($this->object('conn-1', 'catalogus', 'connection', ['module' => 'loser']));
		$objects->method('saveObject')->willThrowException(new RuntimeException('validation failed'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('validation failed'));

		$this->assertFalse((new SavePathReferenceWriter($objects, $logger))->write($this->move(), 'steward'));
	}//end testAFailedSaveIsReportedAndNotWritten()
}//end class

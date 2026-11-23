<?php

/**
 * PurgeObjectCommand reports a missing object and a failed delete as failures,
 * and treats an object whose schema cannot be resolved as non-archival.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\PurgeObjectCommand;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Behaviour of the purge command's lookup and delete failure paths.
 */
class PurgeObjectCommandBehaviourTest extends TestCase {

	/** @var MagicMapperInterface&MockObject */
	private MagicMapperInterface $objectMapper;

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemaMapper;

	protected function setUp(): void {
		$this->objectMapper = $this->createMock(MagicMapperInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
	}

	private function object(?string $schema, bool $trashed): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema($schema);
		if ($trashed === true) {
			$object->setDeleted(['deleted' => '2026-01-01T00:00:00+00:00']);
		}

		return $object;
	}

	private function purge(array $input): CommandTester {
		$tester = new CommandTester(
			new PurgeObjectCommand(
				new SchemaCatalog($this->createMock(RegisterMapperInterface::class), $this->schemaMapper, $this->objectMapper),
				$this->createMock(AuditTrailMapperInterface::class)
			)
		);
		$tester->execute($input);

		return $tester;
	}

	public function testNamedObjectThatIsMissingIsAFailure(): void {
		$this->objectMapper->method('find')->willThrowException(new DoesNotExistException('no row'));
		$this->objectMapper->expects($this->never())->method('delete');

		$tester = $this->purge(['uuid' => ['obj-1'], '--apply' => true]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('obj-1: not found (no row)', $tester->getDisplay());
	}

	public function testFailedDeleteIsReportedAndExitsOne(): void {
		$this->objectMapper->method('find')->willReturn($this->object(null, true));
		$this->objectMapper->expects($this->once())->method('delete')
			->willThrowException(new RuntimeException('row locked'));

		$tester = $this->purge(['uuid' => ['obj-1'], '--apply' => true]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('obj-1: purge failed (row locked)', $tester->getDisplay());
		$this->assertStringNotContainsString('purged', $tester->getDisplay());
	}

	public function testObjectWithoutSchemaSkipsTheSchemaLookupAndPurges(): void {
		$object = $this->object(null, true);
		$this->objectMapper->method('find')->willReturn($object);
		$this->schemaMapper->expects($this->never())->method('find');
		$this->objectMapper->expects($this->once())->method('delete')->with($this->identicalTo($object))->willReturn($object);

		$tester = $this->purge(['uuid' => ['obj-1'], '--apply' => true]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('purged obj-1 from ""', $tester->getDisplay());
		$this->assertStringNotContainsString('ARCHIVAL', $tester->getDisplay());
	}

	public function testEmptySchemaIdAlsoSkipsTheSchemaLookup(): void {
		$object = $this->object('', true);
		$this->objectMapper->method('find')->willReturn($object);
		$this->schemaMapper->expects($this->never())->method('find');
		$this->objectMapper->expects($this->once())->method('delete')->willReturn($object);

		$tester = $this->purge(['uuid' => ['obj-1'], '--apply' => true]);

		$this->assertSame(0, $tester->getStatusCode());
	}

	public function testUnresolvableSchemaIsTreatedAsNonArchivalAndLabelledById(): void {
		$object = $this->object('172', true);
		$this->objectMapper->method('find')->willReturn($object);
		$this->schemaMapper->expects($this->once())->method('find')->with(172)
			->willThrowException(new DoesNotExistException('schema gone'));
		$this->objectMapper->expects($this->once())->method('delete')->willReturn($object);

		$tester = $this->purge(['uuid' => ['obj-1'], '--apply' => true]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('purged obj-1 from "172"', $tester->getDisplay());
		$this->assertStringNotContainsString('ARCHIVAL', $tester->getDisplay());
	}

	public function testUnresolvableSchemaStillRefusesALiveObject(): void {
		$this->objectMapper->method('find')->willReturn($this->object('172', false));
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('schema gone'));
		$this->objectMapper->expects($this->never())->method('delete');

		$tester = $this->purge(['uuid' => ['obj-1'], '--apply' => true]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('obj-1: refused — the object is live, not in the trash.', $tester->getDisplay());
	}
}

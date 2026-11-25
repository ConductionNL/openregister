<?php

/**
 * The translation completeness lookup resolves a schema slug inside the object's register.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\Support\TranslationControllerServices;
use OCA\OpenRegister\Controller\Support\TranslationMappers;
use OCA\OpenRegister\Controller\TranslationController;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Service\BulkTranslationServiceInterface;
use OCA\OpenRegister\Service\TranslationStatusServiceInterface;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Two registers carry a schema with one slug; the object's register decides which one completeness is measured on.
 *
 * @covers \OCA\OpenRegister\Controller\TranslationController
 * @uses   \OCA\OpenRegister\Controller\Support\TranslationControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\TranslationMappers
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class TranslationControllerSchemaSlugScopeTest extends TestCase {

	private TranslationStatusServiceInterface&MockObject $status;

	private SchemaMapperInterface&MockObject $schemas;

	private MagicMapperInterface&MockObject $objects;

	private RegisterMapperInterface&MockObject $registers;

	protected function setUp(): void {
		parent::setUp();
		$this->status = $this->createMock(TranslationStatusServiceInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->objects = $this->createMock(MagicMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
	}

	private function controller(?RegisterMapperInterface $registers): TranslationController {
		return new TranslationController(
			$this->createMock(IRequest::class),
			new TranslationControllerServices(
				$this->status,
				$this->createMock(BulkTranslationServiceInterface::class),
				new TranslationMappers($this->createMock(TranslationMapperInterface::class), $this->schemas, $this->objects, $registers)
			)
		);
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('agent');

		return $schema;
	}

	private function objectInRegister(string $registerId): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister($registerId);
		$this->objects->method('find')->with('obj-1')->willReturn($object);
	}

	public function testASlugResolvesToTheSchemaOfTheObjectsRegister(): void {
		$this->objectInRegister(registerId: '5');
		$register = new Register();
		$register->setSchemas([36, 37]);
		$this->registers->expects($this->once())->method('find')->with('5', false, false)->willReturn($register);
		$this->schemas->expects($this->once())->method('findBySlugInIds')->with('agent', [36, 37])->willReturn(self::schema(36));
		$this->schemas->expects($this->never())->method('find');
		$this->status->expects($this->once())->method('completenessForObject')
			->with('obj-1', $this->callback(static fn (Schema $s): bool => $s->getId() === 36))
			->willReturn(['percent' => 50]);

		$data = $this->controller(registers: $this->registers)->showByObject(uuid: 'obj-1', schema: 'agent')->getData();

		$this->assertSame(['percent' => 50], $data['completeness']);
	}

	public function testASlugTheRegisterLacksResolvesGloballyAsBefore(): void {
		$this->objectInRegister(registerId: '5');
		$register = new Register();
		$register->setSchemas([40]);
		$this->registers->method('find')->willReturn($register);
		$this->schemas->method('findBySlugInIds')->willReturn(null);
		$this->schemas->expects($this->once())->method('find')->with('agent', [], false, false)->willReturn(self::schema(22));
		$this->status->expects($this->once())->method('completenessForObject')
			->with('obj-1', $this->callback(static fn (Schema $s): bool => $s->getId() === 22))
			->willReturn(['percent' => 10]);

		$data = $this->controller(registers: $this->registers)->showByObject(uuid: 'obj-1', schema: 'agent')->getData();

		$this->assertSame(['percent' => 10], $data['completeness']);
	}

	public function testANumericIdNeverReadsTheObjectOrItsRegister(): void {
		$this->objects->expects($this->never())->method('find');
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->once())->method('find')->with('36', [], false, false)->willReturn(self::schema(36));
		$this->status->method('completenessForObject')->willReturn(['percent' => 1]);

		$data = $this->controller(registers: $this->registers)->showByObject(uuid: 'obj-1', schema: '36')->getData();

		$this->assertSame(['percent' => 1], $data['completeness']);
	}

	public function testWithoutARegisterReaderTheSlugResolvesGlobally(): void {
		$this->objects->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('findBySlugInIds');
		$this->schemas->expects($this->once())->method('find')->with('agent')->willReturn(self::schema(22));
		$this->status->method('completenessForObject')->willReturn([]);

		$this->controller(registers: null)->showByObject(uuid: 'obj-1', schema: 'agent');
	}
}

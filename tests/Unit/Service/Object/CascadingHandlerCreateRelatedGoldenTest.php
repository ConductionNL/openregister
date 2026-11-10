<?php

/**
 * Golden tests for CascadingHandler::createRelatedObject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\CascadeTarget;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\SaveObjectArgs;

/**
 * Pins which definitions create nothing, and the save, inverse link and tracking of a created object.
 *
 * @covers \OCA\OpenRegister\Service\Object\CascadingHandler
 * @uses   \OCA\OpenRegister\Service\Object\CascadeTarget
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class CascadingHandlerCreateRelatedGoldenTest extends TestCase {

	/**
	 * Calls that reached the save handler.
	 *
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * A handler whose only schema is `note`.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CascadingHandler The handler.
	 */
	private function handler(?LoggerInterface $logger = null): CascadingHandler {
		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findAll')->willReturn([$note]);

		$save = $this->createMock(SaveObject::class);
		$save->method('assertMayCascadeCreate')->willReturnCallback(
			function (Schema $schema): void {
				$this->calls[] = ['assert', $schema->getSlug()];
			}
		);
		$save->method('saveObject')->willReturnCallback(
			SaveObjectArgs::callback(callback: function ($register, $schema, array $data, $uuid, $rbac, $multi): ObjectEntity {
				$this->calls[] = ['save', $register, $schema->getSlug(), $data, $uuid, $rbac, $multi];
				if (($data['fail'] ?? false) === true) {
					throw new \RuntimeException('disk full');
				}

				$created = new ObjectEntity();
				$created->setUuid(($data['noUuid'] ?? false) === true ? null : 'new-uuid');
				$created->setObject(['text' => 'stored']);
				return $created;
			})
		);
		$save->method('trackCreatedSubObject')->willReturnCallback(
			function (string $uuid, array $objectData): void {
				$this->calls[] = ['track', $uuid, $objectData['text'] ?? null];
			}
		);

		return new CascadingHandler(
			saveHandler: $save,
			schemaMapper: $schemas,
			utilityHandler: $this->createMock(UtilityHandler::class),
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end handler()

	/**
	 * Definitions that create nothing.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function definitionsWithoutTarget(): array {
		return [
			'no ref' => [['type' => 'object']],
			'empty ref' => [['$ref' => '']],
			'not a components path' => [['$ref' => 'schemas/note']],
			'path ending in a slash' => [['$ref' => '#/components/schemas/']],
			'unknown schema' => [['$ref' => '#/components/schemas/memo']],
		];
	}//end definitionsWithoutTarget()

	/**
	 * A definition without a usable target creates nothing.
	 *
	 * @param array $definition The property definition.
	 *
	 * @return void
	 */
	#[DataProvider('definitionsWithoutTarget')]
	public function testADefinitionWithoutATargetCreatesNothing(array $definition): void {
		$this->assertNull($this->handler()->createRelatedObject(objectData: ['text' => 'x'], target: (new CascadeTarget(parentUuid: 'p-1', register: 4))->with(definition: $definition)));
		$this->assertSame([], $this->calls);
	}//end testADefinitionWithoutATargetCreatesNothing()

	public function testTheCreatedObjectIsLinkedBackStrippedOfIdentityAndTracked(): void {
		$uuid = $this->handler()->createRelatedObject(objectData: ['id' => 'client-id', 'text' => 'x', '@self' => ['id' => 'a', 'uuid' => 'b', 'slug' => 's']], target: (new CascadeTarget(parentUuid: 'p-1', register: 4))->with(definition: ['$ref' => '#/components/schemas/note', 'inversedBy' => 'incident']));

		$this->assertSame('new-uuid', $uuid);
		$this->assertSame(
			[
				['assert', 'note'],
				['save', 4, 'note', ['text' => 'x', '@self' => ['slug' => 's'], 'incident' => 'p-1'], null, true, true],
				['track', 'new-uuid', 'stored'],
			],
			$this->calls
		);
	}//end testTheCreatedObjectIsLinkedBackStrippedOfIdentityAndTracked()

	public function testWithoutInverseOrUuidNothingIsLinkedOrTracked(): void {
		$uuid = $this->handler()->createRelatedObject(objectData: ['noUuid' => true], target: (new CascadeTarget(parentUuid: 'p-1', register: null))->with(definition: ['$ref' => '#/components/schemas/note', 'inversedBy' => '']));

		$this->assertNull($uuid);
		$this->assertSame([['assert', 'note'], ['save', null, 'note', ['noUuid' => true], null, true, true]], $this->calls);
	}//end testWithoutInverseOrUuidNothingIsLinkedOrTracked()

	public function testAFailedSaveIsLoggedAndCreatesNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('[CascadingHandler] Failed to create related object: disk full');

		$this->assertNull(
			$this->handler(logger: $logger)->createRelatedObject(objectData: ['fail' => true], target: (new CascadeTarget(parentUuid: 'p-1', register: 4))->with(definition: ['$ref' => '#/components/schemas/note']))
		);
	}//end testAFailedSaveIsLoggedAndCreatesNothing()
}//end class

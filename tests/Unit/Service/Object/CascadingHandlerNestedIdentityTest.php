<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A nested inline object in a relations cascade is always created, never written onto a stored row.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\SaveObjectArgs;

/**
 * The save handler would take a nested object's `id` or `@self.id` as the uuid to write to, and
 * look it up past the read filters. So the cascade hands it the nested object without them.
 *
 * @covers \OCA\OpenRegister\Service\Object\CascadingHandler
 * @uses   \OCA\OpenRegister\Service\Object\CascadeTarget
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class CascadingHandlerNestedIdentityTest extends TestCase {

	private const STORED = '22222222-2222-2222-2222-222222222222';

	/**
	 * Every call to the save handler, as [data, uuid].
	 *
	 * @var array<int, array{0: array, 1: string|null}>
	 */
	private array $saves = [];

	/**
	 * A cascading handler over a `note` schema, whose save handler records what it is asked to write.
	 *
	 * @return CascadingHandler
	 */
	private function handler(): CascadingHandler {
		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findAll')->willReturn([$note]);

		$save = $this->createMock(SaveObject::class);
		$save->method('saveObject')->willReturnCallback(
			SaveObjectArgs::callback(callback: function ($register, $schema, array $data, ?string $uuid = null): ObjectEntity {
				$this->saves[] = [$data, $uuid];
				$created = new ObjectEntity();
				$created->setUuid('33333333-3333-3333-3333-333333333333');
				return $created;
			})
		);

		$utility = $this->createMock(UtilityHandler::class);
		$utility->method('isUuid')->willReturnCallback(
			static fn (mixed $value): bool => is_string($value) && preg_match('/^[0-9a-f-]{36}$/', $value) === 1
		);

		return new CascadingHandler(saveHandler: $save, schemaMapper: $schemas, utilityHandler: $utility, logger: new NullLogger());
	}//end handler()

	/**
	 * Create one nested note for parent `p-1`.
	 *
	 * @param array $note The nested object.
	 *
	 * @return string|null The created uuid.
	 */
	private function createNote(array $note): ?string {
		return $this->handler()->createRelatedObject(objectData: $note, target: (new CascadeTarget(parentUuid: 'p-1', register: 10))->with(definition: ['$ref' => '#/components/schemas/note', 'inversedBy' => 'incident']));
	}//end createNote()

	public function testANestedIdIsNotHandedToTheSave(): void {
		$created = $this->createNote(note: ['id' => self::STORED, 'text' => 'overwritten']);

		$this->assertSame('33333333-3333-3333-3333-333333333333', $created);
		$this->assertSame([[['text' => 'overwritten', 'incident' => 'p-1'], null]], $this->saves);
	}

	public function testANestedSelfIdAndSelfUuidAreNotHandedToTheSave(): void {
		$this->createNote(note: ['@self' => ['id' => self::STORED, 'uuid' => self::STORED, 'name' => 'kept'], 'text' => 'overwritten']);

		$this->assertSame([[['@self' => ['name' => 'kept'], 'text' => 'overwritten', 'incident' => 'p-1'], null]], $this->saves);
	}

	public function testANestedObjectWithoutAnIdIsCreatedAsSent(): void {
		$this->createNote(note: ['text' => 'new', '@self' => 'not an array']);

		$this->assertSame([[['text' => 'new', '@self' => 'not an array', 'incident' => 'p-1'], null]], $this->saves);
	}
}//end class

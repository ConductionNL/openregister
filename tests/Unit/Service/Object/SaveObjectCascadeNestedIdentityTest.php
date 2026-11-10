<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A nested inline object that the save handler cascades is always created, never written onto a stored row.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\NestedObjectTarget;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\SaveLookupsAccess;

/**
 * SaveObject::cascadeSingleObject resolves plain-slug, numeric and uuid `$ref`s that the
 * pre-validation cascade leaves alone. The nested object it saves must not carry the id of
 * a row to write onto: the save looks that id up past every read filter.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceStrictness
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RegisterReferenceLookup
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SaveObjectCascadeNestedIdentityTest extends TestCase {

	private const STORED = '22222222-2222-2222-2222-222222222222';

	/**
	 * Every call to the nested save, as [data, uuid].
	 *
	 * @var array<int, array{0: array, 1: string|null}>
	 */
	private array $saves = [];

	/**
	 * Cascade one nested object of a `note` schema into parent `p-1`.
	 *
	 * @param array $definition The property definition the nested object sits in.
	 * @param array $nested     The nested object.
	 *
	 * @return string|null The uuid the cascade returns.
	 */
	private function cascade(array $definition, array $nested): ?string {
		$handler = $this->getMockBuilder(SaveObject::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject'])
			->getMock();
		$handler->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data, ?string $uuid = null): ObjectEntity {
				$this->saves[] = [$data, $uuid];
				$created = new ObjectEntity();
				$created->setUuid('33333333-3333-3333-3333-333333333333');
				return $created;
			}
		);

		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');
		$note->setProperties(['text' => ['type' => 'string'], 'status' => ['type' => 'string']]);

		$reflection = new ReflectionClass(SaveObject::class);
		SaveLookupsAccess::set($handler, 'schemaReferenceCache', ['note' => '2']);
		SaveLookupsAccess::set($handler, 'schemaCache', ['2' => $note]);
		$reflection->getProperty('urlGenerator')->setValue($handler, $this->createMock(IURLGenerator::class));
		$reflection->getProperty('permissionHandler')->setValue($handler, $this->createMock(PermissionHandler::class));

		$parent = new ObjectEntity();
		$parent->setUuid('p-1');
		$parent->setRegister('10');

		return $reflection->getMethod('cascadeSingleObject')->invoke($handler, new NestedObjectTarget(parent: $parent, definition: $definition), $nested);
	}//end cascade()

	public function testANestedIdUnderAnInverseRelationIsNotWrittenOnto(): void {
		$created = $this->cascade(
			definition: ['$ref' => 'note', 'inversedBy' => 'incident'],
			nested: ['id' => self::STORED, 'text' => 'overwritten']
		);

		$this->assertSame('33333333-3333-3333-3333-333333333333', $created);
		$this->assertSame([[['text' => 'overwritten', 'incident' => 'p-1'], null]], $this->saves);
	}

	public function testANestedSelfIdAndSelfUuidAreNotWrittenOnto(): void {
		$this->cascade(
			definition: ['$ref' => 'note', 'inversedBy' => 'incident'],
			nested: ['@self' => ['id' => self::STORED, 'uuid' => self::STORED, 'name' => 'kept'], 'text' => 'overwritten']
		);

		$this->assertSame([[['@self' => ['name' => 'kept'], 'text' => 'overwritten', 'incident' => 'p-1'], null]], $this->saves);
	}

	public function testANestedIdGivenOnlyAsSelfIdIsNotWrittenOnto(): void {
		$this->cascade(
			definition: ['$ref' => 'note', 'inversedBy' => 'incident'],
			nested: ['@self' => ['id' => self::STORED], 'text' => 'overwritten']
		);

		$this->assertSame([[['@self' => [], 'text' => 'overwritten', 'incident' => 'p-1'], null]], $this->saves);
	}

	public function testANestedObjectWithANonArraySelfKeepsIt(): void {
		$this->cascade(
			definition: ['$ref' => 'note', 'inversedBy' => 'incident'],
			nested: ['text' => 'new', '@self' => 'not an array']
		);

		$this->assertSame([[['text' => 'new', '@self' => 'not an array', 'incident' => 'p-1'], null]], $this->saves);
	}

	public function testACascadeWithoutAnInverseRelationDropsTheWholeSelf(): void {
		$this->cascade(
			definition: ['$ref' => 'note', 'objectConfiguration' => ['handling' => 'cascade']],
			nested: ['id' => self::STORED, '@self' => ['id' => self::STORED, 'name' => 'dropped'], 'text' => 'new']
		);

		$this->assertSame([[['text' => 'new'], null]], $this->saves);
	}
}//end class

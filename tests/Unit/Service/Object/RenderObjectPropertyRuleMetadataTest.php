<?php

/**
 * Property read rules read the record's own metadata, not an `@self` in its data.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\EntityRead;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests the envelope RenderObject hands to the property read filter.
 *
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @uses \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses \OCA\OpenRegister\Service\Object\RecurrenceOccurrences
 * @uses \OCA\OpenRegister\Service\Object\VirtualCalculations
 * @uses \OCA\OpenRegister\Service\Object\InverseBatch
 * @uses \OCA\OpenRegister\Service\Object\InverseLookup
 * @uses \OCA\OpenRegister\Service\Object\ExtendTargetReads
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationCache
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationPreload
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationRender
 * @uses \OCA\OpenRegister\Service\Object\InverseRenderSupport
 * @uses \OCA\OpenRegister\Service\Object\InverseSchemaLookups
 * @uses \OCA\OpenRegister\Service\Object\InverseObjectHooks
 * @uses \OCA\OpenRegister\Service\Object\InverseTarget
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\EntityRead
 * @uses   \OCA\OpenRegister\Service\Object\WithheldProperties
 * @uses \OCA\OpenRegister\Service\Object\EntityRead
 * @uses \OCA\OpenRegister\Service\Object\WithheldProperties
 */
class RenderObjectPropertyRuleMetadataTest extends TestCase {

	/**
	 * What the property read filter was given.
	 *
	 * @var array<int|string, mixed>|null
	 */
	private ?array $seen = null;

	/**
	 * The filter reads the record's organisation and owner, even when the data carries its own `@self`.
	 *
	 * @return void
	 */
	public function testTheFilterReadsTheRecordsMetadata(): void {
		$result = $this->filter(data: ['title' => 't', '@self' => ['organisation' => 'org-a', 'owner' => 'alice']]);

		$this->assertSame(expected: 'org-b', actual: $this->seen['@self']['organisation']);
		$this->assertSame(expected: 'bob', actual: $this->seen['@self']['owner']);
		$this->assertSame(expected: 'rec-1', actual: $this->seen['@self']['id']);
		$this->assertSame(
			expected: ['title' => 't', '@self' => ['organisation' => 'org-a', 'owner' => 'alice']],
			actual: $result
		);
	}//end testTheFilterReadsTheRecordsMetadata()

	/**
	 * Data without `@self` comes back without one.
	 *
	 * @return void
	 */
	public function testDataWithoutSelfComesBackWithoutOne(): void {
		$result = $this->filter(data: ['title' => 't', 'secret' => 's']);

		$this->assertSame(expected: 'org-b', actual: $this->seen['@self']['organisation']);
		$this->assertSame(expected: ['title' => 't'], actual: $result);
	}//end testDataWithoutSelfComesBackWithoutOne()

	/**
	 * Run filterAuthorizedProperties for a record of `org-b` owned by `bob`.
	 *
	 * The filter double records its input and drops `secret`.
	 *
	 * @param array<string, mixed> $data The data being rendered.
	 *
	 * @return array<int|string, mixed>
	 */
	private function filter(array $data): array {
		$rbac = $this->createMock(originalClassName: PropertyRbacHandler::class);
		$rbac->method('filterReadableProperties')->willReturnCallback(
			function (Schema $schema, array $object): array {
				$this->seen = $object;
				unset($object['secret']);

				return $object;
			}
		);

		$renderer = (new ReflectionClass(objectOrClass: RenderObject::class))->newInstanceWithoutConstructor();
		Closure::bind(
			static function (RenderObject $target) use ($rbac): void {
				$target->propertyRbacHandler = $rbac;
			},
			null,
			RenderObject::class
		)($renderer);

		$record = new ObjectEntity();
		$record->setUuid('rec-1');
		$record->setOrganisation('org-b');
		$record->setOwner('bob');
		$record->setObject($data);

		$method = new ReflectionMethod(objectOrMethod: RenderObject::class, method: 'filterAuthorizedProperties');

		return $method->invoke($renderer, new EntityRead(entity: $record, schema: new Schema()), $data);
	}//end filter()
}//end class

<?php

/**
 * Unit tests for IdentityBreachCounter.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Schemas\IdentityBreachCounter;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A newly declared identity reports how many existing objects break it.
 */
#[CoversClass(IdentityBreachCounter::class)]
#[UsesClass(UniqueConstraintEvaluator::class)]
#[UsesClass(Schema::class)]
class IdentityBreachCounterTest extends TestCase {

	private const IDENTITY = ['name' => 'zaaksleutel', 'properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse', 'identity' => true];

	/**
	 * The schema with the identity declared.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setConfiguration(['uniqueConstraints' => [self::IDENTITY]]);

		return $schema;
	}//end schema()

	/**
	 * A counter over these objects, asserting how they are read.
	 *
	 * @param array<int, mixed> $objects The schema's objects.
	 * @param bool              $read    Whether the objects must be read at all.
	 *
	 * @return IdentityBreachCounter
	 */
	private function counter(array $objects, bool $read = true): IdentityBreachCounter {
		$service = $this->createMock(ObjectOperationsInterface::class);
		if ($read === true) {
			$service->expects(self::once())->method('findAll')
				->with(['filters' => ['schema' => 7], 'limit' => IdentityBreachCounter::SCAN_CAP], false, false)
				->willReturn($objects);
		} else {
			$service->expects(self::never())->method('findAll');
		}

		return new IdentityBreachCounter(objectService: $service);
	}//end counter()

	/**
	 * Two objects with one key are one breach; distinct and keyless objects break nothing.
	 *
	 * @return void
	 */
	public function testTwoDuplicateObjectsReportOneBreach(): void {
		$warning = $this->counter(
			objects: [
				['gemeentecode' => '0363', 'zaaknummer' => 'Z-1'],
				['gemeentecode' => '0363', 'zaaknummer' => 'Z-1'],
				['gemeentecode' => '0363', 'zaaknummer' => 'Z-2'],
				['gemeentecode' => '0363'],
			]
		)->warningFor(schema: self::schema(), previousConfiguration: []);

		$this->assertSame('zaaksleutel', $warning['identity']);
		$this->assertSame(1, $warning['breaches']);
		$this->assertFalse($warning['capped']);
		$this->assertSame(
			'1 existing object(s) share a key under the identity "zaaksleutel". A lookup by such a key answers 409 until the duplicates are made unique.',
			$warning['message']
		);
	}//end testTwoDuplicateObjectsReportOneBreach()

	/**
	 * Data that keeps the identity gives no warning.
	 *
	 * @return void
	 */
	public function testUniqueDataGivesNoWarning(): void {
		$warning = $this->counter(objects: [['gemeentecode' => '0363', 'zaaknummer' => 'Z-1'], ['gemeentecode' => '0599', 'zaaknummer' => 'Z-1']])
			->warningFor(schema: self::schema(), previousConfiguration: null);

		$this->assertNull($warning);
	}//end testUniqueDataGivesNoWarning()

	/**
	 * An identity already declared over the same properties is not counted again.
	 *
	 * @return void
	 */
	public function testAnUnchangedIdentityIsNotCounted(): void {
		$warning = $this->counter(objects: [], read: false)
			->warningFor(schema: self::schema(), previousConfiguration: ['uniqueConstraints' => [self::IDENTITY]]);

		$this->assertNull($warning);
	}//end testAnUnchangedIdentityIsNotCounted()

	/**
	 * An identity over other properties than before is counted.
	 *
	 * @return void
	 */
	public function testAnIdentityOverOtherPropertiesIsCounted(): void {
		$previous = ['uniqueConstraints' => [array_merge(self::IDENTITY, ['properties' => ['zaaknummer']])]];
		$entity   = new class {
			/**
			 * The object data, as an entity carries it.
			 *
			 * @return array<string, string>
			 */
			public function getObject(): array {
				return ['gemeentecode' => '0363', 'zaaknummer' => 'Z-9'];
			}
		};

		$warning = $this->counter(objects: [$entity, ['gemeentecode' => '0363', 'zaaknummer' => 'Z-9']])
			->warningFor(schema: self::schema(), previousConfiguration: $previous);

		$this->assertSame(1, $warning['breaches']);
	}//end testAnIdentityOverOtherPropertiesIsCounted()

	/**
	 * A schema without an identity reads nothing.
	 *
	 * @return void
	 */
	public function testASchemaWithoutAnIdentityReadsNothing(): void {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setConfiguration(['uniqueConstraints' => [array_merge(self::IDENTITY, ['identity' => false])]]);

		$this->assertNull($this->counter(objects: [], read: false)->warningFor(schema: $schema, previousConfiguration: null));
	}//end testASchemaWithoutAnIdentityReadsNothing()
}//end class

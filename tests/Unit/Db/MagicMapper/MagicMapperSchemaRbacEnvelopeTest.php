<?php

/**
 * The global-search RBAC filter matches rules on the record's own metadata.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
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

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Closure;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests what filterBySchemaRbac hands to the row-level permission check.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicMapperSchemaRbacEnvelopeTest extends TestCase {

	/**
	 * The record's organisation and owner reach the check through `@self`, over what the data says.
	 *
	 * @return void
	 */
	public function testTheCheckReadsTheRecordsMetadata(): void {
		$seen = [];
		$rbac = $this->createMock(originalClassName: MagicRbacHandler::class);
		$rbac->method('isAdmin')->willReturn(false);
		$rbac->method('hasPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ?string $objectOwner = null, ?array $objectData = null) use (&$seen): bool {
				$seen[] = $objectData;

				return true;
			}
		);

		$schema = new Schema();
		$schema->setId(5);
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$mapper = (new ReflectionClass(objectOrClass: MagicMapper::class))->newInstanceWithoutConstructor();
		Closure::bind(
			static function (MagicMapper $target) use ($rbac, $schemaMapper): void {
				$target->rbacHandler  = $rbac;
				$target->schemaMapper = $schemaMapper;
			},
			null,
			MagicMapper::class
		)($mapper);

		$record = new ObjectEntity();
		$record->setUuid('rec-1');
		$record->setSchema('5');
		$record->setOrganisation('org-b');
		$record->setOwner('bob');
		$record->setObject(['title' => 't', '_owner' => 'alice', '@self' => ['organisation' => 'org-a']]);

		$cache  = [];
		$method = new ReflectionMethod(objectOrMethod: MagicMapper::class, method: 'filterBySchemaRbac');
		$kept   = $method->invokeArgs($mapper, [[$record], &$cache, true]);

		$this->assertSame(expected: [$record], actual: $kept);
		$this->assertCount(expectedCount: 1, haystack: $seen);
		$this->assertSame(expected: 'org-b', actual: $seen[0]['@self']['organisation']);
		$this->assertSame(expected: 'bob', actual: $seen[0]['@self']['owner']);
		$this->assertArrayNotHasKey(key: '_owner', array: $seen[0]);
		$this->assertArrayNotHasKey(key: '_organisation', array: $seen[0]);
		$this->assertSame(expected: 't', actual: $seen[0]['title']);
	}//end testTheCheckReadsTheRecordsMetadata()
}//end class

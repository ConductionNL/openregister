<?php

/**
 * Behaviour test for GdprEntityMapper lookups and OrganisationScopeTable.
 *
 * Asserts that find() binds the id as an integer and throws on a miss, that
 * findEntitiesPublic hydrates what the query returns, and that a scope table is
 * named exactly when it carries a register or a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeTable;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class GdprEntityMapperAndScopeTableBehaviourTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(array $rows): GdprEntityMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return new GdprEntityMapper($db);
	}

	public function testFindBindsTheIdAsAnInteger(): void {
		$found = $this->mapper([[['id' => 12, 'uuid' => 'e12']]])->find(12);
		$this->assertSame('e12', $found->getUuid());
		$this->assertContains('#1 from(openregister_entities, NULL)', $this->queryLog);
		$this->assertContains('#1 param :p1 = 12, 1', $this->queryLog);
		$this->assertContains('#1 expr.eq(id, :p1, NULL)', $this->queryLog);
	}

	public function testFindThrowsOnAMiss(): void {
		$this->expectException(DoesNotExistException::class);
		$this->mapper([[]])->find(99);
	}

	public function testFindEntitiesPublicHydratesEveryRow(): void {
		$mapper = $this->mapper([[['id' => 1, 'uuid' => 'a'], ['id' => 2, 'uuid' => 'b']]]);
		$found = $mapper->findEntitiesPublic($this->recordingBuilder());
		$this->assertSame(['a', 'b'], array_map(static fn (GdprEntity $e): string => $e->getUuid(), $found));
	}

	public function testAScopeTableIsNamedByARegisterOrASchema(): void {
		$this->assertFalse((new OrganisationScopeTable(null, null))->isNamed());
		$this->assertTrue((new OrganisationScopeTable(3, null))->isNamed());
		$this->assertTrue((new OrganisationScopeTable(null, 4))->isNamed());
		$this->assertSame([3, 4], [(new OrganisationScopeTable(3, 4))->registerId, (new OrganisationScopeTable(3, 4))->schemaId]);
	}
}

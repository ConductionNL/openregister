<?php

/**
 * A uuid or slug is never bound as an integer.
 *
 * 🔴 LIVE DEFECT (live pass, 5 Oct, O4): GET /api/views/{uuid} answered 500 on
 * PostgreSQL with "invalid input syntax for type integer". ViewMapper::find()
 * built `id = :p OR uuid = :q` and bound the uuid string as PARAM_INT for the
 * id side; PostgreSQL refuses that cast for the whole query, so the uuid side
 * never gets a chance. MySQL and SQLite cast silently, which is why only the
 * PostgreSQL instance saw it. SchemaMapper::loadSchema() (allOf/anyOf/oneOf
 * references by uuid or slug) had the same binding.
 *
 * These tests capture every named parameter the mapper binds and fail on a
 * non-numeric value bound as PARAM_INT: the exact thing PostgreSQL refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/mariadb-ci-matrix/spec.md#requirement-a-text-identifier-is-never-bound-as-an-integer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IdOrUuidBindingTest extends TestCase {

	/**
	 * Every [value, type] the mapper bound.
	 *
	 * @var array<int, array{0: mixed, 1: mixed}>
	 */
	private array $bound = [];

	/**
	 * Columns the WHERE compared, in order.
	 *
	 * @var array<int, string>
	 */
	private array $compared = [];

	/**
	 * A connection whose query builder records what is bound and answers one row.
	 *
	 * @param array<string, mixed> $row The row the query returns.
	 *
	 * @return IDBConnection
	 */
	private function connection(array $row): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function ($column, $param): string {
				$this->compared[] = (string) $column;
				return $column . ' = ' . $param;
			}
		);
		$expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls($row, false);
		$result->method('fetchAll')->willReturn([$row]);

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orWhere', 'setMaxResults', 'orderBy'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = IQueryBuilder::PARAM_STR): string {
				$this->bound[] = [$value, $type];
				return ':p' . count($this->bound);
			}
		);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end connection()

	/**
	 * Fail on a non-numeric value bound as an integer.
	 *
	 * @return void
	 */
	private function assertNoTextBoundAsInteger(): void {
		$this->assertNotSame([], $this->bound);
		foreach ($this->bound as [$value, $type]) {
			if ($type === IQueryBuilder::PARAM_INT) {
				$this->assertTrue(
					is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true),
					'PostgreSQL refuses ' . var_export($value, true) . ' bound as an integer'
				);
			}
		}
	}//end assertNoTextBoundAsInteger()

	/**
	 * The view mapper over the recording connection.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ViewMapper
	 */
	private function views(array $row): ViewMapper {
		return new ViewMapper(
			$this->connection($row),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IEventDispatcher::class)
		);
	}//end views()

	/**
	 * 🔴 The live case: a view found by its uuid binds no text as an integer.
	 *
	 * @return void
	 */
	public function testAViewFoundByUuidBindsNoTextAsAnInteger(): void {
		$uuid = '04d8079a-b84a-410e-b5df-75f04a130068';
		$view = $this->views(['id' => 1, 'uuid' => $uuid, 'name' => 'alert'])->find($uuid, _rbac: false, _multitenancy: false);

		$this->assertNoTextBoundAsInteger();
		$this->assertSame(['uuid'], $this->compared, 'a uuid is compared with the uuid column only');
		$this->assertSame($uuid, $view->getUuid());
	}//end testAViewFoundByUuidBindsNoTextAsAnInteger()

	/**
	 * A numeric id still finds the view by id (or by a numeric uuid string).
	 *
	 * @return void
	 */
	public function testAViewFoundByIdStillComparesTheId(): void {
		$this->views(['id' => 1, 'uuid' => 'u-1', 'name' => 'alert'])->find('1', _rbac: false, _multitenancy: false);

		$this->assertNoTextBoundAsInteger();
		$this->assertContains('id', $this->compared);
	}//end testAViewFoundByIdStillComparesTheId()

	/**
	 * 🔴 A schema reference by uuid or slug (allOf/anyOf/oneOf) binds no text as an integer.
	 *
	 * @return void
	 */
	public function testASchemaReferenceBySlugBindsNoTextAsAnInteger(): void {
		$mapper = new SchemaMapper(
			$this->connection(['id' => 7, 'uuid' => 'parent-uuid', 'slug' => 'parent', 'title' => 'Parent']),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);

		$load = new \ReflectionMethod(SchemaMapper::class, 'loadSchema');
		$load->setAccessible(true);
		$schema = $load->invoke($mapper, 'parent');

		$this->assertNoTextBoundAsInteger();
		$this->assertNotContains('id', $this->compared, 'a slug is never compared with the integer id column');
		$this->assertSame('parent', $schema->getSlug());
	}//end testASchemaReferenceBySlugBindsNoTextAsAnInteger()
}//end class

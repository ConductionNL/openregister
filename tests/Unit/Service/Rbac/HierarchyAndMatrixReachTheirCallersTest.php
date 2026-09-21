<?php

/**
 * The two round-2 RBAC features, asserted from the code that consumes them.
 *
 * Both were fully built, both had green unit suites, and neither did anything
 * on a live instance. Neither failure was in the service that owns the
 * feature: each was one layer up, in the code that hands the service its
 * input, which is why every existing test passed.
 *
 * - `HierarchyDescender::hierarchicalTables()` gated on the REGISTER's
 *   `schemas` list. A register created over the API and written to directly
 *   keeps that list empty while its magic table fills with objects, so the
 *   gate answered "no register holds this schema" and the descent had nothing
 *   to walk.
 * - `PermissionHandler::stripMcpScope()` read the `matrix` control key as an
 *   action rule list and REINDEXED it, so `compileDepartmentMatrix()` was
 *   handed a list with every key gone and compiled nothing at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use Doctrine\DBAL\Schema\Schema as DbalSchema;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The layer above each feature hands it what it needs.
 */
class HierarchyAndMatrixReachTheirCallersTest extends TestCase {

	/**
	 * A hierarchical schema whose objects live in register 34.
	 *
	 * @return Schema
	 */
	private function hierarchicalSchema(): Schema {
		$schema = new Schema();
		$schema->setId(987);
		$schema->setSlug('zaak');
		$schema->setProperties(['parentObject' => ['type' => 'string', '$ref' => 'zaak']]);
		$schema->setConfiguration(
			[
				HierarchyGrantExpander::ANNOTATION => [
					'parent' => 'parentObject',
					'maxDepth' => 5,
					'inheritedVerbs' => ['read'],
				],
			]
		);

		return $schema;
	}//end hierarchicalSchema()

	/**
	 * A descender over one register whose `schemas` list is empty.
	 *
	 * @param array<int, mixed>|null $registerSchemas What the register claims to hold.
	 *
	 * @return HierarchyDescender
	 */
	private function descenderFor(?array $registerSchemas): HierarchyDescender {
		$register = new Register();
		$register->setId(34);
		$register->setSchemas($registerSchemas);

		$dbal = new DbalSchema();
		$table = $dbal->createTable('oc_openregister_table_34_987');
		$table->addColumn('_uuid', 'string', ['length' => 40]);
		$table->addColumn('parent_object', 'string', ['length' => 40, 'notnull' => false]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('createSchema')->willReturn($dbal);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findAll')->willReturn([$this->hierarchicalSchema()]);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$register]);

		return new HierarchyDescender(
			db: $db,
			schemaMapper: $schemas,
			registerMapper: $registers,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end descenderFor()

	/**
	 * The table is what says a register holds a hierarchy, not the bookkeeping.
	 *
	 * @return void
	 */
	public function testARegisterWithAnEmptySchemasListStillDescends(): void {
		$resolved = $this->descenderFor(registerSchemas: [])->hierarchicalTables();

		$this->assertSame(
			[
				[
					'table' => 'openregister_table_34_987',
					'parentColumn' => 'parent_object',
					'maxDepth' => 5,
					'verbs' => ['read'],
					'schemaId' => 987,
				],
			],
			$resolved,
			'a register that never listed its schema descends nothing, so no grant is inherited'
		);
	}//end testARegisterWithAnEmptySchemasListStillDescends()

	/**
	 * A register that DOES list the schema still descends.
	 *
	 * The control: the fix must not have swapped one gate for its opposite.
	 *
	 * @return void
	 */
	public function testARegisterThatListsTheSchemaStillDescends(): void {
		$this->assertCount(
			1,
			$this->descenderFor(registerSchemas: [987])->hierarchicalTables()
		);
	}//end testARegisterThatListsTheSchemaStillDescends()

	/**
	 * A register with no such table descends nothing.
	 *
	 * The second control, and the one that proves the table is being consulted
	 * at all: without it the test above would pass on a descender that
	 * returned every register unconditionally.
	 *
	 * @return void
	 */
	public function testARegisterWithNoSuchTableDescendsNothing(): void {
		$register = new Register();
		$register->setId(99);
		$register->setSchemas([987]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('createSchema')->willReturn(new DbalSchema());

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findAll')->willReturn([$this->hierarchicalSchema()]);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$register]);

		$descender = new HierarchyDescender(
			db: $db,
			schemaMapper: $schemas,
			registerMapper: $registers,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertSame([], $descender->hierarchicalTables());
	}//end testARegisterWithNoSuchTableDescendsNothing()

	/**
	 * The matrix declaration reaches the compiler with its keys intact.
	 *
	 * Asserted through BOTH components, in the order the request takes them,
	 * because the bug lived between them: the compiler was correct on the
	 * input its own tests gave it, and the strip was correct about mcp scopes.
	 * Only the handover was wrong, and only a test that crosses it can see
	 * that.
	 *
	 * @return void
	 */
	public function testAMatrixSurvivesTheMcpStripAndStillCompiles(): void {
		$authorization = [
			'create' => ['authenticated'],
			'update' => ['group:behandelaars'],
			DepartmentMatrixCompiler::KEY => [
				'field' => 'department',
				'userSource' => ['groupPrefix' => 'dept:'],
				'rows' => [
					['value' => '$self', 'group' => 'behandelaars', 'actions' => ['read']],
				],
			],
		];

		$stripped = PermissionHandler::stripMcpScope(authorization: $authorization);

		$this->assertSame(
			$authorization[DepartmentMatrixCompiler::KEY],
			($stripped[DepartmentMatrixCompiler::KEY] ?? null),
			'the matrix was read as a rule list and reindexed, so its keys are gone'
		);

		$compiler = new DepartmentMatrixCompiler();
		$compiled = $compiler->compile(
			matrix: $stripped[DepartmentMatrixCompiler::KEY],
			ownValues: $compiler->valuesFromGroups(
				source: $stripped[DepartmentMatrixCompiler::KEY]['userSource'],
				userGroups: ['behandelaars', 'dept:VTH']
			)
		);

		$this->assertSame(
			[
				'read' => [
					[
						'group' => 'behandelaars',
						'match' => ['department' => ['$in' => ['VTH']]],
					],
				],
			],
			$compiled,
			'the matrix compiled to no rule at all, so the schema grants read to nobody'
		);
	}//end testAMatrixSurvivesTheMcpStripAndStillCompiles()

	/**
	 * A role assignment survives the strip with its role names.
	 *
	 * The same shape, on the other control key that is a map: read as a rule
	 * list, `roles` came out as a list of group arrays with every role name
	 * discarded.
	 *
	 * @return void
	 */
	public function testARoleAssignmentSurvivesTheMcpStrip(): void {
		$roles = ['behandelaar' => ['afdeling-a'], 'lezer' => ['iedereen']];

		$stripped = PermissionHandler::stripMcpScope(
			authorization: ['read' => ['authenticated'], 'roles' => $roles]
		);

		$this->assertSame($roles, ($stripped['roles'] ?? null));
	}//end testARoleAssignmentSurvivesTheMcpStrip()

	/**
	 * The mcp scope is still stripped from an ordinary action list.
	 *
	 * The control for both strip tests: carrying control keys through must not
	 * turn the strip into a no-op.
	 *
	 * @return void
	 */
	public function testTheMcpScopeIsStillStrippedFromAnActionList(): void {
		$stripped = PermissionHandler::stripMcpScope(
			authorization: ['read' => ['authenticated', 'mcp']]
		);

		$this->assertSame(['read' => ['authenticated']], $stripped);
	}//end testTheMcpScopeIsStillStrippedFromAnActionList()
}//end class

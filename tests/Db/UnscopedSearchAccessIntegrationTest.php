<?php

/**
 * A search that names no register or schema reads under each table's own rules.
 *
 * `_ids` and `_relations_contains` find their rows with a scan of every magic
 * table, and that scan applies no read rules. Only a real database shows that
 * the rows of another organisation stay out of the answer, on the sequential
 * path of one table and the UNION path of several.
 *
 * The session user is a real, non-admin user and every fixture is owned by
 * someone else, because a row's owner is admitted whatever its organisation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class UnscopedSearchAccessIntegrationTest extends TestCase {

	/**
	 * The organisation of the rows the session user may not read.
	 *
	 * @var string
	 */
	private const OTHER_ORGANISATION = '00000000-0000-4000-8000-00000000beef';

	private MagicMapper $mapper;

	private RegisterMapper $registerMapper;

	private SchemaMapper $schemaMapper;

	private IUserSession $userSession;

	private IDBConnection $db;

	private ?IUser $user = null;

	private string $organisation = '';

	/**
	 * @var array<int, array{register: Register, schema: Schema}>
	 */
	private array $tables = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper         = \OC::$server->get(MagicMapper::class);
		$this->registerMapper = \OC::$server->get(RegisterMapper::class);
		$this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
		$this->userSession    = \OC::$server->get(IUserSession::class);
		$this->db             = \OC::$server->get(IDBConnection::class);

		$uid        = 'unscoped-' . substr((string)Uuid::v4(), 0, 8);
		$this->user = \OC::$server->get(IUserManager::class)->createUser($uid, 'Unscoped-Test-Pass-123');
		$this->userSession->setUser($this->user);

		$organisation = \OC::$server->get(OrganisationService::class)->getActiveOrganisation()?->getUuid();
		$this->assertNotNull($organisation, 'the session user needs an active organisation');
		$this->organisation = $organisation;
	}//end setUp()

	protected function tearDown(): void {
		$this->userSession->setUser(null);
		$this->user?->delete();

		foreach ($this->tables as $table) {
			$name = $this->mapper->getTableNameForRegisterSchema($table['register'], $table['schema']);
			$this->db->prepare('DROP TABLE IF EXISTS oc_' . $name)->execute();
			$this->deleteRow(table: 'openregister_schemas', id: $table['schema']->getId());
			$this->deleteRow(table: 'openregister_registers', id: $table['register']->getId());
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * An id of another organisation answers nothing, on the rows and on the total.
	 *
	 * @return void
	 */
	public function testAnIdOfAnotherOrganisationIsNotAnswered(): void {
		$table  = $this->table();
		$theirs = $this->row(table: $table, organisation: self::OTHER_ORGANISATION);

		$result = $this->search(query: ['_ids' => [$theirs]]);

		$this->assertSame([], $this->uuids(result: $result));
		$this->assertSame(0, $result['total']);
		$this->assertSame([], $result['schemas']);
		$this->assertSame([], $result['registers']);
	}//end testAnIdOfAnotherOrganisationIsNotAnswered()

	/**
	 * The control: a caller in the row's organisation still gets it, with its table.
	 *
	 * @return void
	 */
	public function testAnIdOfTheCallersOrganisationIsAnswered(): void {
		$table  = $this->table();
		$mine   = $this->row(table: $table, organisation: $this->organisation);
		$theirs = $this->row(table: $table, organisation: self::OTHER_ORGANISATION);

		$result = $this->search(query: ['_ids' => [$mine, $theirs]]);

		$this->assertSame([$mine], $this->uuids(result: $result));
		$this->assertSame(1, $result['total']);
		$this->assertSame([$table['schema']->getId()], array_keys($result['schemas']));
		$this->assertSame([$table['register']->getId()], array_keys($result['registers']));
	}//end testAnIdOfTheCallersOrganisationIsAnswered()

	/**
	 * Ids in several tables take the UNION path, which draws the same line.
	 *
	 * @return void
	 */
	public function testIdsAcrossTablesKeepTheOrganisationBoundary(): void {
		$first  = $this->table();
		$second = $this->table();
		$mine   = [
			$this->row(table: $first, organisation: $this->organisation),
			$this->row(table: $second, organisation: $this->organisation),
		];
		$theirs = $this->row(table: $second, organisation: self::OTHER_ORGANISATION);
		$this->row(table: $first, organisation: $this->organisation);

		$result = $this->search(query: ['_ids' => [...$mine, $theirs]]);

		sort($mine);
		$this->assertSame($mine, $this->uuids(result: $result));
		$this->assertSame(2, $result['total']);
	}//end testIdsAcrossTablesKeepTheOrganisationBoundary()

	/**
	 * A relation search keeps to the caller's organisation, and still finds their row.
	 *
	 * @return void
	 */
	public function testARelationSearchKeepsTheOrganisationBoundary(): void {
		$table  = $this->table();
		$target = (string)Uuid::v4();
		$mine   = $this->row(table: $table, organisation: $this->organisation, relations: ['ref' => $target]);
		$this->row(table: $table, organisation: self::OTHER_ORGANISATION, relations: ['ref' => $target]);

		$result = $this->search(query: ['_relations_contains' => $target]);

		$this->assertSame([$mine], $this->uuids(result: $result));
		$this->assertSame(1, $result['total']);
	}//end testARelationSearchKeepsTheOrganisationBoundary()

	/**
	 * A request cannot switch the boundary off with its own flags.
	 *
	 * @return void
	 */
	public function testFlagsInTheQueryDoNotLiftTheBoundary(): void {
		$theirs = $this->row(table: $this->table(), organisation: self::OTHER_ORGANISATION);

		$result = $this->search(query: ['_ids' => [$theirs], '_rbac' => false, '_multitenancy' => false]);

		$this->assertSame([], $this->uuids(result: $result));
	}//end testFlagsInTheQueryDoNotLiftTheBoundary()

	/**
	 * A trusted caller that turns multitenancy off reads across organisations, as before.
	 *
	 * @return void
	 */
	public function testACallerWithoutMultitenancyReadsTheRow(): void {
		$theirs = $this->row(table: $this->table(), organisation: self::OTHER_ORGANISATION);

		$result = $this->mapper->searchObjectsPaginated(
			searchQuery: ['_ids' => [$theirs], '_limit' => 20],
			countQuery: ['_ids' => [$theirs]],
			_multitenancy: false
		);

		$this->assertSame([$theirs], $this->uuids(result: $result));
		$this->assertSame(1, $result['total']);
	}//end testACallerWithoutMultitenancyReadsTheRow()

	/**
	 * The other query shapes that name no table answer nothing at all.
	 *
	 * @return void
	 */
	public function testTheCountAndTheUnpagedSearchAnswerNothing(): void {
		$mine = $this->row(table: $this->table(), organisation: $this->organisation);

		$this->assertSame(0, $this->mapper->countSearchObjects(query: ['_ids' => [$mine]]));
		$this->assertSame([], $this->mapper->searchObjects(query: ['_ids' => [$mine]]));
	}//end testTheCountAndTheUnpagedSearchAnswerNothing()

	/**
	 * The cross-table search over named tables keeps the boundary on its rows and total.
	 *
	 * @return void
	 */
	public function testTheCrossTableSearchKeepsTheOrganisationBoundary(): void {
		$first  = $this->table();
		$second = $this->table();
		$mine   = $this->row(table: $first, organisation: $this->organisation);
		$theirs = $this->row(table: $second, organisation: self::OTHER_ORGANISATION);
		$query  = ['_ids' => [$mine, $theirs], '_rbac' => true, '_multitenancy' => true, '_limit' => 20];

		$rows = $this->mapper->searchAcrossMultipleTables(query: $query, registerSchemaPairs: [$first, $second]);

		$this->assertSame([$mine], $this->uuids(result: ['results' => $rows]));
		$this->assertSame(1, $this->mapper->countAcrossMultipleTables(query: $query, registerSchemaPairs: [$first, $second]));
	}//end testTheCrossTableSearchKeepsTheOrganisationBoundary()

	/**
	 * The search of a caller with the default flags.
	 *
	 * @param array<string, mixed> $query The query, naming no register or schema.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function search(array $query): array {
		return $this->mapper->searchObjectsPaginated(searchQuery: $query + ['_limit' => 20], countQuery: $query);
	}//end search()

	/**
	 * The uuids of an answer, sorted.
	 *
	 * @param array<string, mixed> $result The answer.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function uuids(array $result): array {
		$uuids = array_map(static fn (ObjectEntity $row): string => (string)$row->getUuid(), $result['results']);
		sort($uuids);

		return $uuids;
	}//end uuids()

	/**
	 * A register and schema with a table, whose schema sets no read rules.
	 *
	 * @return array{register: Register, schema: Schema} The table.
	 */
	private function table(): array {
		$register = $this->registerMapper->createFromArray(['title' => 'PHPUnit unscoped ' . uniqid()]);
		$schema   = $this->schemaMapper->createFromArray(
			[
				'title'      => 'PHPUnit unscoped ' . uniqid(),
				'properties' => ['ref' => ['type' => 'string', 'maxLength' => 255]],
			]
		);
		$this->mapper->ensureTableForRegisterSchema($register, $schema);

		$table          = ['register' => $register, 'schema' => $schema];
		$this->tables[] = $table;

		return $table;
	}//end table()

	/**
	 * Store a row of an organisation, owned by neither caller.
	 *
	 * @param array<string, mixed> $table        The table.
	 * @param string               $organisation The row's organisation.
	 * @param array<string, mixed> $relations    The row's relations.
	 *
	 * @return string The row's uuid.
	 */
	private function row(array $table, string $organisation, array $relations = []): string {
		$entity = new ObjectEntity();
		$entity->setUuid(Uuid::v4()->toRfc4122());
		$entity->setRegister((string)$table['register']->getId());
		$entity->setSchema((string)$table['schema']->getId());
		$entity->setObject($relations);
		$entity->setOwner('unscoped-other-owner');
		$entity->setOrganisation($organisation);
		$entity->setRelations($relations);

		return (string)$this->mapper->insertObjectEntity($entity, $table['register'], $table['schema'], false)->getUuid();
	}//end row()

	/**
	 * Delete one row by id.
	 *
	 * @param string $table The table.
	 * @param int    $id    The id.
	 *
	 * @return void
	 */
	private function deleteRow(string $table, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end deleteRow()
}//end class

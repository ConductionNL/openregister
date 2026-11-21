<?php

/**
 * Golden record of list() in the link-table providers with their own fallback.
 *
 * For Cospend, Forms and Time: the app missing, linked rows over a database that
 * answers or fails, and the legacy marker fallback (failing link mapper, no
 * links) over a database that answers, is empty or fails. Every query is
 * recorded with its table and parameters. The recording lives in
 * fixtures/link-table-fallback-golden.json; set LINK_FALLBACK_GOLDEN_RECORD=1 to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pins the list shape of the Cospend, Forms and Time providers.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db;
use OCA\OpenRegister\Service\Integration\Providers;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use OCA\OpenRegister\Service\Integration\Providers\LeafMarkerScanning;
use OCA\OpenRegister\Service\Integration\Providers\LeafProviderPlatform;

/**
 * list() of the link-table providers with a fallback.
 *
 * @covers \OCA\OpenRegister\Service\Integration\Providers\CospendProvider
 * @covers \OCA\OpenRegister\Service\Integration\Providers\FormsProvider
 * @covers \OCA\OpenRegister\Service\Integration\Providers\TimeProvider
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses   \OCA\OpenRegister\Service\FormsAppRows
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\LeafMarkerScanning
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\LeafProviderPlatform
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\MarkerScan
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\MarkerLookupTrait
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\NcAppLinkTableProvider
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\LinkTableAppDescriptor
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\FirstRowQuery
 * @uses   \OCA\OpenRegister\Db\CospendLink
 * @uses   \OCA\OpenRegister\Db\FormLink
 * @uses   \OCA\OpenRegister\Db\TimeTrackerLink
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Service\FormsAppRows
 * @uses \OCA\OpenRegister\Service\Integration\Providers\LeafMarkerScanning
 * @uses \OCA\OpenRegister\Service\Integration\Providers\LeafProviderPlatform
 * @uses \OCA\OpenRegister\Service\Integration\Providers\MarkerScan
 */
class LinkTableFallbackGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/link-table-fallback-golden.json';

	/**
	 * The rows each table holds when the database answers.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private const TABLES = [
		'cospend_projects' => [
			['id' => 'p1', 'name' => 'Trip [or:obj-1]', 'user_id' => 'u1', 'currency_name' => 'EUR', 'last_changed' => '1700000000'],
			['id' => 'p2', 'name' => '[or:obj-1]'],
		],
		'cospend_bills' => [
			['id' => 4, 'what' => 'Food', 'amount' => '12.5', 'timestamp' => '1700000100', 'payer_id' => '3'],
			['id' => 5],
		],
		'forms_v2_forms' => [
			['id' => 21, 'hash' => 'h21', 'title' => 'Live [or:obj-1]', 'description' => 'D', 'expires' => 1, 'state' => 0],
			['id' => 22, 'title' => 'Draft', 'expires' => 0, 'state' => 2],
			['id' => 23, 'hash' => 'h23', 'title' => 'Future', 'expires' => 253402214400, 'state' => 0],
			['id' => 24, 'state' => 1],
		],
		'forms_v2_submissions' => [['n' => 1]],
		'timemanager_client' => [
			['id' => 1, 'uuid' => 'c-1', 'name' => 'Client', 'note' => '[or:obj-1]'],
			['id' => 2, 'note' => '[or:obj-1]'],
		],
		'timemanager_task' => [
			['id' => 3, 'uuid' => 't-1', 'name' => 'Task', 'note' => '[or:obj-1]'],
		],
	];

	/**
	 * The queries the database was asked.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * How the database behaves: 'answers', 'empty' or 'fails'.
	 *
	 * @var string
	 */
	private string $dbMode = 'answers';

	/**
	 * A recording query builder.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$index = count($this->queries);
		$this->queries[] = ['calls' => []];
		$log = function (string $call, array $args) use ($index): void {
			$this->queries[$index]['calls'][] = [$call, array_map(static fn ($a) => is_object($a) ? 'object' : $a, $args)];
		};
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($col, $val) => 'eq('.$col.','.$val.')');
		$expr->method('iLike')->willReturnCallback(static fn ($col, $val) => 'ilike('.$col.','.$val.')');
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $log, $method, $index) {
					$log($method, $args);
					if ($method === 'from') {
						$this->queries[$index]['table'] = $args[0];
					}
					return $qb;
				}
			);
		}
		$qb->method('createNamedParameter')->willReturnCallback(
			function (...$args) use ($log): string {
				$log('param', $args);
				return ':p'.json_encode($args[0]);
			}
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $call) use ($log): IQueryFunction {
				$log('function', [$call]);
				return $this->createMock(IQueryFunction::class);
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->tableResult(table: (string)($this->queries[$index]['table'] ?? '')));
		return $qb;
	}//end queryBuilder()

	/**
	 * The result a table gives in the current database mode.
	 *
	 * @param string $table The table.
	 *
	 * @return IResult The result.
	 */
	private function tableResult(string $table): IResult {
		$rows = self::TABLES[$table] ?? [];
		if ($this->dbMode === 'empty') {
			$rows = [];
		}
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetchOne')->willReturn($rows === [] ? false : (string)count($rows));
		return $result;
	}//end tableResult()

	/**
	 * A database whose query builders record and answer per table.
	 *
	 * @return IDBConnection The connection.
	 */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $v): string => 'esc('.$v.')');
		$db->method('getQueryBuilder')->willReturnCallback(
			function (): IQueryBuilder {
				if ($this->dbMode === 'fails') {
					$this->queries[] = ['failed' => true];
					throw new RuntimeException('db down');
				}
				return $this->queryBuilder();
			}
		);
		return $db;
	}//end db()

	/**
	 * A container holding the Cospend mappers, or none.
	 *
	 * @param bool $withMappers Whether the Cospend mappers resolve.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(bool $withMappers): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($withMappers): object {
				if ($withMappers === false) {
					throw new RuntimeException('no '.$id);
				}
				$table = str_ends_with($id, 'ProjectMapper') ? 'cospend_projects' : 'cospend_bills';
				return new class($table) {
					public function __construct(private string $table) {
					}
					public function getTableName(): string {
						return $this->table;
					}
				};
			}
		);
		return $container;
	}//end container()

	/**
	 * Build one provider.
	 *
	 * @param string $name      cospend, cospend-without-mappers, forms or time.
	 * @param bool   $installed Whether the app is installed.
	 * @param mixed  $links     Rows the mapper returns, or an exception.
	 *
	 * @return object The provider.
	 */
	private function provider(string $name, bool $installed, mixed $links): object {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed && in_array($app, ['cospend', 'forms', 'timemanager'], true));
		$l10n = $this->createMock(IL10N::class);
		if (str_starts_with($name, 'cospend')) {
			$mapper = $this->mapper(Db\CospendLinkMapper::class, $links);
			return new Providers\CospendProvider(new LeafProviderPlatform($this->db(), $apps, $l10n), $mapper, $this->container($name === 'cospend'));
		}
		if ($name === 'forms') {
			return new Providers\FormsProvider(new LeafMarkerScanning($this->db(), $apps, $l10n, new NullLogger()), $this->mapper(Db\FormLinkMapper::class, $links));
		}
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('timemanager');
		return new Providers\TimeProvider(new LeafMarkerScanning($this->db(), $apps, $l10n, new NullLogger()), $this->mapper(Db\TimeTrackerLinkMapper::class, $links), $config);
	}//end provider()

	/**
	 * A link mapper returning rows or throwing.
	 *
	 * @param class-string $mapperClass The mapper class.
	 * @param mixed        $links       Rows, or an exception.
	 *
	 * @return object The mapper.
	 */
	private function mapper(string $mapperClass, mixed $links): object {
		$mapper = $this->createMock($mapperClass);
		if ($links instanceof \Throwable) {
			$mapper->method('findByObjectUuid')->willThrowException($links);
			return $mapper;
		}
		$mapper->method('findByObjectUuid')->willReturn($links);
		return $mapper;
	}//end mapper()

	/**
	 * The link rows per provider.
	 *
	 * @param string $name cospend, forms or time.
	 *
	 * @return list<object> The links.
	 */
	private function links(string $name): array {
		$rows = [
			'cospend' => [
				['setEntryType' => 'project', 'setProjectId' => 'p1', 'setName' => 'Trip', 'setAmount' => 3.5, 'setCurrency' => 'EUR'],
				['setEntryType' => 'bill', 'setProjectId' => 'p1', 'setBillId' => 4, 'setName' => 'Food'],
				['setEntryType' => 'bill', 'setProjectId' => 'p2'],
			],
			'forms' => [
				['setFormId' => 21, 'setFormHash' => 'cached', 'setTitle' => 'Cached', 'setStatus' => 'open', 'setLinkedAt' => new DateTime('@1700000000')],
				['setFormId' => 21, 'setSubmissionId' => 7, 'setLinkedAt' => new DateTime('@1700000500')],
				['setFormId' => 21, 'setSubmissionId' => 8, 'setLinkedAt' => new DateTime('@1700000300')],
				['setFormId' => 30, 'setTitle' => 'Gone', 'setStatus' => 'archived', 'setExpiresAt' => new DateTime('@1600000000'), 'setLinkedAt' => new DateTime('@1700000000')],
				['setFormId' => 31, 'setStatus' => 'draft', 'setLinkedAt' => new DateTime('@1700000000')],
				['setFormId' => 40, 'setSubmissionId' => 9, 'setLinkedAt' => new DateTime('@1700000900')],
				['setFormId' => 40, 'setSubmissionId' => 10, 'setLinkedAt' => new DateTime('@1700000100')],
			],
			'time' => [
				['setEntryType' => 'client', 'setClientId' => 'c-1', 'setName' => 'Client'],
				['setEntryType' => 'task', 'setTaskId' => 't-1', 'setDuration' => 30, 'setBillable' => true],
				['setEntryType' => 'time', 'setTimeId' => 'e-1', 'setStartedAt' => new DateTime('@1700000000')],
			],
		][$name];
		$class = ['cospend' => Db\CospendLink::class, 'forms' => Db\FormLink::class, 'time' => Db\TimeTrackerLink::class][$name];
		$out = [];
		foreach ($rows as $i => $values) {
			$link = new $class();
			$link->setId($i + 1);
			foreach ($values as $setter => $value) {
				$link->$setter($value);
			}
			$out[] = $link;
		}

		return $out;
	}//end links()

	/**
	 * Every provider lists as recorded.
	 *
	 * @return void
	 */
	public function testListsMatchTheRecording(): void {
		$seen = [];
		foreach (['cospend', 'cospend-without-mappers', 'forms', 'time'] as $name) {
			$linkName = explode('-', $name)[0];
			$scenarios = [
				'app missing' => [false, $this->links($linkName), 'answers'],
				'linked rows' => [true, $this->links($linkName), 'answers'],
				'linked rows, failing database' => [true, $this->links($linkName), 'fails'],
				'mapper fails' => [true, new RuntimeException('no table'), 'answers'],
				'no links' => [true, [], 'answers'],
				'no links, empty database' => [true, [], 'empty'],
				'no links, failing database' => [true, [], 'fails'],
			];
			foreach ($scenarios as $label => [$installed, $links, $mode]) {
				$this->queries = [];
				$this->dbMode = $mode;
				try {
					$rows = $this->provider($name, $installed, $links)->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1'), ['q' => 1]);
				} catch (\Throwable $e) {
					$rows = ['thrown' => get_class($e), 'message' => $e->getMessage()];
				}
				$seen[$name.', '.$label] = ['rows' => $rows, 'queries' => $this->queries];
			}
		}

		$json = json_decode((string)json_encode($seen, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		if (getenv('LINK_FALLBACK_GOLDEN_RECORD') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestIncomplete('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $json);
	}//end testListsMatchTheRecording()
}//end class

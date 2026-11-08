<?php

/**
 * Characterisation golden for CospendLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\CospendEntryKey;
use OCA\OpenRegister\Db\CospendLink;
use OCA\OpenRegister\Db\CospendLinkMapperInterface;
use OCA\OpenRegister\Service\CospendLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

if (class_exists('OCA\\Cospend\\Service\\ProjectService') === false) {
	eval('namespace OCA\\Cospend\\Service; class ProjectService {}');
}

/**
 * Pins results, thrown exceptions, mapper and database calls and log lines of
 * every public method of CospendLinkService, compared with
 * tests/Unit/Service/fixtures/cospend-link-service-golden.json.
 * Run with UPDATE_GOLDEN=1 to re-record.
 *
 * @covers \OCA\OpenRegister\Service\CospendLinkService
 * @uses   \OCA\OpenRegister\Db\CospendEntryKey
 * @uses   \OCA\OpenRegister\Db\CospendLink
 * @uses   \OCA\OpenRegister\Service\LinkTarget
 */
class CospendLinkServiceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/cospend-link-service-golden.json';

	private array $calls = [];

	private static function world(array $over): array {
		return $over + [
			'uid' => 'alice',
			'enabled' => true,
			'service' => true,
			'duplicate' => false,
			'deleted' => 1,
			'linked' => [],
			'projects' => [
				'trip' => ['id' => 'trip', 'name' => 'Trip', 'currency_name' => 'EUR', 'user_id' => 'alice'],
				'bare' => ['id' => 'bare'],
				'zoo' => ['id' => 'zoo', 'name' => 'Zoo Day', 'currency_name' => 'USD', 'user_id' => 'alice'],
			],
			'bills' => [
				5 => ['id' => 5, 'project_id' => 'trip', 'what' => 'Hotel', 'amount' => '120.5'],
				6 => ['id' => '6', 'project_id' => 'bare'],
			],
			'dbThrows' => [],
			'create' => ['ok'],
			'updateThrows' => false,
		];
	}//end world()

	private static function cases(): array {
		$staleProject = ['entryType' => 'project', 'projectId' => 'trip', 'billId' => null, 'name' => 'old', 'currency' => 'GBP', 'amount' => null, 'linkedAt' => null];
		$staleBill = ['entryType' => 'bill', 'projectId' => 'trip', 'billId' => 5, 'name' => 'old bill', 'currency' => null, 'amount' => 1.0, 'linkedAt' => 'old'];
		$staleBillGone = ['entryType' => 'bill', 'projectId' => 'trip', 'billId' => 99, 'name' => 'gone', 'currency' => null, 'amount' => 2.0, 'linkedAt' => null];
		$staleBillNoId = ['entryType' => 'bill', 'projectId' => 'bare', 'billId' => null, 'name' => 'nobill', 'currency' => 'X', 'amount' => null, 'linkedAt' => null];
		$fresh = ['entryType' => 'bill', 'projectId' => 'trip', 'billId' => 5, 'name' => 'fresh', 'currency' => 'EUR', 'amount' => 3.0, 'linkedAt' => 'recent'];
		$noProject = ['entryType' => 'project', 'projectId' => 'missing', 'billId' => null, 'name' => 'm', 'currency' => null, 'amount' => null, 'linkedAt' => null];
		$emptyProject = ['entryType' => 'project', 'projectId' => '', 'billId' => null, 'name' => 'e', 'currency' => null, 'amount' => null, 'linkedAt' => 'recent'];
		$bareProject = ['entryType' => 'project', 'projectId' => 'bare', 'billId' => null, 'name' => 'keep', 'currency' => 'K', 'amount' => null, 'linkedAt' => null];
		return [
			'available' => ['isCospendAvailable', [], []],
			'project no user' => ['linkProject', ['trip'], ['uid' => null]],
			'project unavailable' => ['linkProject', ['trip'], ['enabled' => false]],
			'project blank id' => ['linkProject', ['  '], []],
			'project duplicate' => ['linkProject', ['trip'], ['duplicate' => true]],
			'project missing' => ['linkProject', ['nope'], []],
			'project db fails' => ['linkProject', ['trip'], ['dbThrows' => ['cospend_projects']]],
			'project ok' => ['linkProject', ['trip'], []],
			'project bare' => ['linkProject', ['bare'], []],
			'bill no user' => ['linkBill', ['trip', 5], ['uid' => null]],
			'bill unavailable' => ['linkBill', ['trip', 5], ['enabled' => false]],
			'bill blank project' => ['linkBill', [' ', 5], []],
			'bill zero id' => ['linkBill', ['trip', 0], []],
			'bill duplicate' => ['linkBill', ['trip', 5], ['duplicate' => true]],
			'bill missing' => ['linkBill', ['trip', 99], []],
			'bill db fails' => ['linkBill', ['trip', 5], ['dbThrows' => ['cospend_bills']]],
			'bill ok' => ['linkBill', ['trip', 5], []],
			'bill bare' => ['linkBill', ['bare', 6], []],
			'bill project missing' => ['linkBill', ['ghost', 6], ['bills' => [6 => ['id' => 6, 'project_id' => 'ghost', 'what' => '', 'amount' => 0]]]],
			'bill no what no project' => ['linkBill', ['ghost', 7], ['bills' => [7 => ['id' => 7, 'project_id' => 'ghost']]]],
			'create no user' => ['createAndLinkProject', ['Trip', 'EUR'], ['uid' => null]],
			'create blank name' => ['createAndLinkProject', ['  ', 'EUR'], []],
			'create unavailable' => ['createAndLinkProject', ['Trip', 'EUR'], ['enabled' => false]],
			'create no service' => ['createAndLinkProject', ['Trip', 'EUR'], ['service' => false]],
			'create ok string id' => ['createAndLinkProject', [' My Trip! ', ' EUR '], ['create' => ['string']]],
			'create ok array id' => ['createAndLinkProject', ['Zoo', ''], ['create' => ['array:zoo']]],
			'create ok array no id' => ['createAndLinkProject', ['!!!', ''], ['create' => ['arraynoid']]],
			'create ok null result' => ['createAndLinkProject', [str_repeat('Long name ', 8), 'NOK'], ['create' => ['null']]],
			'create empty string result' => ['createAndLinkProject', ['Trip', ''], ['create' => ['empty']]],
			'create first fails' => ['createAndLinkProject', ['Trip', 'EUR'], ['create' => ['throw', 'string']]],
			'create both fail' => ['createAndLinkProject', ['Trip', 'EUR'], ['create' => ['throw', 'throw']]],
			'create currency lookup none' => ['createAndLinkProject', ['Trip', ''], ['create' => ['array:bare']]],
			'unlink no user' => ['unlink', ['obj-1', 4], ['uid' => null]],
			'unlink ok' => ['unlink', ['obj-1', 4], []],
			'unlink missing' => ['unlink', ['obj-1', 4], ['deleted' => 0]],
			'linked none' => ['getLinkedEntries', ['obj-1'], []],
			'linked unavailable' => ['getLinkedEntries', ['obj-1'], ['enabled' => false, 'linked' => [$staleProject, $staleBill]]],
			'linked refresh' => ['getLinkedEntries', ['obj-1'], ['linked' => [$staleProject, $staleBill, $staleBillGone, $staleBillNoId, $fresh, $noProject, $emptyProject, $bareProject]]],
			'linked refresh update fails' => ['getLinkedEntries', ['obj-1'], ['linked' => [$staleProject, $staleBill], 'updateThrows' => true]],
			'projects unavailable' => ['getAvailableProjects', [null], ['enabled' => false]],
			'projects no user' => ['getAvailableProjects', [null], ['uid' => null]],
			'projects all' => ['getAvailableProjects', [null], []],
			'projects empty search' => ['getAvailableProjects', [''], []],
			'projects search' => ['getAvailableProjects', ['TRI'], []],
			'projects search by id fallback' => ['getAvailableProjects', ['bar'], []],
			'projects db fails' => ['getAvailableProjects', ['x'], ['dbThrows' => ['cospend_projects']]],
		];
	}//end cases()

	public function testCospendLinkServiceMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $over]) {
			$this->calls = [];
			$service = $this->makeService(self::world($over));
			$target = (new LinkTarget('obj-1', 3))->inSchema(4);
			if (in_array($method, ['linkProject', 'linkBill', 'createAndLinkProject'], true) === true) {
				array_unshift($args, $target);
			}

			try {
				$result = $this->normalise($service->$method(...$args));
			} catch (Throwable $e) {
				$result = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
			}

			$actual[$name] = ['result' => $result, 'calls' => $this->calls];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testCospendLinkServiceMatchesGolden()

	public function record(string $call): void {
		$this->calls[] = $this->normalise($call);
	}//end record()

	/**
	 * Clock-taken timestamps and the random project-id suffix become markers.
	 */
	public function normalise(mixed $value): mixed {
		if ($value instanceof CospendLink) {
			$fields = [];
			foreach (['ObjectUuid', 'RegisterId', 'SchemaId', 'EntryType', 'ProjectId', 'BillId', 'Name', 'Amount', 'Currency', 'LinkedBy', 'LinkedAt'] as $field) {
				$fields[$field] = $this->normalise($value->{'get' . $field}());
			}

			$fields['updated'] = array_keys($value->getUpdatedFields());
			return $fields;
		}

		if ($value instanceof DateTime) {
			if (abs($value->getTimestamp() - time()) < 3600) {
				return 'NOW';
			}

			return $value->format('U');
		}

		if (is_array($value) === true) {
			return array_map(fn ($v) => $this->normalise($v), $value);
		}

		if (is_string($value) === true) {
			if (preg_match('/^\d{4}-\d\d-\d\dT/', $value) === 1) {
				$time = strtotime($value);
				if (abs($time - time()) < 3600) {
					return 'NOW';
				}

				return (string)$time;
			}

			return (string)preg_replace('/-[0-9a-f]{6}\b/', '-RAND', $value);
		}

		return $value;
	}//end normalise()

	public function linkRow(array $spec): CospendLink {
		$link = new CospendLink();
		$link->setEntryType($spec['entryType']);
		$link->setProjectId($spec['projectId']);
		$link->setBillId($spec['billId']);
		$link->setName($spec['name']);
		$link->setAmount($spec['amount']);
		$link->setCurrency($spec['currency']);
		if ($spec['linkedAt'] === 'recent') {
			$link->setLinkedAt(new DateTime('-10 seconds'));
		}

		if ($spec['linkedAt'] === 'old') {
			$link->setLinkedAt(new DateTime('@1600000000'));
		}

		$link->resetUpdatedFields();
		return $link;
	}//end linkRow()

	/**
	 * A query builder over the world's projects and bills tables.
	 */
	private function database(array $w): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($w) {
				$state = ['table' => null, 'where' => []];
				$qb = $this->createMock(IQueryBuilder::class);
				$expr = $this->createMock(IExpressionBuilder::class);
				$expr->method('eq')->willReturnCallback(
					function ($col, $param) use (&$state) {
						$state['where'][$col] = $param;
						return $col . '=' . $param;
					}
				);
				$qb->method('expr')->willReturn($expr);
				$qb->method('createNamedParameter')->willReturnCallback(fn ($v) => (string)$v);
				foreach (['select', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chain) {
					$qb->method($chain)->willReturnCallback(
						function (...$args) use ($qb, $chain) {
							$this->record('qb.' . $chain . '(' . json_encode($args) . ')');
							return $qb;
						}
					);
				}

				$qb->method('from')->willReturnCallback(
					function ($table) use ($qb, &$state) {
						$state['table'] = $table;
						$this->record('qb.from(' . $table . ')');
						return $qb;
					}
				);
				$qb->method('executeQuery')->willReturnCallback(
					function () use (&$state, $w) {
						$this->record('qb.executeQuery');
						if (in_array($state['table'], $w['dbThrows'], true) === true) {
							throw new RuntimeException('db down');
						}

						$source = $w['bills'];
						if ($state['table'] === 'cospend_projects') {
							$source = $w['projects'];
						}

						$rows = [];
						foreach ($source as $row) {
							$match = true;
							foreach ($state['where'] as $col => $val) {
								if ((string)($row[$col] ?? '') !== (string)$val) {
									$match = false;
								}
							}

							if ($match === true) {
								$rows[] = $row;
							}
						}

						$result = $this->createMock(IResult::class);
						$result->method('fetch')->willReturnCallback(
							function () use (&$rows) {
								$row = array_shift($rows);
								return $row ?? false;
							}
						);
						$result->method('closeCursor')->willReturnCallback(fn () => $this->record('closeCursor') ?? true);
						return $result;
					}
				);
				return $qb;
			}
		);
		return $db;
	}//end database()

	private function makeService(array $w): CospendLinkService {
		$test = $this;
		$projects = new class($test, $w['create']) {
			public function __construct(private $test, private array $plan) {
			}

			public function createProject(...$args): mixed {
				$this->test->record('createProject(' . $this->test->normalise(json_encode($args)) . ')');
				$step = array_shift($this->plan);
				if ($step === 'throw') {
					throw new RuntimeException('signature mismatch');
				}

				if (str_starts_with((string)$step, 'array:') === true) {
					return ['id' => substr($step, 6)];
				}

				return match ($step) {
					'string' => 'created-id',
					'arraynoid' => ['name' => 'x'],
					'empty' => '',
					default => null,
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($w, $projects) {
				$this->record('container.get(' . $id . ')');
				if ($w['service'] === false) {
					throw new RuntimeException('no ' . $id);
				}

				return $projects;
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			function (string $app) use ($w) {
				$this->record('isEnabledForUser(' . $app . ')');
				return $w['enabled'];
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($w['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($w['uid']);
		}

		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(fn (string $m) => $this->record('warning ' . $m));
		$logger->method('debug')->willReturnCallback(fn (string $m) => $this->record('debug ' . $m));
		$links = $this->createMock(CospendLinkMapperInterface::class);
		$links->method('findDuplicate')->willReturnCallback(
			function (string $uuid, CospendEntryKey $entry) use ($w) {
				$this->record('findDuplicate(' . $uuid . ', ' . json_encode((array)$entry) . ')');
				if ($w['duplicate'] === true) {
					return new CospendLink();
				}

				return null;
			}
		);
		$links->method('deleteByObjectAndId')->willReturnCallback(
			function (string $uuid, int $id) use ($w) {
				$this->record('deleteByObjectAndId(' . $uuid . ', ' . $id . ')');
				return $w['deleted'];
			}
		);
		$links->method('findByObjectUuid')->willReturnCallback(
			function (string $uuid) use ($w) {
				$this->record('findByObjectUuid(' . $uuid . ')');
				return array_map(fn (array $spec) => $this->linkRow($spec), $w['linked']);
			}
		);
		$links->method('insert')->willReturnCallback(
			function ($entity) {
				$this->record('insert(' . json_encode($this->normalise($entity)) . ')');
				return $entity;
			}
		);
		$links->method('update')->willReturnCallback(
			function ($entity) use ($w) {
				$this->record('update(' . json_encode($this->normalise($entity)) . ')');
				if ($w['updateThrows'] === true) {
					throw new RuntimeException('update down');
				}

				$copy = clone $entity;
				$copy->setName('saved:' . $entity->getName());
				return $copy;
			}
		);
		return new CospendLinkService($links, $container, new LinkServiceRuntime($appManager, $session, $logger), $this->database($w));
	}//end makeService()
}//end class

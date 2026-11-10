<?php

/**
 * OpenRegister - the batch prefetch and per-row lookups of the bulk safeguards across registers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SafeguardBatchRun;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\BulkBatchDefaults;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Pins, for batches whose rows name their own registers, which stored rows are
 * prefetched per register and schema, which rows fall back to a single lookup,
 * the create-or-update outcome, the refusals of another organisation's rows and
 * the log lines. Recorded on the code before the safeguards were split up.
 */
class SaveObjectsSafeguardPrefetchGoldenTest extends TestCase {

	private const STORED = '11111111-1111-1111-1111-111111111111';

	private const FOREIGN = '33333333-3333-3333-3333-333333333333';

	private const UNKNOWN = '99999999-9999-9999-9999-999999999999';

	/**
	 * The calls made, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every case with its recorded outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [
			['@self' => ['register' => 10, 'uuid' => self::STORED], 'title' => 'a'],
			['@self' => ['register' => 11, 'uuid' => self::FOREIGN], 'title' => 'b'],
			['@self' => ['register' => 404, 'uuid' => self::STORED], 'title' => 'c'],
			['@self' => ['register' => 10, 'uuid' => self::UNKNOWN], 'title' => 'd'],
			['title' => 'e'],
			['@self' => ['register' => '', 'uuid' => self::FOREIGN], 'title' => 'f'],
		];

		$expected = require __DIR__.'/fixtures/bulk-safeguard-prefetch.php';

		return [
			'registers per row'                  => [['rows' => $rows], $expected['registers per row']],
			'one register prefetch fails'        => [['rows' => $rows, 'failingRegister' => 11], $expected['one register prefetch fails']],
			'administrator without organisations' => [
				['rows' => $rows, 'user' => 'admin', 'multitenancy' => false],
				$expected['administrator without organisations'],
			],
		];
	}//end cases()

	/**
	 * The batch's rows give the recorded outcome.
	 *
	 * @param array<string, mixed> $case     The case
	 * @param array<string, mixed> $expected The recorded outcome
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheBatchGivesTheRecordedOutcome(array $case, array $expected): void {
		$this->assertSame($expected, $this->runCase(case: $case));
	}//end testTheBatchGivesTheRecordedOutcome()

	/**
	 * Run one case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		$logger      = new class extends AbstractLogger {
			/**
			 * The logged lines.
			 *
			 * @var list<string>
			 */
			public array $lines = [];

			/**
			 * Record a log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array             $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context=[]): void {
				unset($context['file'], $context['line']);
				$this->lines[] = $level.': '.$message.' '.json_encode($context);
			}//end log()
		};

		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $cache) {
			$ref->getProperty($cache)->setValue(null, []);
		}

		$user    = ($case['user'] ?? 'bob');
		$handler = new SaveObjects(
			stores: new BulkSaveStores(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper(), schemas: $this->createMock(SchemaMapper::class), tables: $this->objectMapper(failingRegister: ($case['failingRegister'] ?? null))), saveHandler: $this->createMock(SaveObject::class)
			),
			session: new BulkSaveSession(
				userSession: $this->userSession(user: $user),
				organisationService: $this->createMock(OrganisationService::class),
				groupManager: $this->groupManager()
			),
			reporting: new BulkSaveReporting(logger: $logger),
			checks: new BulkRowChecks(permissionHandler: $this->permissionHandler())
		);

		$result = [
			'saved'      => [],
			'updated'    => [],
			'unchanged'  => [],
			'invalid'    => [],
			'errors'     => [],
			'statistics' => ['invalid' => 0, 'errors' => 0],
		];
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('schema-7');
		$method = new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards');
		$batch  = new SafeguardBatchRun(
			objects: $case['rows'],
			defaults: new BulkBatchDefaults(null, $schema),
			flags: ['rbac' => true, 'validation' => false, 'multitenancy' => ($case['multitenancy'] ?? true)],
			result: $result
		);
		$passed = $method->invokeArgs($handler, [$batch]);
		$result = $batch->result;

		return ['passed' => $passed, 'result' => $result, 'calls' => $this->calls, 'log' => $logger->lines];
	}//end runCase()

	/**
	 * A mapper holding one row of the caller's organisation and one of another.
	 *
	 * @param int|null $failingRegister The register whose prefetch fails
	 *
	 * @return MagicMapper
	 */
	private function objectMapper(?int $failingRegister): MagicMapper {
		$stored = new ObjectEntity();
		$stored->setUuid(self::STORED);
		$stored->setOwner('bob');
		$stored->setOrganisation('mine');
		$foreign = new ObjectEntity();
		$foreign->setUuid(self::FOREIGN);
		$foreign->setOwner('eve');
		$foreign->setOrganisation('theirs');
		$rows = [self::STORED => $stored, self::FOREIGN => $foreign];

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findObjectsByUuidsInRegisterSchema')->willReturnCallback(
			function (...$args) use ($rows, $failingRegister): array {
				$this->calls[] = 'prefetch '.json_encode([$args[2], $args[0]->getId(), $args[1]->getId(), $args[3]]);
				if ($args[0]->getId() === $failingRegister) {
					throw new RuntimeException('probe failed');
				}

				return array_intersect_key($rows, array_flip($args[2]));
			}
		);
		$mapper->method('find')->willReturnCallback(
			function (...$args) use ($rows): ObjectEntity {
				$this->calls[] = 'find '.json_encode(
					[$args[0], $args[1]?->getId(), $args[2]?->getId(), ($args[3] ?? null), ($args[4] ?? null), ($args[5] ?? null)]
				);
				if (isset($rows[$args[0]]) === false || ($args[4] ?? true) === true) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
				}

				return $rows[$args[0]];
			}
		);
		$mapper->method('callerOrganisationsHold')->willReturnCallback(
			function (ObjectEntity $row): bool {
				$this->calls[] = 'callerOrganisationsHold '.$row->getUuid();
				return $row->getOrganisation() === 'mine';
			}
		);

		return $mapper;
	}//end objectMapper()

	/**
	 * A register mapper that knows registers 10 and 11.
	 *
	 * @return RegisterMapper
	 */
	private function registerMapper(): RegisterMapper {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (...$args): Register {
				$this->calls[] = 'register find '.json_encode($args[0]);
				if (in_array((int) $args[0], [10, 11], true) === false) {
					throw new RuntimeException('no register');
				}

				$register = new Register();
				$register->setId((int) $args[0]);
				return $register;
			}
		);

		return $mapper;
	}//end registerMapper()

	/**
	 * A session for the user.
	 *
	 * @param string $user The user
	 *
	 * @return IUserSession
	 */
	private function userSession(string $user): IUserSession {
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn($user);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($account);

		return $session;
	}//end userSession()

	/**
	 * A group manager for which `admin` is an administrator.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(): IGroupManager {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		return $manager;
	}//end groupManager()

	/**
	 * A permission handler that grants everything and records the question.
	 *
	 * @return PermissionHandler
	 */
	private function permissionHandler(): PermissionHandler {
		$handler = $this->createMock(PermissionHandler::class);
		$handler->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
			function (...$args): bool {
				$this->calls[] = 'hasPermission '.json_encode([$args[1], ($args[2] ?? null), ($args[3] ?? null), ($args[5] ?? null)?->getUuid()]);
				return true;
			}
		));

		return $handler;
	}//end permissionHandler()
}//end class

<?php

/**
 * OpenRegister - every outcome of the per-row gates in SaveObjects::applyBulkSafeguards().
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
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use Opis\JsonSchema\ValidationResult;
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
 * Pins, for a range of batches, callers and stored rows, which rows pass, every
 * rejection with its reason and index, the statistics, the permission, validation
 * and lookup calls with their arguments and order, and the log lines.
 *
 * Expected values live in `fixtures/bulk-safeguards.php`, one entry per case.
 */
class SaveObjectsBulkSafeguardsGoldenTest extends TestCase {

	private const EXISTING = '11111111-1111-1111-1111-111111111111';

	private const DRAFT = '22222222-2222-2222-2222-222222222222';

	private const OTHER = '33333333-3333-3333-3333-333333333333';

	/**
	 * The calls made, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every case.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cases(): array {
		$create = ['title' => 'new'];
		$update = ['@self' => ['uuid' => self::EXISTING], 'title' => 'changed'];

		return [
			'no permission handler'             => ['wiring' => 'legacy', 'rows' => [$create]],
			'non-admin create allowed'          => ['rows' => [$create]],
			'non-admin create denied'           => ['rows' => [$create], 'deny' => ['create']],
			'admin skips rbac when asked'       => ['user' => 'admin', 'rbac' => false, 'rows' => [$create], 'deny' => ['create']],
			'admin keeps rbac by default'       => ['user' => 'admin', 'rows' => [$create], 'deny' => ['create']],
			'non-admin cannot skip rbac'        => ['rbac' => false, 'rows' => [$create], 'deny' => ['create']],
			'no session user'                   => ['user' => null, 'rows' => [$create]],
			'admin lookup throws'               => ['user' => 'throws', 'rbac' => false, 'rows' => [$create], 'deny' => ['create']],
			'no group manager'                  => ['wiring' => 'no-groups', 'rbac' => false, 'rows' => [$create], 'deny' => ['create']],
			'non-admin self injection stripped' => [
				'rows' => [['@self' => ['organisation' => 'evil', 'owner' => 'mallory', 'authorization' => ['x'], 'name' => 'ok'], 'title' => 't']],
			],
			'admin self injection kept'         => [
				'user' => 'admin',
				'rows' => [['@self' => ['organisation' => 'org', 'owner' => 'root', 'name' => 'ok'], 'title' => 't']],
			],
			'ambiguous identity'                => ['rows' => [['id' => self::EXISTING, '@self' => ['id' => self::OTHER]]]],
			'agreeing identities'               => ['rows' => [['id' => self::EXISTING, '@self' => ['id' => self::EXISTING, 'uuid' => self::EXISTING]]]],
			'mixed batch without schema'        => ['schema' => null, 'rows' => [$create]],
			'schema zero'                       => ['schema' => 0, 'rows' => [$create]],
			'schema zero string'                => ['schema' => '0', 'rows' => [$create]],
			'schema by id'                      => ['schema' => 7, 'rows' => [$create]],
			'schema unresolvable'               => ['schema' => 404, 'rows' => [$create, $update]],
			'schema unresolvable, admin no rbac' => ['schema' => 404, 'user' => 'admin', 'rbac' => false, 'rows' => [$create]],
			'row names its own schema'          => ['rows' => [['@self' => ['schema' => 8], 'title' => 'x']], 'deny' => ['create:7']],
			'row names an unknown schema'       => ['rows' => [['@self' => ['schema' => 404], 'title' => 'x']]],
			'register by id'                    => ['register' => 10, 'rows' => [$update]],
			'register unresolvable'             => ['register' => 404, 'rows' => [$update]],
			'register zero'                     => ['register' => 0, 'rows' => [$update]],
			'row names its own register'        => ['rows' => [['@self' => ['register' => 11, 'uuid' => self::EXISTING]]]],
			'update allowed'                    => ['rows' => [$update]],
			'update denied'                     => ['rows' => [$update], 'deny' => ['update']],
			'update with unknown uuid'          => ['rows' => [['@self' => ['uuid' => self::OTHER], 'title' => 'x']]],
			'prefetch fails'                    => ['prefetch' => 'throws', 'rows' => [$update]],
			'prefetch fails and find fails'     => ['prefetch' => 'throws', 'find' => 'throws', 'rows' => [$update]],
			'append-only update'                => ['appendOnly' => true, 'rows' => [$update]],
			'append-only create'                => ['appendOnly' => true, 'rows' => [$create]],
			'append-only update without rbac'   => ['appendOnly' => true, 'user' => 'admin', 'rbac' => false, 'rows' => [$update]],
			'somebody elses draft'              => ['rows' => [['@self' => ['uuid' => self::DRAFT], 'title' => 'x']]],
			'own draft'                         => ['user' => 'carol', 'rows' => [['@self' => ['uuid' => self::DRAFT], 'title' => 'x']]],
			'admin over a draft'                => ['user' => 'admin', 'rows' => [['@self' => ['uuid' => self::DRAFT], 'title' => 'x']]],
			'draft on append-only schema'       => ['appendOnly' => true, 'rows' => [['@self' => ['uuid' => self::DRAFT], 'title' => 'x']]],
			'draft without rbac'                => ['user' => 'admin', 'rbac' => false, 'rows' => [['@self' => ['uuid' => self::DRAFT]]]],
			'property authorization refuses'    => ['propertyRbac' => ['secret'], 'rows' => [$create, $update]],
			'property authorization restores'   => ['propertyRbac' => 'restore', 'rows' => [$update]],
			'property authorization no rbac'    => ['propertyRbac' => ['secret'], 'user' => 'admin', 'rbac' => false, 'rows' => [$create]],
			'validation passes'                 => ['validation' => true, 'rows' => [$create]],
			'validation fails'                  => ['validation' => true, 'validator' => 'invalid', 'rows' => [$create, $update]],
			'validation throws'                 => ['validation' => true, 'validator' => 'throws', 'rows' => [$create]],
			'validation without validator'      => ['validation' => true, 'wiring' => 'no-validator', 'rows' => [$create]],
			'validation not asked'              => ['validator' => 'invalid', 'rows' => [$create]],
			'string row keys'                   => ['rows' => ['first' => $create, 'second' => $create], 'deny' => ['create']],
			'mixed batch in order'              => [
				'rows'       => [
					$create,
					['id' => self::EXISTING, '@self' => ['id' => self::OTHER]],
					$update,
					['@self' => ['uuid' => self::DRAFT]],
					['title' => 'second new'],
				],
				'deny'       => ['update'],
				'validation' => true,
			],
		];
	}//end cases()

	/**
	 * Every case paired with its expected outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function casesWithExpectations(): array {
		$expected = require __DIR__.'/fixtures/bulk-safeguards.php';
		$pairs    = [];
		foreach (self::cases() as $name => $case) {
			$pairs[$name] = [$case, $expected[$name] ?? ['missing expectation' => $name]];
		}

		return $pairs;
	}//end casesWithExpectations()

	/**
	 * The gates pass, reject and log what was recorded.
	 *
	 * @param array<string, mixed> $case     The case
	 * @param array<string, mixed> $expected The recorded outcome
	 *
	 * @return void
	 */
	#[DataProvider('casesWithExpectations')]
	public function testGatesGiveTheRecordedOutcome(array $case, array $expected): void {
		$this->assertSame(expected: $expected, actual: $this->runCase(case: $case));
	}//end testGatesGiveTheRecordedOutcome()

	/**
	 * Every case has a recorded expectation and every expectation a case.
	 *
	 * @return void
	 */
	public function testEveryCaseIsRecorded(): void {
		$expected = require __DIR__.'/fixtures/bulk-safeguards.php';
		$this->assertSame(expected: array_keys(self::cases()), actual: array_keys($expected));
	}//end testEveryCaseIsRecorded()

	/**
	 * Run one case: the passed rows, the result, the calls and the log lines.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return array<string, mixed>
	 */
	public function runCase(array $case): array {
		$this->calls = [];
		$logger      = new class extends AbstractLogger {
			/**
			 * The logged levels, messages and context.
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

		$ref = new ReflectionClass(objectOrClass: SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $cache) {
			$ref->getProperty($cache)->setValue(null, []);
		}

		$result = [
			'saved'      => [],
			'updated'    => [],
			'unchanged'  => [],
			'invalid'    => [],
			'errors'     => [],
			'statistics' => ['totalProcessed' => count($case['rows']), 'saved' => 0, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0, 'errors' => 0],
		];
		$method = new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards');
		$passed = $method->invokeArgs(
			$this->handler(case: $case, logger: $logger),
			[
				$case['rows'],
				$this->defaultRegister(register: ($case['register'] ?? 'object')),
				$this->defaultSchema(case: $case),
				($case['rbac'] ?? true),
				($case['validation'] ?? false),
				&$result,
			]
		);

		return ['passed' => $passed, 'result' => $result, 'calls' => $this->calls, 'log' => $logger->lines];
	}//end runCase()

	/**
	 * The handler, wired the way the case says.
	 *
	 * @param array<string, mixed> $case   The case
	 * @param AbstractLogger       $logger The logger
	 *
	 * @return SaveObjects
	 */
	private function handler(array $case, AbstractLogger $logger): SaveObjects {
		$wiring = ($case['wiring'] ?? 'full');
		$user   = 'bob';
		if (array_key_exists('user', $case) === true) {
			$user = $case['user'];
		}

		$args   = [
			'objectEntityMapper'  => $this->objectMapper(case: $case),
			'schemaMapper'        => $this->schemaMapper(case: $case),
			'registerMapper'      => $this->registerMapper(),
			'saveHandler'         => $this->createMock(originalClassName: SaveObject::class),
			'userSession'         => $this->userSession(user: $user),
			'organisationService' => $this->createMock(originalClassName: OrganisationService::class),
			'logger'              => $logger,
		];
		if ($wiring === 'legacy') {
			return new SaveObjects(...$args);
		}

		if ($wiring !== 'no-groups') {
			$args['groupManager'] = $this->groupManager(user: $user);
		}

		$args['permissionHandler'] = $this->permissionHandler(deny: ($case['deny'] ?? []));
		if ($wiring !== 'no-validator') {
			$args['validateHandler'] = $this->validator(mode: ($case['validator'] ?? 'valid'));
		}

		if (isset($case['propertyRbac']) === true) {
			$args['propertyRbac'] = $this->propertyRbac(mode: $case['propertyRbac']);
		}

		return new SaveObjects(...$args);
	}//end handler()

	/**
	 * The default schema argument: an entity (id 7), an id, or nothing.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return Schema|string|int|null
	 */
	private function defaultSchema(array $case): Schema|string|int|null {
		if (array_key_exists('schema', $case) === true) {
			return $case['schema'];
		}

		return $this->schema(id: 7, appendOnly: ($case['appendOnly'] ?? false));
	}//end defaultSchema()

	/**
	 * The default register argument: an entity (id 10), an id, or nothing.
	 *
	 * @param mixed $register `object`, an id, or null
	 *
	 * @return Register|string|int|null
	 */
	private function defaultRegister(mixed $register): Register|string|int|null {
		if ($register === 'object') {
			return $this->register(id: 10);
		}

		return $register;
	}//end defaultRegister()

	/**
	 * A schema.
	 *
	 * @param int  $id         The id
	 * @param bool $appendOnly Whether it is append-only
	 *
	 * @return Schema
	 */
	private function schema(int $id, bool $appendOnly = false): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle('schema-'.$id);
		$schema->setSlug('schema-'.$id);
		$schema->setAppendOnly($appendOnly);

		return $schema;
	}//end schema()

	/**
	 * A register.
	 *
	 * @param int $id The id
	 *
	 * @return Register
	 */
	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		$register->setConfiguration([]);

		return $register;
	}//end register()

	/**
	 * A mapper holding one existing object and one draft owned by carol.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return MagicMapper
	 */
	private function objectMapper(array $case): MagicMapper {
		$existing = new ObjectEntity();
		$existing->setUuid(self::EXISTING);
		$existing->setOwner('bob');
		$existing->setObject(['title' => 'stored', 'secret' => 's3cret']);
		$draft = new ObjectEntity();
		$draft->setUuid(self::DRAFT);
		$draft->setOwner('carol');
		$draft->setStatus('draft');
		$draft->setObject(['title' => 'draft']);
		$stored = [self::EXISTING => $existing, self::DRAFT => $draft];

		$mapper = $this->createMock(originalClassName: MagicMapper::class);
		$mapper->method('findObjectsByUuidsInRegisterSchema')->willReturnCallback(
			function (...$args) use ($case, $stored): array {
				$this->calls[] = 'prefetch '.json_encode([$args[2], $args[0]->getId(), $args[1]->getId()]);
				if (($case['prefetch'] ?? '') === 'throws') {
					throw new RuntimeException('probe failed');
				}

				return array_intersect_key($stored, array_flip($args[2]));
			}
		);
		$mapper->method('find')->willReturnCallback(
			function (...$args) use ($case, $stored): ObjectEntity {
				$this->calls[] = 'find '.json_encode([$args[0], $args[1]?->getId(), $args[2]?->getId()]);
				if (($case['find'] ?? '') === 'throws' || isset($stored[$args[0]]) === false) {
					throw new RuntimeException('not found');
				}

				return $stored[$args[0]];
			}
		);

		return $mapper;
	}//end objectMapper()

	/**
	 * A schema mapper that knows schemas 7 and 8.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return SchemaMapper
	 */
	private function schemaMapper(array $case): SchemaMapper {
		$mapper = $this->createMock(originalClassName: SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (...$args) use ($case): Schema {
				$this->calls[] = 'schema find '.json_encode($args[0]);
				if (in_array((int)$args[0], [7, 8], true) === false) {
					throw new RuntimeException('no schema');
				}

				return $this->schema(id: (int)$args[0], appendOnly: ($case['appendOnly'] ?? false));
			}
		);

		return $mapper;
	}//end schemaMapper()

	/**
	 * A register mapper that knows registers 10 and 11.
	 *
	 * @return RegisterMapper
	 */
	private function registerMapper(): RegisterMapper {
		$mapper = $this->createMock(originalClassName: RegisterMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (...$args): Register {
				$this->calls[] = 'register find '.json_encode($args[0]);
				if (in_array((int)$args[0], [10, 11], true) === false) {
					throw new RuntimeException('no register');
				}

				return $this->register(id: (int)$args[0]);
			}
		);

		return $mapper;
	}//end registerMapper()

	/**
	 * A session for bob, carol, an administrator, or nobody.
	 *
	 * @param string|null $user The user
	 *
	 * @return IUserSession
	 */
	private function userSession(?string $user): IUserSession {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		if ($user !== null) {
			$account = $this->createMock(originalClassName: IUser::class);
			$account->method('getUID')->willReturn($user);
			$session = $this->createMock(originalClassName: IUserSession::class);
			$session->method('getUser')->willReturn($account);
		}

		return $session;
	}//end userSession()

	/**
	 * A group manager for which `admin` is an administrator, or which fails.
	 *
	 * @param string|null $user The user
	 *
	 * @return IGroupManager
	 */
	private function groupManager(?string $user): IGroupManager {
		$manager = $this->createMock(originalClassName: IGroupManager::class);
		if ($user === 'throws') {
			$manager->method('isAdmin')->willThrowException(new RuntimeException('groups gone'));
			return $manager;
		}

		$manager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		return $manager;
	}//end groupManager()

	/**
	 * A permission handler that refuses the listed actions (`action` or `action:schemaId`).
	 *
	 * @param list<string> $deny The refused actions
	 *
	 * @return PermissionHandler
	 */
	private function permissionHandler(array $deny): PermissionHandler {
		$handler = $this->createMock(originalClassName: PermissionHandler::class);
		$handler->method('hasPermission')->willReturnCallback(
			function (...$args) use ($deny): bool {
				$this->calls[] = 'hasPermission '.json_encode(
					[$args[0]->getId(), $args[1], ($args[2] ?? null), ($args[3] ?? null), ($args[4] ?? true), ($args[5] ?? null)?->getUuid()]
				);
				$key = $args[1].':'.$args[0]->getId();

				return in_array($args[1], $deny, true) === false && in_array($key, $deny, true) === false;
			}
		);

		return $handler;
	}//end permissionHandler()

	/**
	 * A validator whose rows are valid, invalid, or throw.
	 *
	 * @param string $mode `valid`, `invalid` or `throws`
	 *
	 * @return ValidateObject
	 */
	private function validator(string $mode): ValidateObject {
		$validation = $this->createMock(originalClassName: ValidationResult::class);
		$validation->method('isValid')->willReturn($mode === 'valid');

		$validator = $this->createMock(originalClassName: ValidateObject::class);
		$validator->method('validateObject')->willReturnCallback(
			function (...$args) use ($mode, $validation): ValidationResult {
				$this->calls[] = 'validateObject '.json_encode([$args[0], $args[1]->getId()]);
				if ($mode === 'throws') {
					throw new RuntimeException('validator broke');
				}

				return $validation;
			}
		);
		$validator->method('generateErrorMessage')->willReturn('title is required');

		return $validator;
	}//end validator()

	/**
	 * Property authorization that refuses the listed properties, or restores a write-only one.
	 *
	 * @param list<string>|string $mode The refused properties, or `restore`
	 *
	 * @return PropertyRbacHandler
	 */
	private function propertyRbac(array|string $mode): PropertyRbacHandler {
		$rbac = $this->createMock(originalClassName: PropertyRbacHandler::class);
		$rbac->method('getUnauthorizedProperties')->willReturnCallback(
			function (...$args) use ($mode): array {
				$this->calls[] = 'getUnauthorizedProperties '.json_encode([$args[1], $args[2], ($args[3] ?? false)]);
				if (is_array($mode) === true) {
					return $mode;
				}

				return [];
			}
		);
		$rbac->method('collectOmittedProtectedProperties')->willReturn(['secret']);
		$rbac->method('restoreWriteOnlyValues')->willReturnCallback(
			static fn (...$args): array => ($args[0] + ['secret' => $args[1]['secret']])
		);

		return $rbac;
	}//end propertyRbac()
}//end class

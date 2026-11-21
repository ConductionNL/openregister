<?php

/**
 * Characterisation golden for the register, application, source and consumer list reads,
 * and for the register save-time clean-up and authorization refusal.
 *
 * Pins, on the code as it was before those units were split, the exact query each
 * findAll() builds (columns, filters, search conditions, paging, the organisation
 * scope), the RBAC verdict that admits or refuses the read, the defaults
 * cleanObject() fills in, and which authorization blocks validateAuthorizationDeny()
 * stores and which it refuses (exception class and message).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use OCA\OpenRegister\Db\RegisterMapperServices;

/**
 * @coversNothing
 */
final class FindAllMappersGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/find-all-mappers-golden.json';

	/**
	 * The findAll() argument sets every mapper is read with.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function readCases(): array {
		return [
			'defaults' => [],
			'paged' => [10, 20],
			'null offset' => [5, null],
			'column filters' => [null, null, ['status' => 'IS NULL', 'title' => 'IS NOT NULL', 'slug' => 'abc']],
			'null filters' => [null, null, null],
			'search with params' => [null, null, [], ['title LIKE :t', 'description LIKE :t'], ['t' => '%x%', 'n' => 3]],
			'search with null params' => [null, null, [], ['title LIKE :t'], null],
			'params without search' => [null, null, [], [], ['t' => 'unused']],
			'everything' => [3, 6, ['slug' => 'abc'], ['a = :a'], ['a' => 1]],
		];
	}//end readCases()

	/**
	 * The callers a read is made as.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function personas(): array {
		return [
			'admin' => ['uid' => 'root', 'admin' => true, 'groups' => ['admin']],
			'granted user' => ['uid' => 'alice', 'admin' => false, 'groups' => ['readers']],
			'refused user' => ['uid' => 'bob', 'admin' => false, 'groups' => ['others']],
		];
	}//end personas()

	/**
	 * Every outcome matches the recording.
	 *
	 * @return void
	 */
	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::readCases() as $name => $args) {
			$actual['consumer: ' . $name] = $this->recorded(fn () => $this->consumerMapper()->findAll(...array_slice($args, 0, 3)));
			foreach ([[true, true], [false, true], [true, false], [false, false]] as [$rbac, $mt]) {
				$label = 'register rbac ' . var_export($rbac, true) . ' mt ' . var_export($mt, true) . ': ' . $name;
				$actual[$label] = $this->recorded(
					fn () => $this->registerMapper(persona: self::personas()['granted user'])->findAll(...[...($args + [null, null, [], [], []]), $rbac, $mt])
				);
			}

			foreach (self::personas() as $who => $persona) {
				$actual['application ' . $who . ': ' . $name] = $this->recorded(fn () => $this->applicationMapper(persona: $persona)->findAll(...$this->applicationArgs($args)));
				$actual['source ' . $who . ': ' . $name] = $this->recorded(fn () => $this->sourceMapper(persona: $persona)->findAll(...$args));
			}
		}

		foreach (self::cleanCases() as $name => $register) {
			$actual['clean: ' . $name] = $this->recorded(fn () => $this->invokePrivate(method: 'cleanObject', register: $register));
		}

		foreach (self::authorizationCases() as $name => $register) {
			$actual['deny: ' . $name] = $this->recorded(fn () => $this->invokePrivate(method: 'validateAuthorizationDeny', register: $register));
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	/**
	 * The golden holds at least one admitted and one refused read and save, so it can tell them apart.
	 *
	 * @return void
	 */
	public function testGoldenHoldsAllowedAndRefusedOutcomes(): void {
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertArrayNotHasKey('thrown', $expected['application granted user: defaults']);
		$this->assertArrayHasKey('thrown', $expected['application refused user: defaults']);
		$this->assertArrayNotHasKey('thrown', $expected['source granted user: defaults']);
		$this->assertArrayHasKey('thrown', $expected['source refused user: defaults']);
		$this->assertArrayNotHasKey('thrown', $expected['deny: deny contradicting nothing']);
		$this->assertArrayHasKey('thrown', $expected['deny: grant and deny on one principal']);
		$this->assertArrayHasKey('thrown', $expected['deny: unknown verb in a role definition']);
	}//end testGoldenHoldsAllowedAndRefusedOutcomes()

	/**
	 * Registers for cleanObject().
	 *
	 * @return array<string, Register>
	 */
	private static function cleanCases(): array {
		$bare = new Register();

		$titled = new Register();
		$titled->setTitle('  Zaken & Besluiten -- 2026!  ');

		$complete = new Register();
		$complete->setUuid('u-1');
		$complete->setSlug('kept');
		$complete->setVersion('2.0.0');
		$complete->setSource('external');

		$emptySource = new Register();
		$emptySource->setSlug('0');
		$emptySource->setSource('');

		$dashes = new Register();
		$dashes->setTitle('---');

		return ['bare' => $bare, 'titled' => $titled, 'complete' => $complete, 'empty source and zero slug' => $emptySource, 'only dashes' => $dashes];
	}//end cleanCases()

	/**
	 * Registers for validateAuthorizationDeny().
	 *
	 * @return array<string, Register>
	 */
	private static function authorizationCases(): array {
		$cases = [];
		$with = static function (?array $authorization, array $configuration = [], ?string $slug = 'zaken', ?string $title = 'Zaken'): Register {
			$register = new Register();
			$register->setId(1);
			$register->setTitle($title);
			$register->setSlug($slug);
			$register->setAuthorization($authorization);
			$register->setConfiguration($configuration);
			return $register;
		};

		$cases['null block'] = $with(null);
		$cases['empty block'] = $with([]);
		$cases['empty block, null roles'] = $with([], ['roles' => null]);
		$cases['empty block, valid roles'] = $with([], ['roles' => ['editor' => ['actions' => ['read', 'update']]]]);
		$cases['unknown verb in a role definition'] = $with([], ['roles' => ['editor' => ['actions' => ['frobnicate']]]]);
		$cases['null block, unknown verb in a role definition'] = $with(null, ['roles' => ['editor' => ['actions' => ['frobnicate']]]]);
		$cases['plain grants'] = $with(['read' => ['iedereen'], 'manage' => ['beheerders']]);
		$cases['unknown verb in the block'] = $with(['frobnicate' => ['iedereen'], 'manage' => ['beheerders']]);
		$cases['deny contradicting nothing'] = $with(['read' => ['iedereen'], 'manage' => ['beheerders'], 'deny' => ['read' => ['stagiairs']]]);
		$cases['grant and deny on one principal'] = $with(['delete' => ['behandelaars'], 'manage' => ['beheerders'], 'deny' => ['delete' => ['behandelaars']]]);
		$cases['manage denied to its last holder'] = $with(['manage' => ['beheerders'], 'deny' => ['manage' => ['beheerders']]]);
		$cases['refusal subject from the title'] = $with(['delete' => ['b'], 'deny' => ['delete' => ['b']]], [], null, 'Alleen titel');
		$cases['refusal subject with neither'] = $with(['delete' => ['b'], 'deny' => ['delete' => ['b']]], [], null, null);
		$cases['roles and block both valid'] = $with(['read' => ['iedereen']], ['roles' => ['editor' => ['actions' => ['read']]]]);

		return $cases;
	}//end authorizationCases()

	/**
	 * The application mapper takes no null filters; pass the declared defaults instead.
	 *
	 * @param array<int, mixed> $args The read arguments.
	 *
	 * @return array<int, mixed>
	 */
	private function applicationArgs(array $args): array {
		$args = array_slice($args, 0, 3);
		foreach ([2] as $index) {
			if (array_key_exists($index, $args) === true && $args[$index] === null) {
				$args[$index] = [];
			}
		}

		return $args;
	}//end applicationArgs()

	/**
	 * The queries a call made and what it answered or threw.
	 *
	 * @param callable $call The call.
	 *
	 * @return array<string, mixed>
	 */
	private function recorded(callable $call): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [];
		$outcome = [];
		set_error_handler(
			function (int $errno, string $errstr) use (&$outcome): bool {
				$outcome['warnings'][] = $errstr;
				return true;
			}
		);
		try {
			$result = $call();
			if ($result instanceof Register) {
				$outcome['register'] = [
					'uuid' => $result->getUuid() === null ? null : 'set',
					'slug' => $result->getSlug(),
					'version' => $result->getVersion(),
					'source' => $result->getSource(),
				];
			} elseif (is_array($result) === true) {
				$outcome['rows'] = count($result);
			}
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e);
			$outcome['message'] = $e->getMessage();
			$outcome['code'] = $e->getCode();
		} finally {
			restore_error_handler();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end recorded()

	/**
	 * Run a private RegisterMapper step on a register and hand the register back.
	 *
	 * @param string $method The method name.
	 * @param Register $register The register.
	 *
	 * @return Register
	 */
	private function invokePrivate(string $method, Register $register): Register {
		$register = clone $register;
		(new ReflectionMethod(RegisterMapper::class, $method))->invoke($this->registerMapper(persona: self::personas()['admin']), $register);
		return $register;
	}//end invokePrivate()

	/**
	 * A connection handing out recording builders.
	 *
	 * @return IDBConnection
	 */
	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return $db;
	}//end connection()

	/**
	 * Session, groups and organisation for one caller.
	 *
	 * @param array<string, mixed> $persona The caller.
	 *
	 * @return array{0: IUserSession, 1: IGroupManager, 2: OrganisationMapper}
	 */
	private function caller(array $persona): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($persona['uid']);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($persona['admin']);
		$groups->method('getUserGroupIds')->willReturn($persona['groups']);

		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setAuthorization(['application' => ['read' => ['readers']], 'source' => ['read' => ['readers']]]);
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('isEntityRbacEnforcementEnabled')->willReturn(true);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');
		$organisations->method('findByUuid')->willReturn($organisation);
		$organisations->method('findParentChain')->willReturn([]);

		return [$session, $groups, $organisations];
	}//end caller()

	/**
	 * A register mapper.
	 *
	 * @param array<string, mixed> $persona The caller.
	 *
	 * @return RegisterMapper
	 */
	private function registerMapper(array $persona): RegisterMapper {
		[$session, $groups, $organisations] = $this->caller(persona: $persona);
		return new RegisterMapper(
			db: $this->connection(),
			tenancy: new TenancyServices($organisations, $session, $groups),
			appConfig: $this->createMock(IAppConfig::class),
			services: new RegisterMapperServices(
				schemaMapper: $this->createMock(SchemaMapper::class),
				container: $this->createMock(ContainerInterface::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: new NullLogger()
			)
		);
	}//end registerMapper()

	/**
	 * An application mapper.
	 *
	 * @param array<string, mixed> $persona The caller.
	 *
	 * @return ApplicationMapper
	 */
	private function applicationMapper(array $persona): ApplicationMapper {
		[$session, $groups, $organisations] = $this->caller(persona: $persona);
		return new ApplicationMapper($this->connection(), new TenancyServices($organisations, $session, $groups), new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)));
	}//end applicationMapper()

	/**
	 * A source mapper.
	 *
	 * @param array<string, mixed> $persona The caller.
	 *
	 * @return SourceMapper
	 */
	private function sourceMapper(array $persona): SourceMapper {
		[$session, $groups, $organisations] = $this->caller(persona: $persona);
		return new SourceMapper($this->connection(), new TenancyServices($organisations, $session, $groups), new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)), $this->createMock(IAppConfig::class));
	}//end sourceMapper()

	/**
	 * A consumer mapper.
	 *
	 * @return ConsumerMapper
	 */
	private function consumerMapper(): ConsumerMapper {
		return new ConsumerMapper($this->connection());
	}//end consumerMapper()
}//end class

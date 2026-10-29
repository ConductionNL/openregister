<?php

/**
 * OpenRegister - every verdict and log line of PermissionHandler::evaluatePermission().
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\CustomScopeEvaluatingEvent;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Pins the verdict and the log lines of the RBAC rule chain for every way out of
 * it: unresolvable rules, an enforced or staged deny, the private scope, anonymous
 * and unknown callers, administrators, derived groups, custom verbs with and
 * without a listener, the authenticated, delegation, group and public grants, and
 * the refusal.
 *
 * Expected values live in `fixtures/evaluate-permission.php`, one entry per case.
 */
class PermissionHandlerEvaluatePermissionTest extends TestCase {

	/**
	 * Every case.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cases(): array {
		$ana     = ['id' => 'ana', 'groups' => ['behandelaars']];
		$private = ['owner' => 'bea', 'authorization' => ['scope' => ObjectScopeResolver::SCOPE_PRIVATE]];

		$cases = [
			'unresolvable rules'                => ['auth' => null, 'user' => $ana, 'container' => 'broken'],
			'unresolvable rules anonymous'      => ['auth' => null, 'user' => null, 'container' => 'broken'],
			'schema rules skip the register'    => ['auth' => ['read' => ['behandelaars']], 'user' => $ana, 'container' => 'broken'],
			'deny enforced'                     => ['auth' => ['read' => ['behandelaars'], 'deny' => ['read' => ['behandelaars']]], 'user' => $ana],
			'deny staged'                       => [
				'auth'     => ['read' => ['behandelaars'], 'deny' => ['read' => ['behandelaars']]],
				'user'     => $ana,
				'denyMode' => DenyEnforcementMode::MODE_STAGING,
			],
			'deny other verb'                   => ['auth' => ['read' => ['behandelaars'], 'deny' => ['update' => ['behandelaars']]], 'user' => $ana],
			'deny for admin'                    => [
				'auth' => ['read' => ['behandelaars'], 'deny' => ['read' => ['admin']]],
				'user' => ['id' => 'root', 'groups' => ['admin']],
			],
			'private refuses non-owner'         => ['auth' => ['read' => ['behandelaars']], 'user' => $ana, 'object' => $private],
			'private admits owner'              => [
				'auth'   => ['read' => ['behandelaars']],
				'user'   => ['id' => 'bea', 'groups' => []],
				'object' => $private,
			],
			'private admits invited'            => [
				'auth'   => ['read' => []],
				'user'   => $ana,
				'object' => ['owner' => 'bea', 'authorization' => ['scope' => 'private', 'read' => ['behandelaars']]],
			],
			'organisation scope'                => [
				'auth'   => ['read' => ['behandelaars']],
				'user'   => $ana,
				'object' => ['owner' => 'bea', 'authorization' => ['scope' => 'organisation']],
			],
			'per-object rules override'         => [
				'auth'   => ['read' => ['behandelaars']],
				'user'   => $ana,
				'object' => ['owner' => 'bea', 'authorization' => ['read' => ['anderen']]],
			],
			'owner bypass on object'            => [
				'auth'   => ['update' => ['anderen']],
				'user'   => ['id' => 'bea', 'groups' => []],
				'action' => 'update',
				'object' => ['owner' => 'bea'],
			],
			'no rules at all'                   => ['auth' => null, 'user' => $ana],
			'no rules at all anonymous read'    => ['auth' => null, 'user' => null],
			'no rules at all anonymous create'  => ['auth' => null, 'user' => null, 'action' => 'create'],
			'anonymous create public granted'   => ['auth' => ['create' => ['public']], 'user' => null, 'action' => 'create'],
			'anonymous update not granted'      => ['auth' => ['read' => ['public']], 'user' => null, 'action' => 'update'],
			'anonymous delete public granted'   => ['auth' => ['delete' => ['public']], 'user' => null, 'action' => 'delete'],
			'anonymous read public'             => ['auth' => ['read' => ['public']], 'user' => null],
			'anonymous read not public'         => ['auth' => ['read' => ['behandelaars']], 'user' => null],
			'anonymous custom verb'             => ['auth' => ['sign' => ['public']], 'user' => null, 'action' => 'sign'],
			'unknown user public'               => ['auth' => ['read' => ['public']], 'user' => 'ghost'],
			'unknown user not public'           => ['auth' => ['read' => ['behandelaars']], 'user' => 'ghost'],
			'admin'                             => ['auth' => ['read' => ['niemand']], 'user' => ['id' => 'root', 'groups' => ['admin', 'x']]],
			'admin custom verb'                 => [
				'auth'     => [],
				'user'     => ['id' => 'root', 'groups' => ['admin']],
				'action'   => 'sign',
				'vote'     => 'deny',
				'declared' => ['sign'],
			],
			'session user'                      => ['auth' => ['read' => ['behandelaars']], 'user' => $ana, 'viaSession' => true],
			'group grant'                       => ['auth' => ['read' => ['anderen', 'behandelaars']], 'user' => $ana],
			'group grant second group'          => ['auth' => ['read' => ['tweede']], 'user' => ['id' => 'ana', 'groups' => ['eerste', 'tweede']]],
			'no matching group'                 => ['auth' => ['read' => ['anderen']], 'user' => $ana],
			'no rule for verb'                  => ['auth' => ['read' => ['anderen']], 'user' => $ana, 'action' => 'update'],
			'authenticated grant'               => ['auth' => ['read' => ['authenticated']], 'user' => ['id' => 'cas', 'groups' => []]],
			'user override grant'               => [
				'auth' => ['read' => [['user' => 'ana'], 'anderen']],
				'user' => ['id' => 'ana', 'groups' => []],
			],
			'inherit from public'               => ['auth' => ['read' => ['public']], 'user' => $ana],
			'inherit from public switched off'  => ['auth' => ['read' => ['public'], 'inheritFromPublic' => false], 'user' => $ana],
			'derived group grant'               => ['auth' => ['read' => ['afgeleid']], 'user' => $ana, 'derived' => ['afgeleid', 'admin']],
			'derived admin is ignored'          => ['auth' => ['read' => ['niemand']], 'user' => $ana, 'derived' => ['admin', 'public']],
			'custom verb listener allows'       => ['auth' => [], 'user' => $ana, 'action' => 'sign', 'vote' => 'allow', 'declared' => ['sign']],
			'custom verb listener denies'       => [
				'auth'     => ['sign' => ['behandelaars']],
				'user'     => $ana,
				'action'   => 'sign',
				'vote'     => 'deny',
				'declared' => ['sign'],
			],
			'custom verb undeclared vote'       => ['auth' => [], 'user' => $ana, 'action' => 'sign', 'vote' => 'allow'],
			'custom verb listener throws'       => ['auth' => ['sign' => ['behandelaars']], 'user' => $ana, 'action' => 'sign', 'vote' => 'throws'],
			'custom verb declared, no vote'     => [
				'auth'     => ['sign' => ['behandelaars']],
				'user'     => $ana,
				'action'   => 'sign',
				'vote'     => 'none',
				'declared' => ['sign'],
			],
			'custom verb undeclared, no vote'   => ['auth' => ['sign' => ['behandelaars']], 'user' => $ana, 'action' => 'sign', 'vote' => 'none'],
			'custom verb without a dispatcher'  => ['auth' => ['sign' => ['behandelaars']], 'user' => $ana, 'action' => 'sign'],
			'custom verb canonical declaration' => [
				'auth'     => ['sign' => ['anderen']],
				'user'     => $ana,
				'action'   => 'sign',
				'vote'     => 'none',
				'declared' => ['sign' => true],
			],
			'canonical verb with a dispatcher'  => ['auth' => ['read' => ['behandelaars']], 'user' => $ana, 'vote' => 'deny'],
		];

		foreach (['create', 'update', 'delete'] as $action) {
			$cases["verb $action granted"] = ['auth' => [$action => ['behandelaars']], 'user' => $ana, 'action' => $action];
			$cases["verb $action refused"] = ['auth' => [$action => ['anderen']], 'user' => $ana, 'action' => $action];
		}

		return $cases;
	}//end cases()

	/**
	 * Every case paired with its expected outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function casesWithExpectations(): array {
		$expected = require __DIR__.'/fixtures/evaluate-permission.php';
		$pairs    = [];
		foreach (self::cases() as $name => $case) {
			$pairs[$name] = [$case, $expected[$name] ?? ['missing expectation' => $name]];
		}

		return $pairs;
	}//end casesWithExpectations()

	/**
	 * The chain answers the recorded verdict and logs the recorded lines.
	 *
	 * @param array<string, mixed> $case     The case
	 * @param array<string, mixed> $expected The recorded outcome
	 *
	 * @return void
	 */
	#[DataProvider('casesWithExpectations')]
	public function testChainGivesTheRecordedVerdict(array $case, array $expected): void {
		$this->assertSame(expected: $expected, actual: $this->runCase(case: $case));
	}//end testChainGivesTheRecordedVerdict()

	/**
	 * Every case has a recorded expectation and every expectation a case.
	 *
	 * @return void
	 */
	public function testEveryCaseIsRecorded(): void {
		$expected = require __DIR__.'/fixtures/evaluate-permission.php';
		$this->assertSame(expected: array_keys(self::cases()), actual: array_keys($expected));
	}//end testEveryCaseIsRecorded()

	/**
	 * Run one case: the verdict and the log lines.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return array<string, mixed>
	 */
	public function runCase(array $case): array {
		$logger = new class extends AbstractLogger {
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

		$schema = new Schema();
		$schema->setId(41);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($case['auth']);

		$object = null;
		if (isset($case['object']) === true) {
			$object = new ObjectEntity();
			$object->setUuid('11111111-2222-3333-4444-555555555555');
			$object->setObject(['title' => 'een zaak']);
			$object->setOwner($case['object']['owner']);
			$object->setAuthorization($case['object']['authorization'] ?? null);
		}

		$userId = null;
		if (is_array($case['user']) === true && ($case['viaSession'] ?? false) === false) {
			$userId = $case['user']['id'];
		}

		if ($case['user'] === 'ghost') {
			$userId = 'ghost';
		}

		$method  = new ReflectionMethod(PermissionHandler::class, 'evaluatePermission');
		$verdict = $method->invoke(
			$this->handler(case: $case, logger: $logger),
			$schema,
			($case['action'] ?? 'read'),
			$userId,
			$object?->getOwner(),
			$object
		);

		return ['verdict' => $verdict, 'log' => $logger->lines];
	}//end runCase()

	/**
	 * A handler wired the way the case says.
	 *
	 * @param array<string, mixed> $case   The case
	 * @param AbstractLogger       $logger The logger
	 *
	 * @return PermissionHandler
	 */
	private function handler(array $case, AbstractLogger $logger): PermissionHandler {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturn($case['denyMode'] ?? DenyEnforcementMode::MODE_ENFORCING);

		return new PermissionHandler(
			userSession: $this->userSession(case: $case),
			userManager: $this->userManager(case: $case),
			groupManager: $this->groupManager(case: $case),
			schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
			objectEntityMapper: $this->createMock(originalClassName: MagicMapper::class),
			conditionMatcher: $this->createMock(originalClassName: ConditionMatcher::class),
			appConfig: $appConfig,
			logger: $logger,
			container: $this->container(broken: (($case['container'] ?? '') === 'broken')),
			eventDispatcher: $this->eventDispatcher(vote: ($case['vote'] ?? null)),
			objectScopeResolver: new ObjectScopeResolver(),
			denyResolver: new DenyResolver(matcher: new DenyEntryMatcher()),
			denyEnforcementMode: new DenyEnforcementMode(appConfig: $appConfig, logger: $logger),
			permissionCatalogue: $this->catalogue(declared: ($case['declared'] ?? [])),
			derivedGrantStore: $this->derivedStore(derived: ($case['derived'] ?? [])),
			derivedGrantResolver: $this->derivedResolver(derived: ($case['derived'] ?? []))
		);
	}//end handler()

	/**
	 * A session holding the case's user, or nobody.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return IUserSession
	 */
	private function userSession(array $case): IUserSession {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		if (is_array($case['user']) === true) {
			$session = $this->createMock(originalClassName: IUserSession::class);
			$session->method('getUser')->willReturn($this->user(uid: $case['user']['id']));
		}

		return $session;
	}//end userSession()

	/**
	 * A user manager that knows the case's user and nobody else.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return IUserManager
	 */
	private function userManager(array $case): IUserManager {
		$known = null;
		if (is_array($case['user']) === true) {
			$known = $this->user(uid: $case['user']['id']);
		}

		$manager = $this->createMock(originalClassName: IUserManager::class);
		$manager->method('get')->willReturnCallback(
			static function (string $uid) use ($known): ?IUser {
				if ($known !== null && $known->getUID() === $uid) {
					return $known;
				}

				return null;
			}
		);

		return $manager;
	}//end userManager()

	/**
	 * A group manager answering the case's groups.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return IGroupManager
	 */
	private function groupManager(array $case): IGroupManager {
		$groups = [];
		if (is_array($case['user']) === true) {
			$groups = $case['user']['groups'];
		}

		$manager = $this->createMock(originalClassName: IGroupManager::class);
		$manager->method('getUserGroupIds')->willReturn($groups);
		$manager->method('isAdmin')->willReturn(in_array('admin', $groups, true));

		return $manager;
	}//end groupManager()

	/**
	 * A user with an id.
	 *
	 * @param string $uid The id
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * A container whose register mapper finds no register, or fails.
	 *
	 * @param bool $broken Whether the register lookup fails
	 *
	 * @return ContainerInterface
	 */
	private function container(bool $broken): ContainerInterface {
		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(null);
		if ($broken === true) {
			$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
			$registerMapper->method('getFirstRegisterWithSchema')->willThrowException(new RuntimeException('database gone'));
		}

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapper::class) {
					return $registerMapper;
				}

				throw new RuntimeException('nothing registered for '.$id);
			}
		);

		return $container;
	}//end container()

	/**
	 * A dispatcher whose listener allows, denies, stays silent or throws; or none.
	 *
	 * @param string|null $vote The listener's vote
	 *
	 * @return IEventDispatcher|null
	 */
	private function eventDispatcher(?string $vote): ?IEventDispatcher {
		if ($vote === null) {
			return null;
		}

		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function ($event) use ($vote): void {
				if ($event instanceof CustomScopeEvaluatingEvent === false) {
					return;
				}

				if ($vote === 'throws') {
					throw new \Exception('listener failed');
				}

				if ($vote === 'allow') {
					$event->allow();
				}

				if ($vote === 'deny') {
					$event->deny();
				}
			}
		);

		return $dispatcher;
	}//end eventDispatcher()

	/**
	 * A catalogue declaring the case's custom verbs; `verb => true` declares it canonical.
	 *
	 * @param array<int|string, mixed> $declared The declared verbs
	 *
	 * @return PermissionCatalogue
	 */
	private function catalogue(array $declared): PermissionCatalogue {
		$declarations = [];
		foreach ($declared as $key => $value) {
			if (is_int($key) === true) {
				$declarations[$value] = ['canonical' => false, 'app' => 'zaken-app'];
				continue;
			}

			$declarations[$key] = ['canonical' => $value, 'app' => 'openregister'];
		}

		$catalogue = $this->createMock(originalClassName: PermissionCatalogue::class);
		$catalogue->method('declarationFor')->willReturnCallback(static fn (string $verb): ?array => ($declarations[$verb] ?? null));
		$catalogue->method('isGrantable')->willReturnCallback(static fn (string $verb): bool => isset($declarations[$verb]));

		return $catalogue;
	}//end catalogue()

	/**
	 * A derived-grant store that holds one grant when the case derives groups.
	 *
	 * @param list<string> $derived The derived groups
	 *
	 * @return DerivedGrantStore
	 */
	private function derivedStore(array $derived): DerivedGrantStore {
		$store = $this->createMock(originalClassName: DerivedGrantStore::class);
		$store->method('grantsFor')->willReturn([]);
		if ($derived !== []) {
			$store = $this->createMock(originalClassName: DerivedGrantStore::class);
			$store->method('grantsFor')->willReturn([['group' => 'derived']]);
		}

		return $store;
	}//end derivedStore()

	/**
	 * A derived-grant resolver answering the case's groups.
	 *
	 * @param list<string> $derived The derived groups
	 *
	 * @return DerivedGrantResolver
	 */
	private function derivedResolver(array $derived): DerivedGrantResolver {
		$resolver = $this->createMock(originalClassName: DerivedGrantResolver::class);
		$resolver->method('groupsFor')->willReturn($derived);

		return $resolver;
	}//end derivedResolver()
}//end class

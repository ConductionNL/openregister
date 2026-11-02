<?php

/**
 * OpenRegister - what the RBAC rule chain hands to its collaborators.
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

use Error;
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
 * Pins what evaluatePermission() passes on: the envelope a conditional rule is
 * matched against, the payload of the custom-verb event, the caller id the
 * private scope and the deny pass see, unusual group lists, and which
 * exceptions escape.
 *
 * Every call to a collaborator is recorded in order in `$trace`.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\CustomScopeEvaluatedEvent
 * @uses   \OCA\OpenRegister\Event\CustomScopeEvaluatingEvent
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class PermissionHandlerChainContractTest extends TestCase {

	private const UUID = '11111111-2222-3333-4444-555555555555';

	private const REFUSED = 'info: [PermissionHandler] Action refused; the rule that decided it is named below ';

	/**
	 * The collaborator calls of the current case, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * The log lines of the current case, without file and line.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * Conditional rules, one per way a caller reaches a group: what the matcher receives.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool, 2: list<array<string, mixed>>}>
	 */
	public static function conditionalRules(): array {
		$match    = ['organisation' => 'org-1'];
		$object   = ['owner' => 'bea', 'organisation' => 'org-1', 'data' => ['title' => 't', '@self' => ['x' => 1]]];
		$envelope = ['id' => self::UUID, 'title' => 't', '@self' => ['x' => 1, 'organisation' => 'org-1']];
		$ana      = ['id' => 'ana', 'groups' => ['behandelaars']];
		$matched  = [['cond' => [$envelope, $match]]];

		return [
			'a group'                         => [
				['auth' => ['read' => [['group' => 'behandelaars', 'match' => $match]]], 'user' => $ana, 'object' => $object],
				true,
				[['userManager.get' => 'ana'], ...$matched],
			],
			'the object keeps its own @self.organisation' => [
				[
					'auth'   => ['read' => [['group' => 'behandelaars', 'match' => $match]]],
					'user'   => $ana,
					'object' => ['owner' => 'bea', 'organisation' => 'org-1', 'data' => ['title' => 't', '@self' => ['organisation' => 'own']]],
				],
				true,
				[['userManager.get' => 'ana'], ['cond' => [['id' => self::UUID, 'title' => 't', '@self' => ['organisation' => 'own']], $match]]],
			],
			'public for an anonymous reader'  => [
				['auth' => ['read' => [['group' => 'public', 'match' => $match]]], 'user' => null, 'object' => $object],
				true,
				$matched,
			],
			'public for an anonymous create'  => [
				['auth' => ['create' => [['group' => 'public', 'match' => $match]]], 'user' => null, 'object' => $object, 'action' => 'create'],
				true,
				$matched,
			],
			'public for an unknown user id'   => [
				['auth' => ['read' => [['group' => 'public', 'match' => $match]]], 'user' => 'ghost', 'object' => $object],
				true,
				[['userManager.get' => 'ghost'], ...$matched],
			],
			'authenticated without groups'    => [
				['auth' => ['read' => [['group' => 'authenticated', 'match' => $match]]], 'user' => ['id' => 'cas', 'groups' => []], 'object' => $object],
				true,
				[['userManager.get' => 'cas'], ...$matched],
			],
			'a user override'                 => [
				['auth' => ['read' => [['user' => 'ana', 'match' => $match]]], 'user' => ['id' => 'ana', 'groups' => []], 'object' => $object],
				true,
				[['userManager.get' => 'ana'], ...$matched],
			],
			'public inherited by a user'      => [
				['auth' => ['read' => [['group' => 'public', 'match' => $match]]], 'user' => $ana, 'object' => $object],
				true,
				[['userManager.get' => 'ana'], ...$matched],
			],
		];
	}//end conditionalRules()

	/**
	 * A conditional rule is matched against the object data with the organisation folded into @self.
	 *
	 * @param array<string, mixed>       $case    The case
	 * @param bool                       $verdict The expected verdict
	 * @param list<array<string, mixed>> $trace   The expected collaborator calls
	 *
	 * @return void
	 */
	#[DataProvider('conditionalRules')]
	public function testAConditionalRuleIsMatchedOnTheObjectEnvelope(array $case, bool $verdict, array $trace): void {
		$this->assertSame(expected: $verdict, actual: $this->evaluate(case: $case));
		$this->assertSame(expected: $trace, actual: $this->trace);
	}//end testAConditionalRuleIsMatchedOnTheObjectEnvelope()

	/**
	 * With no match anywhere every entry is asked in order: authenticated, then the
	 * user override on each entry while walking each group, and the refusal is logged.
	 *
	 * @return void
	 */
	public function testEveryConditionalEntryIsAskedInOrderBeforeARefusal(): void {
		$rules = [
			['group' => 'authenticated', 'match' => ['a' => 1]],
			['group' => 'behandelaars', 'match' => ['b' => 2]],
			['group' => 'public', 'match' => ['c' => 3]],
			['user' => 'ana', 'match' => ['d' => 4]],
		];
		$case  = [
			'auth'      => ['read' => $rules],
			'user'      => ['id' => 'ana', 'groups' => ['behandelaars']],
			'object'    => ['owner' => 'bea', 'organisation' => 'org-1', 'data' => ['title' => 't']],
			'condFalse' => true,
		];

		$this->assertFalse(condition: $this->evaluate(case: $case));
		$asked = [];
		foreach ($this->trace as $call) {
			if (isset($call['cond']) === true) {
				$asked[] = $call['cond'][1];
			}
		}

		$this->assertSame(expected: [['a' => 1], ['d' => 4], ['d' => 4], ['b' => 2], ['d' => 4], ['c' => 3], ['d' => 4]], actual: $asked);
		$this->assertCount(expectedCount: 1, haystack: $this->lines);
		$this->assertStringStartsWith(prefix: 'info: [PermissionHandler] Action refused;', string: $this->lines[0]);
	}//end testEveryConditionalEntryIsAskedInOrderBeforeARefusal()

	/**
	 * The custom-verb event carries the caller, their groups with the derived ones folded in, and the object.
	 *
	 * @return void
	 */
	public function testTheCustomVerbEventCarriesTheCallerTheDerivedGroupsAndTheObject(): void {
		$case = [
			'auth'    => [],
			'user'    => ['id' => 'ana', 'groups' => ['behandelaars']],
			'action'  => 'sign',
			'vote'    => 'record',
			'derived' => ['afgeleid', 'behandelaars'],
			'object'  => ['owner' => 'bea', 'data' => ['title' => 't']],
		];

		$this->assertTrue(condition: $this->evaluate(case: $case));
		$this->assertSame(
			expected: [['userManager.get' => 'ana'], ['event' => ['sign', 'ana', ['behandelaars', 'afgeleid'], self::UUID]]],
			actual: $this->trace
		);
	}//end testTheCustomVerbEventCarriesTheCallerTheDerivedGroupsAndTheObject()

	/**
	 * A caller from the session reaches the event by id; a declared verb nobody decides is refused.
	 *
	 * @return void
	 */
	public function testTheCustomVerbEventNamesTheSessionCaller(): void {
		$case = [
			'auth'       => [],
			'user'       => ['id' => 'ana', 'groups' => ['behandelaars']],
			'viaSession' => true,
			'action'     => 'sign',
			'vote'       => 'record',
			'declared'   => ['sign'],
		];

		$this->assertFalse(condition: $this->evaluate(case: $case));
		$this->assertSame(
			expected: [['session.getUser' => 'ana'], ['userManager.get' => 'ana'], ['event' => ['sign', 'ana', ['behandelaars'], null]]],
			actual: $this->trace
		);
		$this->assertSame(
			expected: [
				'warning: [PermissionHandler] A declared custom verb has no evaluator; refusing '
				.'{"action":"sign","declaredBy":"zaken-app","schemaId":41,"userId":"ana"}',
			],
			actual: $this->lines
		);
	}//end testTheCustomVerbEventNamesTheSessionCaller()

	/**
	 * An enforced deny is decided before the caller is resolved, so it sees no user id
	 * and resolves the session itself.
	 *
	 * The private scope and the deny pass take a null user id for a session caller and
	 * resolve it on their own. Resolving the caller first (reordering the `??` chain in
	 * evaluatePermission) would change these traces.
	 *
	 * @return void
	 */
	public function testTheEnforcedDenySeesNoCallerYet(): void {
		$case = [
			'auth'       => ['read' => ['behandelaars'], 'deny' => ['read' => [['user' => 'ana']]]],
			'user'       => ['id' => 'ana', 'groups' => ['behandelaars']],
			'viaSession' => true,
		];

		$this->assertFalse(condition: $this->evaluate(case: $case));
		$this->assertSame(expected: [['session.getUser' => 'ana'], ['userManager.get' => 'ana']], actual: $this->trace);
		$this->assertSame(
			expected: [
				'info: [PermissionHandler] Action denied by an explicit deny rule '
				.'{"schemaId":41,"action":"read","userId":null,"principal":"user:ana","rule":{"user":"ana"}}',
			],
			actual: $this->lines
		);
	}//end testTheEnforcedDenySeesNoCallerYet()

	/**
	 * A staged deny resolves the session on its own, and the caller is resolved from the session afterwards.
	 *
	 * @return void
	 */
	public function testTheStagedDenySeesNoCallerYet(): void {
		$case = [
			'auth'       => ['read' => ['x'], 'deny' => ['read' => ['behandelaars']]],
			'user'       => ['id' => 'ana', 'groups' => ['behandelaars']],
			'viaSession' => true,
			'denyMode'   => DenyEnforcementMode::MODE_STAGING,
		];

		$this->assertFalse(condition: $this->evaluate(case: $case));
		$this->assertSame(
			expected: [
				['session.getUser' => 'ana'],
				['userManager.get' => 'ana'],
				['session.getUser' => 'ana'],
				['session.getUser' => 'ana'],
				['userManager.get' => 'ana'],
			],
			actual: $this->trace
		);
		$this->assertCount(expectedCount: 2, haystack: $this->lines);
		$this->assertStringStartsWith(prefix: 'warning: [DenyEnforcementMode] A deny rule would refuse this action; staging', string: $this->lines[0]);
	}//end testTheStagedDenySeesNoCallerYet()

	/**
	 * The private scope answers before the caller is resolved, so the session is read once.
	 *
	 * @return void
	 */
	public function testThePrivateScopeAnswersBeforeTheCallerIsResolved(): void {
		$case = [
			'auth'       => ['read' => ['behandelaars']],
			'user'       => ['id' => 'bea', 'groups' => []],
			'viaSession' => true,
			'object'     => ['owner' => 'bea', 'authorization' => ['scope' => ObjectScopeResolver::SCOPE_PRIVATE]],
		];

		$this->assertTrue(condition: $this->evaluate(case: $case));
		$this->assertSame(expected: [['session.getUser' => 'bea'], ['userManager.get' => 'bea']], actual: $this->trace);
	}//end testThePrivateScopeAnswersBeforeTheCallerIsResolved()

	/**
	 * Group lists with pseudo-groups, an empty id, duplicates and string keys.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool, 2: list<string>}>
	 */
	public static function unusualGroupLists(): array {
		return [
			'pseudo-groups, an empty id and duplicates are refused' => [
				['auth' => ['read' => ['niemand']], 'user' => ['id' => 'ana', 'groups' => ['authenticated', '', 'public', 'x', 'x']], 'derived' => ['x', 'y']],
				false,
				[
					self::REFUSED.'{"schemaId":41,"objectUuid":null,"action":"read","userId":"ana",'
					.'"principals":["authenticated","","public","x","y"],"rule":["niemand"],"reason":"the rule for this verb does not name this caller"}',
				],
			],
			'membership of public grants without inheritance' => [
				['auth' => ['read' => ['public'], 'inheritFromPublic' => false], 'user' => ['id' => 'ana', 'groups' => ['public']]],
				true,
				[],
			],
			'string keys still grant'         => [
				['auth' => ['read' => ['b']], 'user' => ['id' => 'ana', 'groups' => ['k' => 'a', 'l' => 'b']]],
				true,
				[],
			],
			'string keys are logged as given' => [
				['auth' => ['read' => ['z']], 'user' => ['id' => 'ana', 'groups' => ['k' => 'a', 'l' => 'b']]],
				false,
				[
					self::REFUSED.'{"schemaId":41,"objectUuid":null,"action":"read","userId":"ana",'
					.'"principals":{"k":"a","l":"b"},"rule":["z"],"reason":"the rule for this verb does not name this caller"}',
				],
			],
		];
	}//end unusualGroupLists()

	/**
	 * Unusual group lists give the recorded verdict and log.
	 *
	 * @param array<string, mixed> $case    The case
	 * @param bool                 $verdict The expected verdict
	 * @param list<string>         $lines   The expected log lines
	 *
	 * @return void
	 */
	#[DataProvider('unusualGroupLists')]
	public function testUnusualGroupListsGiveTheRecordedVerdict(array $case, bool $verdict, array $lines): void {
		$this->assertSame(expected: $verdict, actual: $this->evaluate(case: $case));
		$this->assertSame(expected: $lines, actual: $this->lines);
	}//end testUnusualGroupListsGiveTheRecordedVerdict()

	/**
	 * String-keyed groups reach the custom-verb event unchanged.
	 *
	 * @return void
	 */
	public function testStringKeyedGroupsReachTheEventUnchanged(): void {
		$case = ['auth' => [], 'user' => ['id' => 'ana', 'groups' => ['k' => 'a']], 'action' => 'sign', 'vote' => 'record'];

		$this->assertTrue(condition: $this->evaluate(case: $case));
		$this->assertSame(expected: [['userManager.get' => 'ana'], ['event' => ['sign', 'ana', ['k' => 'a'], null]]], actual: $this->trace);
	}//end testStringKeyedGroupsReachTheEventUnchanged()

	/**
	 * A listener that throws an Error is not swallowed.
	 *
	 * @return void
	 */
	public function testAListenerErrorPropagates(): void {
		$this->expectException(exception: Error::class);
		$this->expectExceptionMessage(message: 'listener error');

		$this->evaluate(
			case: ['auth' => ['sign' => ['behandelaars']], 'user' => ['id' => 'ana', 'groups' => ['behandelaars']], 'action' => 'sign', 'vote' => 'error']
		);
	}//end testAListenerErrorPropagates()

	/**
	 * A failing user manager is not swallowed.
	 *
	 * @return void
	 */
	public function testAUserManagerFailurePropagates(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'user manager down');

		$this->evaluate(case: ['auth' => ['read' => ['x']], 'user' => ['id' => 'ana', 'groups' => ['behandelaars']], 'umThrows' => true]);
	}//end testAUserManagerFailurePropagates()

	/**
	 * Run evaluatePermission() for one case, recording the trace and the log.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return bool The verdict
	 */
	private function evaluate(array $case): bool {
		$this->trace = [];
		$this->lines = [];

		$schema = new Schema();
		$schema->setId(41);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($case['auth']);

		$object = null;
		if (isset($case['object']) === true) {
			$object = new ObjectEntity();
			$object->setUuid(self::UUID);
			$object->setObject($case['object']['data'] ?? ['title' => 'een zaak']);
			$object->setOrganisation($case['object']['organisation'] ?? null);
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

		$method = new ReflectionMethod(PermissionHandler::class, 'evaluatePermission');

		return $method->invoke($this->handler(case: $case), $schema, ($case['action'] ?? 'read'), $userId, $object?->getOwner(), $object);
	}//end evaluate()

	/**
	 * A handler wired the way the case says.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return PermissionHandler
	 */
	private function handler(array $case): PermissionHandler {
		$lines  = &$this->lines;
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * Keep a reference to the test's log lines.
			 *
			 * @param list<string> $lines The lines
			 */
			public function __construct(private array &$lines) {
			}//end __construct()

			/**
			 * Record a log line without file and line.
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

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturn($case['denyMode'] ?? DenyEnforcementMode::MODE_ENFORCING);

		$derived = ($case['derived'] ?? []);

		return new PermissionHandler(
			userSession: $this->userSession(case: $case),
			userManager: $this->userManager(case: $case),
			groupManager: $this->groupManager(case: $case),
			schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
			objectEntityMapper: $this->createMock(originalClassName: MagicMapper::class),
			conditionMatcher: $this->conditionMatcher(answer: (($case['condFalse'] ?? false) === false)),
			appConfig: $appConfig,
			logger: $logger,
			container: $this->container(),
			eventDispatcher: $this->eventDispatcher(vote: ($case['vote'] ?? null)),
			objectScopeResolver: new ObjectScopeResolver(),
			denyResolver: new DenyResolver(matcher: new DenyEntryMatcher()),
			denyEnforcementMode: new DenyEnforcementMode(appConfig: $appConfig, logger: $logger),
			permissionCatalogue: $this->catalogue(declared: ($case['declared'] ?? [])),
			derivedGrantStore: $this->derivedStore(derived: $derived),
			derivedGrantResolver: $this->derivedResolver(derived: $derived)
		);
	}//end handler()

	/**
	 * A matcher that records what it is asked and answers the same each time.
	 *
	 * @param bool $answer The answer
	 *
	 * @return ConditionMatcher
	 */
	private function conditionMatcher(bool $answer): ConditionMatcher {
		$matcher = $this->createMock(originalClassName: ConditionMatcher::class);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			function (array $object, array $match) use ($answer): bool {
				$this->trace[] = ['cond' => [$object, $match]];
				return $answer;
			}
		);

		return $matcher;
	}//end conditionMatcher()

	/**
	 * A session holding the case's user, or nobody.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return IUserSession
	 */
	private function userSession(array $case): IUserSession {
		$user = null;
		if (is_array($case['user']) === true) {
			$user = $this->user(uid: $case['user']['id']);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($user): ?IUser {
				if ($user !== null) {
					$this->trace[] = ['session.getUser' => $user->getUID()];
				}

				return $user;
			}
		);

		return $session;
	}//end userSession()

	/**
	 * A user manager that knows the case's user, or fails when the case says so.
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

		$fails   = ($case['umThrows'] ?? false);
		$manager = $this->createMock(originalClassName: IUserManager::class);
		$manager->method('get')->willReturnCallback(
			function (string $uid) use ($known, $fails): ?IUser {
				$this->trace[] = ['userManager.get' => $uid];
				if ($fails === true) {
					throw new RuntimeException('user manager down');
				}

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
	 * A container whose register mapper finds no register.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(null);

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
	 * A dispatcher whose listener records the event and stays silent or throws an Error; or none.
	 *
	 * @param string|null $vote `record`, `error` or null for no dispatcher
	 *
	 * @return IEventDispatcher|null
	 */
	private function eventDispatcher(?string $vote): ?IEventDispatcher {
		if ($vote === null) {
			return null;
		}

		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event) use ($vote): void {
				if ($event instanceof CustomScopeEvaluatingEvent === false) {
					return;
				}

				$this->trace[] = ['event' => [$event->getAction(), $event->getUserId(), $event->getUserGroups(), $event->getObject()?->getUuid()]];
				if ($vote === 'error') {
					throw new Error('listener error');
				}
			}
		);

		return $dispatcher;
	}//end eventDispatcher()

	/**
	 * A catalogue declaring the given custom verbs for another app.
	 *
	 * @param list<string> $declared The declared verbs
	 *
	 * @return PermissionCatalogue
	 */
	private function catalogue(array $declared): PermissionCatalogue {
		$catalogue = $this->createMock(originalClassName: PermissionCatalogue::class);
		$catalogue->method('declarationFor')->willReturnCallback(
			static function (string $verb) use ($declared): ?array {
				if (in_array($verb, $declared, true) === false) {
					return null;
				}

				return ['canonical' => false, 'app' => 'zaken-app'];
			}
		);

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
		$grants = [];
		if ($derived !== []) {
			$grants = [['group' => 'derived']];
		}

		$store->method('grantsFor')->willReturn($grants);

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

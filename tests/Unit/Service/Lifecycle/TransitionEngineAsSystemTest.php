<?php

/**
 * OpenRegister TransitionEngine as-system tests
 *
 * An app whose own guard has already approved a caller (learniq's course
 * evaluation answer, after it checked the learner's open invitation) has to be
 * able to run a lifecycle transition on an object the caller holds no right on.
 * Until this change the only way was to grant every signed-in user read and
 * update on the draft rows, which is the access hole DECISIONS row 63 closes.
 *
 * Pinned here, all against the real TransitionEngine:
 *
 * - the normal path still refuses a caller without read/update;
 * - `transitionAsSystem()` lets the same caller through, skipping only
 *   OpenRegister's own read and update checks;
 * - the transition's declared `requires` guard still runs (through the real
 *   LifecycleValidationListener) and still sees the real caller, and its
 *   refusal still refuses;
 * - the audit row names the real caller and says the move ran as the system,
 *   for which app;
 * - nothing a client sends can reach the system path: the payload cannot ask
 *   for it and no controller calls it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/transition-as-system/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\LifecycleSubjectNotFoundException;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Listener\LifecycleValidationListener;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 */
class TransitionEngineAsSystemTest extends TestCase {
	private const OBJECT_ID = '00000000-0000-0000-0000-00000000a5e1';

	private const GUARD_TAG = 'learniq.course-evaluation-eligibility';

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private PermissionHandler&MockObject $permission;

	private IUserSession&MockObject $userSession;

	private ContainerInterface&MockObject $guardContainer;

	private LoggerInterface&MockObject $logger;

	private LifecycleActionContext $actions;

	private LifecycleValidationListener $listener;

	/**
	 * What the guard was asked, per call: [action, userId].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $guardCalls = [];

	/**
	 * What saveObject() saw, per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->permission = $this->createMock(PermissionHandler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->actions = new LifecycleActionContext();

		// The learner: signed in, holding no right on the response schema.
		$learner = $this->createMock(IUser::class);
		$learner->method('getUID')->willReturn('learner-1');
		$learner->method('getDisplayName')->willReturn('Learner One');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($learner);

		$schema = new Schema();
		$schema->setSlug('course-evaluation-response');
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'lifecycle',
					'transitions' => [
						'submit' => ['from' => ['draft'], 'to' => 'submitted', 'requires' => self::GUARD_TAG],
					],
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		// RBAC-filtered find refuses the row, exactly as it does for a learner;
		// only an unfiltered find (the system path) returns it.
		$this->objectService->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
			): ObjectEntity {
				if ($_rbac === true) {
					throw new DoesNotExistException('Object not found in any magic table');
				}

				return $this->draft();
			}
		);
		$this->permission->method('hasPermission')->willReturn(false);

		// The real lifecycle listener, wired as the save path dispatches it,
		// with a real guard registry over a container holding the app's guard.
		$this->guardContainer = $this->createMock(ContainerInterface::class);
		$serverContainer = $this->createMock(IServerContainer::class);
		$serverContainer->method('get')->willThrowException(new RuntimeException('not found'));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		$dialect = new ConditionDialect(
			ast: new CalculationEvaluator(placeholders: new PlaceholderResolver(userSession: $this->createMock(IUserSession::class)))
		);
		$this->listener = new LifecycleValidationListener(
			$this->schemaMapper,
			new LifecycleGuardRegistry($this->guardContainer, $serverContainer, $this->logger),
			$this->userSession,
			$this->permission,
			$this->logger,
			new LifecycleConditionEvaluator($this->userSession, $groupManager, $l10n, $this->logger, $dialect),
			new LifecycleTransitionResolver($this->actions),
			new ConditionTracer(dialect: $dialect),
			$this->createMock(RuleRunRecorder::class)
		);

		// saveObject() dispatches ObjectUpdatingEvent to the real listener and
		// refuses the write when it stops the event, as MagicMapper does.
		$this->objectService->method('saveObject')->willReturnCallback(
			function (
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?IUser $currentUser = null,
			): ObjectEntity {
				$this->saves[] = [
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
					'silent' => $silent,
					'currentUser' => $currentUser,
					'systemApp' => $this->actions->systemAppFor(self::OBJECT_ID),
					'declared' => $this->actions->declaredFor(self::OBJECT_ID),
				];
				$new = $this->draft();
				$new->setObject($object);
				$event = new ObjectUpdatingEvent($new, $this->draft());
				$this->listener->handle($event);
				if ($event->isPropagationStopped() === true) {
					throw new RuntimeException('Refused: ' . json_encode($event->getErrors()));
				}

				return $new;
			}
		);
	}//end setUp()

	/**
	 * The draft row the learniq answer service saved, as the system, with no owner.
	 */
	private function draft(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid(self::OBJECT_ID);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['lifecycle' => 'draft', 'campaignId' => 'c-1', 'score' => 4]);
		return $object;
	}//end draft()

	/**
	 * Register the app's guard with the given verdict.
	 */
	private function guardSays(GuardResult $verdict): void {
		$guard = $this->createMock(LifecycleGuardInterface::class);
		$guard->method('check')->willReturnCallback(
			function (array $data, string $action, string $userId) use ($verdict): GuardResult {
				$this->guardCalls[] = [$action, $userId];
				return $verdict;
			}
		);
		$this->guardContainer->method('get')->with(self::GUARD_TAG)->willReturn($guard);
	}//end guardSays()

	/**
	 * Build the real engine around the doubled collaborators.
	 */
	private function engine(): TransitionEngine {
		return new TransitionEngine(
			$this->objectService,
			$this->schemaMapper,
			$this->createMock(IEventDispatcher::class),
			$this->userSession,
			$this->permission,
			$this->createMock(RegisterMapper::class),
			$this->createMock(IAppConfig::class),
			$this->logger,
			new LifecycleWriteBoundary($this->actions),
			$this->createMock(LifecycleActionProviderRegistry::class)
		);
	}//end engine()

	/**
	 * Control: the caller without read/update is refused through the normal path.
	 */
	public function testCallerWithoutRightsIsRefusedThroughTheNormalPath(): void {
		$this->guardSays(GuardResult::allow());

		try {
			$this->engine()->transition(self::OBJECT_ID, 'submit');
			$this->fail('The normal path let a caller without rights transition the object.');
		} catch (LifecycleSubjectNotFoundException $e) {
			$this->assertSame([], $this->saves, 'Nothing may be written on a refused transition.');
		}
	}//end testCallerWithoutRightsIsRefusedThroughTheNormalPath()

	/**
	 * The same caller succeeds through the system entry point, and only OpenRegister's own checks are skipped.
	 */
	public function testSameCallerSucceedsThroughTheSystemEntryPoint(): void {
		$this->guardSays(GuardResult::allow());
		$this->permission->expects($this->never())->method('hasPermission');

		$saved = $this->engine()->transitionAsSystem(self::OBJECT_ID, 'submit', app: 'learniq');

		$this->assertSame('submitted', $saved->getObject()['lifecycle']);
		$this->assertCount(1, $this->saves);
		$this->assertFalse($this->saves[0]['_rbac'], 'The save must skip RBAC on the system path.');
		$this->assertFalse($this->saves[0]['_multitenancy'], 'The save must skip the organisation filter on the system path.');
		$this->assertSame('learner-1', $this->saves[0]['currentUser']?->getUID(), 'The save still carries the real caller.');
		$this->assertSame('submit', $this->saves[0]['declared'], 'The listeners still judge the named action.');
	}//end testSameCallerSucceedsThroughTheSystemEntryPoint()

	/**
	 * The declared guard still runs on the system path, and sees the real caller.
	 */
	public function testDeclaredGuardStillRunsWithTheRealCaller(): void {
		$this->guardSays(GuardResult::allow());

		$this->engine()->transitionAsSystem(self::OBJECT_ID, 'submit', app: 'learniq');

		$this->assertSame([['submit', 'learner-1']], $this->guardCalls);
	}//end testDeclaredGuardStillRunsWithTheRealCaller()

	/**
	 * A guard that refuses still refuses on the system path.
	 */
	public function testGuardRefusalStillRefusesOnTheSystemPath(): void {
		$this->guardSays(GuardResult::deny('No open invitation for this campaign.'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('lifecycle-guard-denied');

		$this->engine()->transitionAsSystem(self::OBJECT_ID, 'submit', app: 'learniq');
	}//end testGuardRefusalStillRefusesOnTheSystemPath()

	/**
	 * The write is marked as system for the app only while it runs, and the mark is released afterwards.
	 */
	public function testSystemMarkIsScopedToTheWrite(): void {
		$this->guardSays(GuardResult::allow());

		$this->engine()->transitionAsSystem(self::OBJECT_ID, 'submit', app: 'learniq');

		$this->assertSame('learniq', $this->saves[0]['systemApp']);
		$this->assertNull($this->actions->systemAppFor(self::OBJECT_ID), 'The mark must not outlive the transition.');
	}//end testSystemMarkIsScopedToTheWrite()

	/**
	 * The mark is released when the guard refuses, too.
	 */
	public function testSystemMarkIsReleasedOnRefusal(): void {
		$this->guardSays(GuardResult::deny('No.'));

		try {
			$this->engine()->transitionAsSystem(self::OBJECT_ID, 'submit', app: 'learniq');
		} catch (RuntimeException $e) {
			// Expected.
		}

		$this->assertNull($this->actions->systemAppFor(self::OBJECT_ID));
	}//end testSystemMarkIsReleasedOnRefusal()

	/**
	 * The system path is logged with the app, the real caller, the object and the action.
	 */
	public function testSystemPathIsLoggedWithAppAndCaller(): void {
		$this->guardSays(GuardResult::allow());
		$this->logger->expects($this->once())->method('info')->with(
			$this->stringContains('as the system'),
			$this->callback(
				static fn (array $context): bool => ($context['app'] ?? null) === 'openregister'
					&& ($context['onBehalfOfApp'] ?? null) === 'learniq'
					&& ($context['caller'] ?? null) === 'learner-1'
					&& ($context['uuid'] ?? null) === self::OBJECT_ID
					&& ($context['action'] ?? null) === 'submit'
			)
		);

		$this->engine()->transitionAsSystem(self::OBJECT_ID, 'submit', app: 'learniq');
	}//end testSystemPathIsLoggedWithAppAndCaller()

	/**
	 * An app must name itself: an anonymous system transition is refused before anything is read.
	 */
	public function testAnEmptyAppIsRefused(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);

		$this->engine()->transitionAsSystem(self::OBJECT_ID, 'submit', app: '  ');
	}//end testAnEmptyAppIsRefused()

	/**
	 * A client cannot ask for the system path through the transition payload.
	 */
	public function testThePayloadCannotAskForTheSystemPath(): void {
		$this->guardSays(GuardResult::allow());

		$this->expectException(LifecycleSubjectNotFoundException::class);

		$this->engine()->transition(
			self::OBJECT_ID,
			'submit',
			['_rbac' => false, 'asSystem' => 'learniq', 'app' => 'learniq', '_multitenancy' => false]
		);
	}//end testThePayloadCannotAskForTheSystemPath()

	/**
	 * No controller reaches the system path: the HTTP API cannot trigger it.
	 *
	 * Structural, like SystemOperationContextBoundaryTest: adding a call site
	 * under lib/Controller has to be a deliberate act with a visible diff.
	 */
	public function testNoControllerCallsTheSystemPath(): void {
		$root = dirname(__DIR__, 4) . '/lib';
		$callers = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (preg_match('/->transitionAsSystem\s*\(/', $source) === 1) {
				$callers[] = substr($file->getPathname(), strlen($root) + 1);
			}
		}

		$controllers = array_values(array_filter($callers, static fn (string $path): bool => str_starts_with($path, 'Controller/')));
		$this->assertSame([], $controllers, 'A controller calls transitionAsSystem().');
		$this->assertSame([], $callers, 'OpenRegister itself has no caller of transitionAsSystem(); it is for apps.');
	}//end testNoControllerCallsTheSystemPath()

	/**
	 * The audit row of a system transition names the real caller and the app it ran as the system for.
	 */
	public function testAuditRowNamesTheRealCallerAndTheSystemApp(): void {
		$this->actions->enterSystem(uuid: self::OBJECT_ID, app: 'learniq');

		try {
			$row = $this->auditMapper()->buildAuditTrail(old: $this->draft(), new: $this->submitted());
		} finally {
			$this->actions->leaveSystem(uuid: self::OBJECT_ID);
		}

		$this->assertSame('learner-1', $row->getUser());
		$this->assertSame(['app' => 'learniq'], $row->getChanged()['transitionAsSystem'] ?? null);
	}//end testAuditRowNamesTheRealCallerAndTheSystemApp()

	/**
	 * Control: an ordinary write carries no system mark.
	 */
	public function testOrdinaryAuditRowCarriesNoSystemMark(): void {
		$row = $this->auditMapper()->buildAuditTrail(old: $this->draft(), new: $this->submitted());

		$this->assertArrayNotHasKey('transitionAsSystem', $row->getChanged());
	}//end testOrdinaryAuditRowCarriesNoSystemMark()

	/**
	 * The draft after the move.
	 */
	private function submitted(): ObjectEntity {
		$object = $this->draft();
		$object->setObject(['lifecycle' => 'submitted', 'campaignId' => 'c-1', 'score' => 4]);
		return $object;
	}//end submitted()

	/**
	 * The real audit mapper, whose container resolves the shared action context.
	 */
	private function auditMapper(): AuditTrailMapper {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class): object {
				if ($class === LifecycleActionContext::class) {
					return $this->actions;
				}

				throw new RuntimeException('not registered: ' . $class);
			}
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		return new AuditTrailMapper(
			$this->createMock(IDBConnection::class),
			$container,
			$this->userSession,
			$request,
			$this->createMock(LoggerInterface::class)
		);
	}//end auditMapper()
}//end class

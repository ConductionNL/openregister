<?php

/**
 * Deciding one automatic move, and either firing it or queuing it.
 *
 * The two things worth pinning down here are the ones that fail quietly if
 * they break: a refusal must never reach the caller whose write triggered the
 * move, and an `async` move must be queued with the version it was decided
 * against so a newer write can invalidate it.
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
 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use DateTime;
use OCA\OpenRegister\BackgroundJob\AutoTransitionJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionCandidate;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionDecision;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionQueue;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelector;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Decide, fire, queue.
 */
class AutoTransitionRunnerTest extends TestCase {

	private const AUTO_WHEN = ['!!' => ['var' => 'object.motivering']];

	private ContainerInterface&MockObject $container;

	private SchemaMapper&MockObject $schemaMapper;

	private ListenerDeferralService&MockObject $deferral;

	private DeferredEntryObjectResolver&MockObject $resolver;

	private TransitionEngine&MockObject $engine;

	private LoggerInterface&MockObject $logger;

	private AutoTransitionRunner $runner;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->deferral = $this->createMock(ListenerDeferralService::class);
		$this->resolver = $this->createMock(DeferredEntryObjectResolver::class);
		$this->engine = $this->createMock(TransitionEngine::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === DeferredEntryObjectResolver::class) {
					return $this->resolver;
				}

				if ($id === TransitionEngine::class) {
					return $this->engine;
				}

				throw new RuntimeException('unexpected service ' . $id);
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');

		$this->runner = new AutoTransitionRunner(
			$this->container,
			$this->schemaMapper,
			new AutoTransitionSelector(
				new LifecycleConditionEvaluator(
					$this->createMock(IUserSession::class),
					$groupManager,
					$l10n,
					$this->logger
				),
				$this->logger
			),
			// A REAL queue around the mocked deferral service, not a mocked queue:
			// the dedupe key, the entry shape and the kill-switch rule are the
			// behaviour these tests assert, and a double would assert nothing.
			new AutoTransitionQueue($this->deferral, $this->logger),
			$this->logger
		);
	}//end setUp()

	/**
	 * Make the mapper answer with a schema declaring `beslissen`.
	 *
	 * @param string|null $executionMode The transition's declared mode, or null for none.
	 *
	 * @return void
	 */
	private function schemaWithAutoWhen(?string $executionMode = null): void {
		$transition = ['from' => ['open'], 'to' => 'besloten', 'autoWhen' => self::AUTO_WHEN];
		if ($executionMode !== null) {
			$transition['executionMode'] = $executionMode;
		}

		$schema = new Schema();
		$schema->setSlug('bezwaar');
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'initial' => 'open',
					'transitions' => ['beslissen' => $transition],
				],
			]
		);

		$this->schemaMapper->method('find')->willReturn($schema);
	}//end schemaWithAutoWhen()

	/**
	 * Make the resolver answer with a stored object.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return ObjectEntity
	 */
	private function storedObject(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setRegister('1');
		$entity->setSchema('2');
		$entity->setObject($data);
		$entity->setVersion('3');
		$entity->setUpdated(new DateTime('2026-09-11T09:00:00+00:00'));

		$this->resolver->method('resolve')->willReturn($entity);

		return $entity;
	}//end storedObject()

	/**
	 * A decision for `beslissen`, in the given mode.
	 *
	 * @param string $mode The execution mode.
	 *
	 * @return AutoTransitionDecision
	 */
	private function decision(string $mode = 'sync'): AutoTransitionDecision {
		return new AutoTransitionDecision(
			uuid: 'obj-1',
			register: '1',
			schema: '2',
			schemaSlug: 'bezwaar',
			candidate: new AutoTransitionCandidate('beslissen', 'open', 'besloten', $mode),
			version: '3',
			updated: '2026-09-11T09:00:00+00:00'
		);
	}//end decision()

	/**
	 * @return void
	 */
	public function testAnUnresolvableObjectDecidesNothing(): void {
		$this->resolver->method('resolve')->willReturn(null);

		$this->assertNull($this->runner->decide(uuid: 'obj-1', register: '1', schema: '2', previous: []));
	}//end testAnUnresolvableObjectDecidesNothing()

	/**
	 * @return void
	 */
	public function testASchemaWithoutALifecycleDecidesNothing(): void {
		$this->storedObject(['status' => 'open', 'motivering' => 'ja']);
		$this->schemaMapper->method('find')->willReturn(new Schema());

		$this->assertNull($this->runner->decide(uuid: 'obj-1', register: '1', schema: '2', previous: []));
	}//end testASchemaWithoutALifecycleDecidesNothing()

	/**
	 * @return void
	 */
	public function testTheDecisionCarriesTheStoredVersionAndTimestamp(): void {
		// This is what makes a queued move safe: the job applies it only while
		// these still match, because a newer write made its own decision.
		$this->storedObject(['status' => 'open', 'motivering' => 'Ongegrond.']);
		$this->schemaWithAutoWhen();

		$decision = $this->runner->decide(uuid: 'obj-1', register: '1', schema: '2', previous: []);

		$this->assertNotNull($decision);
		$this->assertSame('beslissen', $decision->candidate->action);
		$this->assertSame('3', $decision->version);
		$this->assertSame('2026-09-11T09:00:00+00:00', $decision->updated);
		$this->assertSame('bezwaar', $decision->schemaSlug);
	}//end testTheDecisionCarriesTheStoredVersionAndTimestamp()

	/**
	 * @return void
	 */
	public function testTheDecisionIsMadeAgainstTheObjectAsStored(): void {
		// Not against the event payload: computed fields and defaults are only
		// present once the write has landed.
		$this->storedObject(['status' => 'open', 'motivering' => '']);
		$this->schemaWithAutoWhen();

		$this->assertNull($this->runner->decide(uuid: 'obj-1', register: '1', schema: '2', previous: []));
	}//end testTheDecisionIsMadeAgainstTheObjectAsStored()

	/**
	 * @return void
	 */
	public function testASyncMoveIsAppliedThroughTheEngine(): void {
		$moved = new ObjectEntity();
		$moved->setUuid('obj-1');

		$this->engine->expects($this->once())
			->method('transition')
			->with(objectId: 'obj-1', action: 'beslissen')
			->willReturn($moved);
		$this->deferral->expects($this->never())->method('defer');

		$this->assertSame(
			$moved,
			$this->runner->fire(decision: $this->decision(), lineage: [], queueOnly: false)
		);
	}//end testASyncMoveIsAppliedThroughTheEngine()

	/**
	 * @return void
	 */
	public function testARefusalIsLoggedWithItsCodeAndDoesNotPropagate(): void {
		// 🔴 The triggering write has already succeeded. A refusal here is not
		// the caller's fault and must not become the caller's error.
		$this->engine->method('transition')->willThrowException(
			new HookStoppedException(
				message: 'Transition denied by guard.',
				errors: ['code' => 'lifecycle-guard-denied', 'action' => 'beslissen']
			)
		);

		$this->logger->expects($this->atLeastOnce())
			->method('warning')
			->with(
				$this->stringContains('automatic transition was refused'),
				$this->callback(
					static fn (array $context): bool => $context['refusalCode'] === 'lifecycle-guard-denied'
						&& $context['action'] === 'beslissen'
				)
			);

		$this->assertNull($this->runner->fire(decision: $this->decision(), lineage: [], queueOnly: false));
	}//end testARefusalIsLoggedWithItsCodeAndDoesNotPropagate()

	/**
	 * @return void
	 */
	public function testAnArbitraryThrowableDoesNotPropagateEither(): void {
		$this->engine->method('transition')->willThrowException(new RuntimeException('anything at all'));

		$this->assertNull($this->runner->fire(decision: $this->decision(), lineage: [], queueOnly: false));
	}//end testAnArbitraryThrowableDoesNotPropagateEither()

	/**
	 * @return void
	 */
	public function testAnAsyncMoveIsQueuedWithItsLineageAndDedupeKey(): void {
		$this->deferral->method('isDeferralEnabled')->willReturn(true);
		$this->engine->expects($this->never())->method('transition');

		$this->deferral->expects($this->once())
			->method('defer')
			->with(
				jobClass: AutoTransitionJob::class,
				entry: [
					'uuid' => 'obj-1',
					'register' => '1',
					'schema' => '2',
					'action' => 'beslissen',
					'to' => 'besloten',
					'version' => '3',
					'updated' => '2026-09-11T09:00:00+00:00',
					'moves' => 2,
					'visited' => ['open', 'besloten'],
				],
				chunkSize: AutoTransitionQueue::CHUNK_SIZE,
				// uuid PLUS version: deduping on the uuid alone would keep a
				// stale decision and drop the current one.
				dedupeKey: 'obj-1|3'
			);

		$this->assertNull(
			$this->runner->fire(
				decision: $this->decision(mode: 'async'),
				lineage: ['moves' => 2, 'visited' => ['open' => true, 'besloten' => true], 'applied' => []],
				queueOnly: false
			)
		);
	}//end testAnAsyncMoveIsQueuedWithItsLineageAndDedupeKey()

	/**
	 * @return void
	 */
	public function testTheKillSwitchAppliesAnAsyncMoveInline(): void {
		$this->deferral->method('isDeferralEnabled')->willReturn(false);
		$this->deferral->expects($this->never())->method('defer');

		$moved = new ObjectEntity();
		$this->engine->expects($this->once())->method('transition')->willReturn($moved);

		$this->assertSame(
			$moved,
			$this->runner->fire(decision: $this->decision(mode: 'async'), lineage: [], queueOnly: false)
		);
	}//end testTheKillSwitchAppliesAnAsyncMoveInline()

	/**
	 * @return void
	 */
	public function testAMoveDecidedOutsideABoundaryIsQueuedEvenWhenSync(): void {
		// There is no point after a bulk save's write and before a response at
		// which a sync move could run, so the declared mode does not apply.
		$this->engine->expects($this->never())->method('transition');
		$this->deferral->expects($this->once())->method('defer');

		$this->assertNull(
			$this->runner->fire(decision: $this->decision(mode: 'sync'), lineage: [], queueOnly: true)
		);
	}//end testAMoveDecidedOutsideABoundaryIsQueuedEvenWhenSync()

	/**
	 * @return void
	 */
	public function testTheKillSwitchDoesNotPullAnOutsideMoveInline(): void {
		$this->deferral->method('isDeferralEnabled')->willReturn(false);
		$this->engine->expects($this->never())->method('transition');
		$this->deferral->expects($this->once())->method('defer');

		$this->assertNull(
			$this->runner->fire(decision: $this->decision(mode: 'async'), lineage: [], queueOnly: true)
		);
	}//end testTheKillSwitchDoesNotPullAnOutsideMoveInline()
}//end class

<?php

/**
 * OpenRegister TransitionEngine provider-mode WRITE tests
 *
 * The other half of provider mode: the app that answered "these are the
 * moves" is also the one that takes them. Asserts that the provider is asked
 * rather than the lifecycle field mutated, that OpenRegister saves nothing
 * itself, that the object is re-read afterwards, that a refusal and a
 * breakage stay different throws, that static transitions still win, and
 * that a schema declaring no provider never reaches this path at all.
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
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Lifecycle\LifecycleActionProviderInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 */
class TransitionEngineProviderWriteTest extends TestCase {
	private const CASE = '00000000-0000-0000-0000-0000000000aa';

	private const TAG = 'OCA\\Dossiq\\Lifecycle\\CaseActionProvider';

	private const ACTION = 'lc-start';

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private LifecycleActionProviderRegistry&MockObject $registry;

	private IEventDispatcher&MockObject $dispatcher;

	private LoggerInterface&MockObject $logger;

	private LifecycleActionContext $actions;

	private TransitionEngine $engine;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registry = $this->createMock(LifecycleActionProviderRegistry::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->actions = new LifecycleActionContext();

		$this->engine = new TransitionEngine(
			$this->objectService,
			$this->schemaMapper,
			$this->dispatcher,
			$this->createMock(IUserSession::class),
			$this->permission(),
			$this->createMock(RegisterMapper::class),
			$this->appConfig(),
			$this->logger,
			new LifecycleWriteBoundary($this->actions),
			$this->registry
		);
	}//end setUp()

	/**
	 * A permission handler that says yes, so the RBAC gate is out of the way.
	 */
	private function permission(): PermissionHandler&MockObject {
		$permission = $this->createMock(PermissionHandler::class);
		$permission->method('hasPermission')->willReturn(true);
		return $permission;
	}//end permission()

	/**
	 * App config that answers every read with the caller's default.
	 */
	private function appConfig(): IAppConfig&MockObject {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					return $default;
				}
			);
		return $appConfig;
	}//end appConfig()

	/**
	 * The dossiq-shaped case object: a status the schema knows nothing about.
	 */
	private function caseObject(string $status = 'in-behandeling'): ObjectEntity {
		$case = new ObjectEntity();
		$case->setUuid(self::CASE);
		$case->setSchema('case');
		$case->setRegister('1');
		$case->setObject(['caseType' => 'ct-1', 'status' => $status]);
		return $case;
	}//end caseObject()

	/**
	 * A provider-mode annotation, the shape a delegating schema declares.
	 *
	 * @return array<string, mixed>
	 */
	private function providerAnnotation(): array {
		return [
			'field' => 'status',
			'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
			'provider' => self::TAG,
		];
	}//end providerAnnotation()

	/**
	 * Serve the annotation, and answer `find()` with the before-state first
	 * and the after-state on the engine's re-read.
	 *
	 * @param array<string, mixed> $annotation The lifecycle block to serve.
	 * @param ObjectEntity|null $after What the re-read answers; the before-state when null.
	 */
	private function wire(ObjectEntity $before, array $annotation, ?ObjectEntity $after = null): void {
		$answers = [$before, ($after ?? $before)];
		$this->objectService->method('find')
			->willReturnCallback(
				static function () use (&$answers) {
					return (array_shift($answers) ?? null);
				}
			);

		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-lifecycle' => $annotation]);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end wire()

	/**
	 * Register a provider under the annotation's tag.
	 */
	private function provider(): LifecycleActionProviderInterface&MockObject {
		$provider = $this->createMock(LifecycleActionProviderInterface::class);
		$this->registry->method('resolve')->with(self::TAG)->willReturn($provider);
		return $provider;
	}//end provider()

	/**
	 * THE DEFECT, pinned. A provider-mode schema declares no `transitions`,
	 * so before this branch existed the write path fell through to "Transition
	 * ... is not declared on this schema." and the object never moved. The
	 * provider is asked instead, with the object payload, the caller, the
	 * action and the payload it was given.
	 */
	public function testTheProviderIsAskedToPerformTheMove(): void {
		$before = $this->caseObject();
		$after = $this->caseObject('afgehandeld');
		$this->wire($before, $this->providerAnnotation(), $after);

		$provider = $this->provider();
		$provider->expects($this->once())
			->method('execute')
			->with(
				// `id` is stamped on by ObjectEntity::getObject(), and it is
				// how a provider finds its own record: dossiq's reads
				// `id`/`uuid`/`@self.id` off exactly this payload.
				['caseType' => 'ct-1', 'status' => 'in-behandeling', 'id' => self::CASE],
				'',
				self::ACTION,
				['resultTypeId' => 'rt-1']
			)
			->willReturn(['status' => 'ok']);

		$result = $this->engine->transition(self::CASE, self::ACTION, ['resultTypeId' => 'rt-1']);

		$this->assertSame($after, $result);
	}//end testTheProviderIsAskedToPerformTheMove()

	/**
	 * OPENREGISTER WRITES NOTHING. The provider owns the whole write —
	 * dossiq's takes a version lock, refuses a closing move with no result,
	 * writes the status record and dispatches side effects — so a `saveObject()`
	 * here would be a second, blind write racing the app's own.
	 */
	public function testOpenRegisterDoesNotSaveTheObjectItself(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation(), $this->caseObject('afgehandeld'));
		$this->provider()->method('execute')->willReturn([]);

		$this->objectService->expects($this->never())->method('saveObject');

		$this->engine->transition(self::CASE, self::ACTION);
	}//end testOpenRegisterDoesNotSaveTheObjectItself()

	/**
	 * The transitioned event still fires, and its `to` is read off the object
	 * as it was RE-READ — the stored truth — when the provider's report does
	 * not name one. dossiq's `execute()` answers
	 * `status`/`statusRecord`/`dispatchedActions`/`version`, so this is the
	 * ordinary case rather than the fallback.
	 */
	public function testTransitionedEventUsesTheReReadStateWhenTheReportIsSilent(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation(), $this->caseObject('afgehandeld'));
		$this->provider()->method('execute')->willReturn(['status' => 'ok', 'version' => 4]);

		$this->dispatcher->expects($this->once())
			->method('dispatchTyped')
			->with(
				$this->callback(
					static function ($event): bool {
						return $event instanceof ObjectTransitionedEvent
							&& $event->getAction() === self::ACTION
							&& $event->getFrom() === 'in-behandeling'
							&& $event->getTo() === 'afgehandeld';
					}
				)
			);

		$this->engine->transition(self::CASE, self::ACTION);
	}//end testTransitionedEventUsesTheReReadStateWhenTheReportIsSilent()

	/**
	 * A provider that DOES name its target state in the report has that
	 * answered, which is the one key OpenRegister reads off the array.
	 */
	public function testTransitionedEventPrefersTheReportedTargetState(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation(), $this->caseObject(''));
		$this->provider()->method('execute')->willReturn(['to' => 'afgehandeld']);

		$this->dispatcher->expects($this->once())
			->method('dispatchTyped')
			->with(
				$this->callback(
					static function ($event): bool {
						return $event instanceof ObjectTransitionedEvent
							&& $event->getTo() === 'afgehandeld';
					}
				)
			);

		$this->engine->transition(self::CASE, self::ACTION);
	}//end testTransitionedEventPrefersTheReportedTargetState()

	/**
	 * A REFUSAL STAYS A REFUSAL. The provider says no — a guard refused, the
	 * case already moved, a closing move carries no result — and that reaches
	 * the controller as the plain `RuntimeException` it maps to 422, carrying
	 * the provider's own sentence. Wrapping it would turn "the deadline has
	 * passed" into "the upstream is down".
	 */
	public function testAProviderRefusalSurfacesAsARefusal(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation());
		$this->provider()->method('execute')
			->willThrowException(new RuntimeException('De bezwaartermijn is verstreken.'));

		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		try {
			$this->engine->transition(self::CASE, self::ACTION);
			$this->fail('Expected the provider refusal to propagate.');
		} catch (RuntimeException $e) {
			$this->assertNotInstanceOf(LifecycleProviderException::class, $e);
			$this->assertSame('De bezwaartermijn is verstreken.', $e->getMessage());
		}
	}//end testAProviderRefusalSurfacesAsARefusal()

	/**
	 * A BREAKAGE STAYS A BREAKAGE, first route: the provider declares its own
	 * failure with `LifecycleProviderException`, and it reaches the controller
	 * as that type — 502 — rather than being reclassified as a refused move.
	 */
	public function testADeclaredProviderFailureIsNotReclassifiedAsARefusal(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation());
		$this->provider()->method('execute')
			->willThrowException(new LifecycleProviderException('workflowTemplate is not valid JSON'));

		$this->expectException(LifecycleProviderException::class);
		$this->expectExceptionMessage('workflowTemplate is not valid JSON');

		$this->engine->transition(self::CASE, self::ACTION);
	}//end testADeclaredProviderFailureIsNotReclassifiedAsARefusal()

	/**
	 * A BREAKAGE STAYS A BREAKAGE, second route: an unplanned throw — a
	 * `TypeError`, an `Error` — is wrapped and logged, never reported as a
	 * refusal. A refusal tells a handler the move was considered and declined;
	 * this one was never considered at all.
	 */
	public function testAnUnplannedProviderThrowIsWrappedAsAFailure(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation());
		$this->provider()->method('execute')
			->willThrowException(new \TypeError('Argument #1 must be of type array, null given'));

		$this->logger->expects($this->once())->method('error');

		$this->expectException(LifecycleProviderException::class);
		$this->expectExceptionMessage('could not apply action');

		$this->engine->transition(self::CASE, self::ACTION);
	}//end testAnUnplannedProviderThrowIsWrappedAsAFailure()

	/**
	 * FAIL CLOSED on an unresolvable tag, on the write path too. Drives a REAL
	 * registry against empty containers, so the policy is exercised rather
	 * than restated by a double.
	 */
	public function testAnUnregisteredProviderFailsClosedOnTheWritePath(): void {
		$notFound = new class extends \Exception implements NotFoundExceptionInterface {
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException($notFound);
		$serverContainer = $this->createMock(\OCP\IServerContainer::class);
		$serverContainer->method('get')->willThrowException($notFound);

		$engine = new TransitionEngine(
			$this->objectService,
			$this->schemaMapper,
			$this->dispatcher,
			$this->createMock(IUserSession::class),
			$this->permission(),
			$this->createMock(RegisterMapper::class),
			$this->appConfig(),
			$this->logger,
			new LifecycleWriteBoundary(new LifecycleActionContext()),
			new LifecycleActionProviderRegistry($container, $serverContainer, $this->logger)
		);

		$this->wire($this->caseObject(), $this->providerAnnotation());

		$this->expectException(LifecycleProviderException::class);
		$this->expectExceptionMessage('is not registered');

		$engine->transition(self::CASE, self::ACTION);
	}//end testAnUnregisteredProviderFailsClosedOnTheWritePath()

	/**
	 * An object that is gone after the move leaves OpenRegister nothing
	 * truthful to answer the endpoint with, so it says so as a provider
	 * failure rather than inventing a stale entity.
	 */
	public function testAnObjectThatCannotBeReReadIsAFailure(): void {
		$before = $this->caseObject();
		$answers = [$before, null];
		$this->objectService->method('find')
			->willReturnCallback(
				static function () use (&$answers) {
					return (array_shift($answers) ?? null);
				}
			);
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')
			->willReturn(['x-openregister-lifecycle' => $this->providerAnnotation()]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->provider()->method('execute')->willReturn([]);
		$this->logger->expects($this->once())->method('error');

		$this->expectException(LifecycleProviderException::class);
		$this->expectExceptionMessage('could not be read back');

		$this->engine->transition(self::CASE, self::ACTION);
	}//end testAnObjectThatCannotBeReReadIsAFailure()

	/**
	 * NO WRITE-BOUNDARY DECLARATION, asserted from inside the provider call
	 * rather than inferred afterwards — a declaration that was made and
	 * released would be invisible to a check that ran after `execute()`
	 * returned.
	 *
	 * In provider mode there is no named transition for the lifecycle
	 * listeners to look up: the annotation carries no `transitions` map, so a
	 * declaration would name them something they cannot resolve while claiming
	 * OpenRegister is applying it through its own save pipeline. The
	 * enforcement it supports has moved into the provider, which re-validates
	 * the move itself.
	 */
	public function testNoActionIsDeclaredForTheListenersDuringAProviderWrite(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation(), $this->caseObject('afgehandeld'));

		$seen = 'unset';
		$this->provider()->method('execute')
			->willReturnCallback(
				function () use (&$seen): array {
					$seen = $this->actions->declaredFor(uuid: self::CASE);
					return [];
				}
			);

		$this->engine->transition(self::CASE, self::ACTION);

		$this->assertNull($seen);
	}//end testNoActionIsDeclaredForTheListenersDuringAProviderWrite()

	/**
	 * STATIC STILL WINS, on the write path exactly as on the read path. A
	 * schema that grows a provider tag beside the transitions it already had
	 * keeps applying them, and the provider is not even resolved.
	 */
	public function testStaticTransitionsTakePrecedenceOverTheProvider(): void {
		$this->registry->expects($this->never())->method('resolve');

		$annotation = $this->providerAnnotation();
		$annotation['transitions'] = [
			'goedkeuren' => ['from' => ['in-behandeling'], 'to' => 'afgehandeld'],
		];
		$this->wire($this->caseObject(), $annotation);

		$saved = $this->caseObject('afgehandeld');
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($saved);

		$this->assertSame($saved, $this->engine->transition(self::CASE, 'goedkeuren'));
	}//end testStaticTransitionsTakePrecedenceOverTheProvider()

	/**
	 * A SCHEMA WITH NO PROVIDER IS UNAFFECTED: the branch is entered only on a
	 * non-empty `provider` string, so a static schema still refuses an
	 * undeclared action with the message it always did, and no provider is
	 * resolved on the way there.
	 */
	public function testASchemaWithoutAProviderIsUnaffected(): void {
		$this->registry->expects($this->never())->method('resolve');

		$this->wire(
			$this->caseObject(),
			[
				'field' => 'status',
				'transitions' => ['goedkeuren' => ['from' => ['ontvangen'], 'to' => 'afgehandeld']],
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('is not declared on this schema');

		$this->engine->transition(self::CASE, self::ACTION);
	}//end testASchemaWithoutAProviderIsUnaffected()

	/**
	 * An EMPTY `provider` string declares no provider, so it must not be
	 * resolved and must not swallow the graph mode behind it. Pins the
	 * `trim()` the branch guards on, which is the same guard the read path
	 * uses.
	 */
	public function testAnEmptyProviderStringIsNotAProvider(): void {
		$this->registry->expects($this->never())->method('resolve');

		$annotation = $this->providerAnnotation();
		$annotation['provider'] = '   ';
		$this->wire($this->caseObject(), $annotation);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('is not declared on this schema');

		$this->engine->transition(self::CASE, self::ACTION);
	}//end testAnEmptyProviderStringIsNotAProvider()
}//end class

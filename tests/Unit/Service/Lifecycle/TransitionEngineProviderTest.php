<?php

/**
 * OpenRegister TransitionEngine provider-mode tests
 *
 * Covers the third lifecycle mode: an app service answers available-actions
 * for a schema whose state machine lives in data rather than in the
 * annotation. Asserts what is published, that static transitions still win,
 * that the `inputs` contract is normalised by the same code the other two
 * modes use, that an unresolvable provider fails closed rather than answering
 * with an empty list, and that a `blocked` entry keeps its reason.
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

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 */
class TransitionEngineProviderTest extends TestCase {
	private const CASE = '00000000-0000-0000-0000-0000000000aa';

	private const TAG = 'OCA\\Dossiq\\Lifecycle\\CaseActionProvider';

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private LifecycleActionProviderRegistry&MockObject $registry;

	private LoggerInterface&MockObject $logger;

	private TransitionEngine $engine;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registry = $this->createMock(LifecycleActionProviderRegistry::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$permission = $this->createMock(PermissionHandler::class);
		$permission->method('hasPermission')->willReturn(true);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					return $default;
				}
			);

		$this->engine = new TransitionEngine(
			$this->objectService,
			$this->schemaMapper,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$permission,
			$this->createMock(RegisterMapper::class),
			$appConfig,
			$this->logger,
			new LifecycleWriteBoundary(new LifecycleActionContext()),
			$this->registry
		);
	}//end setUp()

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
	 * @param array<string, mixed> $annotation The lifecycle block to serve.
	 */
	private function wire(ObjectEntity $case, array $annotation): void {
		$this->objectService->method('find')->willReturn($case);
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-lifecycle' => $annotation]);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end wire()

	/**
	 * Register a provider returning the given list under the annotation's tag.
	 *
	 * @param array<int, mixed> $entries Whatever the app answers with.
	 */
	private function provider(array $entries): LifecycleActionProviderInterface&MockObject {
		$provider = $this->createMock(LifecycleActionProviderInterface::class);
		$provider->method('availableActions')->willReturn($entries);
		$this->registry->method('resolve')->with(self::TAG)->willReturn($provider);
		return $provider;
	}//end provider()

	/**
	 * The provider's list is what the endpoint publishes: this is the whole
	 * point of the mode, and it is the case dossiq's stages widget reads.
	 */
	public function testProviderActionsArePublished(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation());
		$this->provider(
			[
				[
					'action' => 'afhandelen',
					'to' => 'afgehandeld',
					'requires' => 'dossiq.case.closeGuard',
					'description' => 'Sluit de zaak af.',
					'label' => 'Afgehandeld',
					'inputs' => [['field' => 'motivering', 'required' => true]],
				],
			]
		);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertCount(1, $actions);
		$this->assertSame('afhandelen', $actions[0]['action']);
		$this->assertSame('afgehandeld', $actions[0]['to']);
		$this->assertSame('dossiq.case.closeGuard', $actions[0]['requires']);
		$this->assertSame('Sluit de zaak af.', $actions[0]['description']);
		$this->assertSame('Afgehandeld', $actions[0]['label']);
		$this->assertSame([['field' => 'motivering', 'required' => true]], $actions[0]['inputs']);
	}//end testProviderActionsArePublished()

	/**
	 * An entry that names no action is skipped, and the rest of the list is
	 * still published — the same policy a malformed static transition spec
	 * gets, rather than failing the whole read.
	 */
	public function testEntriesWithoutAnActionAreSkipped(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation());
		$this->provider(
			[
				'not-an-array',
				['to' => 'afgehandeld'],
				['action' => 'afhandelen', 'to' => 'afgehandeld'],
			]
		);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertCount(1, $actions);
		$this->assertSame('afhandelen', $actions[0]['action']);
	}//end testEntriesWithoutAnActionAreSkipped()

	/**
	 * An action that declares nothing still publishes the contract's own
	 * nulls and its EMPTY inputs list, so a client reading a provider-mode
	 * response reads exactly the shape a static one gives it.
	 */
	public function testUndeclaredFieldsPublishAsTheContractsDefaults(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation());
		$this->provider([['action' => 'afhandelen', 'to' => 'afgehandeld']]);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertNull($actions[0]['requires']);
		$this->assertNull($actions[0]['description']);
		$this->assertSame([], $actions[0]['inputs']);
		$this->assertArrayNotHasKey('label', $actions[0]);
		$this->assertArrayNotHasKey('blocked', $actions[0]);
	}//end testUndeclaredFieldsPublishAsTheContractsDefaults()

	/**
	 * `inputs` run through the same normalisation the write path's allowlist
	 * uses: an entry naming no field is dropped, `required` defaults to false,
	 * and the published shape is `[{field, required}]` whatever the provider
	 * spelled. What a client is told the transition accepts is therefore
	 * exactly what the write path will accept.
	 */
	public function testInputsAreNormalisedThroughTheSharedContract(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation());
		$this->provider(
			[
				[
					'action' => 'afhandelen',
					'to' => 'afgehandeld',
					'inputs' => [
						['field' => 'motivering', 'required' => 1, 'label' => 'ignored'],
						['field' => '', 'required' => true],
						['required' => true],
						'not-an-array',
						['field' => 'bijlage'],
					],
				],
			]
		);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertSame(
			[
				['field' => 'motivering', 'required' => true],
				['field' => 'bijlage', 'required' => false],
			],
			$actions[0]['inputs']
		);
	}//end testInputsAreNormalisedThroughTheSharedContract()

	/**
	 * A `blocked` entry keeps both the flag and the reason: the widget draws
	 * the step and says why it cannot be taken, which is different from the
	 * step not being offered at all.
	 */
	public function testBlockedEntryKeepsItsReason(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation());
		$this->provider(
			[
				[
					'action' => 'afhandelen',
					'to' => 'afgehandeld',
					'blocked' => true,
					'description' => 'Er ontbreekt nog een besluit.',
				],
			]
		);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertTrue($actions[0]['blocked']);
		$this->assertSame('Er ontbreekt nog een besluit.', $actions[0]['description']);
	}//end testBlockedEntryKeepsItsReason()

	/**
	 * Static transitions win over a provider, exactly as they win over a
	 * graph block, and the provider is not even resolved. A schema that grows
	 * a second mode therefore never silently loses the transitions it had.
	 */
	public function testStaticTransitionsTakePrecedenceOverProvider(): void {
		$this->registry->expects($this->never())->method('resolve');

		$annotation = $this->providerAnnotation();
		$annotation['transitions'] = [
			'goedkeuren' => ['from' => ['in-behandeling'], 'to' => 'afgehandeld'],
		];
		$this->wire($this->caseObject(), $annotation);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertCount(1, $actions);
		$this->assertSame('goedkeuren', $actions[0]['action']);
	}//end testStaticTransitionsTakePrecedenceOverProvider()

	/**
	 * Between the two delegating modes the provider is read first, and the
	 * graph's sibling fetch never happens. Note this ordering is unreachable
	 * for a schema that saved cleanly: LifecycleAnnotationValidator refuses
	 * `provider` beside `graph`. It is pinned here so the engine's behaviour
	 * on an annotation that predates that rule is stated rather than assumed.
	 */
	public function testProviderIsReadBeforeGraph(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$annotation = $this->providerAnnotation();
		$annotation['graph'] = [
			'schema' => 'statustype',
			'parentField' => 'caseType',
			'parentFrom' => 'caseType',
			'orderField' => 'order',
			'finalField' => 'isFinal',
			'allowedMoves' => 'forward',
		];
		$this->wire($this->caseObject(), $annotation);
		$this->provider([['action' => 'afhandelen', 'to' => 'afgehandeld']]);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertCount(1, $actions);
		$this->assertSame('afhandelen', $actions[0]['action']);
	}//end testProviderIsReadBeforeGraph()

	/**
	 * FAIL CLOSED: a provider tag nothing answers to throws, and does NOT
	 * come back as an empty action list. An empty list is a legitimate answer
	 * meaning "no moves from here", so a silent failure would be
	 * indistinguishable from one and would render as a dead timeline.
	 *
	 * Drives a REAL registry against empty containers, so the fail-closed
	 * policy is exercised rather than restated by a double.
	 */
	public function testUnregisteredProviderFailsClosed(): void {
		$notFound = new class extends \Exception implements NotFoundExceptionInterface {
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException($notFound);
		$serverContainer = $this->createMock(\OCP\IServerContainer::class);
		$serverContainer->method('get')->willThrowException($notFound);

		$permission = $this->createMock(PermissionHandler::class);
		$permission->method('hasPermission')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					return $default;
				}
			);

		$engine = new TransitionEngine(
			$this->objectService,
			$this->schemaMapper,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$permission,
			$this->createMock(RegisterMapper::class),
			$appConfig,
			$this->logger,
			new LifecycleWriteBoundary(new LifecycleActionContext()),
			new LifecycleActionProviderRegistry($container, $serverContainer, $this->logger)
		);

		$this->wire($this->caseObject(), $this->providerAnnotation());

		$this->expectException(LifecycleProviderException::class);
		$this->expectExceptionMessage('is not registered');

		$engine->availableActions(self::CASE);
	}//end testUnregisteredProviderFailsClosed()

	/**
	 * FAIL CLOSED, second route: the provider resolves but throws while
	 * answering. Wrapped, logged, and never flattened into an empty list.
	 */
	public function testProviderThrowingIsWrappedNotSwallowed(): void {
		$this->wire($this->caseObject(), $this->providerAnnotation());

		$provider = $this->createMock(LifecycleActionProviderInterface::class);
		$provider->method('availableActions')
			->willThrowException(new \LogicException('workflowTemplate is not valid JSON'));
		$this->registry->method('resolve')->with(self::TAG)->willReturn($provider);

		$this->logger->expects($this->once())->method('error');

		$this->expectException(LifecycleProviderException::class);
		$this->expectExceptionMessage('could not list available actions');

		$this->engine->availableActions(self::CASE);
	}//end testProviderThrowingIsWrappedNotSwallowed()

	/**
	 * A provider that genuinely has no moves answers an empty list, and that
	 * is a SUCCESS, not a failure. The pair with the two fail-closed cases
	 * above: they are what makes an empty list readable as a real answer.
	 */
	public function testAProviderWithNoMovesAnswersAnEmptyList(): void {
		$this->wire($this->caseObject('afgehandeld'), $this->providerAnnotation());
		$this->provider([]);

		$this->assertSame([], $this->engine->availableActions(self::CASE));
	}//end testAProviderWithNoMovesAnswersAnEmptyList()
}//end class

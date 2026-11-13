<?php

/**
 * OpenRegister TransitionEngine graph-mode forms, side moves and reopen
 *
 * Covers the lifecycle-over-reference-field change: a move that writes a form's
 * values in the same save, side moves (suspend/resume) that freeze the ordered
 * graph, a field-less side move with a form, and a declared reopen out of a
 * terminal state for a caller holding the action it requires.
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
 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 * @covers \OCA\OpenRegister\Service\Lifecycle\GraphMoveCatalogue
 */
class TransitionEngineGraphMovesTest extends TestCase {
	private const PARENT = '00000000-0000-0000-0000-000000000000';

	private const S1 = '00000000-0000-0000-0000-000000000001';

	private const S2 = '00000000-0000-0000-0000-000000000002';

	private const S3 = '00000000-0000-0000-0000-000000000003';

	private const CASE = '00000000-0000-0000-0000-0000000000aa';

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private IEventDispatcher&MockObject $dispatcher;

	private TransitionEngine $engine;

	/**
	 * The actions the caller holds; every other is refused.
	 *
	 * @var list<string>
	 */
	private array $grants = ['read', 'update', 'manage'];

	/**
	 * The data passed to saveObject(), per save.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The transitioned events dispatched.
	 *
	 * @var list<ObjectTransitionedEvent>
	 */
	private array $events = [];

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$permission = $this->createMock(PermissionHandler::class);
		$permission->method('hasPermission')->willReturnCallback(
			fn (...$args): bool => in_array($args['action'] ?? $args[1], $this->grants, true)
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object): ObjectEntity {
				$this->saves[] = $object;
				$saved = new ObjectEntity();
				$saved->setUuid(self::CASE);
				$saved->setObject($object);
				return $saved;
			}
		);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				$this->events[] = $event;
			}
		);

		$this->engine = new TransitionEngine(
			new \OCA\OpenRegister\Service\Lifecycle\TransitionSubjects(
				$this->objectService,
				new \OCA\OpenRegister\Db\SchemaCatalog(
					$this->createMock(RegisterMapper::class),
					$this->schemaMapper,
					$this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class)
				)
			),
			new \OCA\OpenRegister\Service\Lifecycle\TransitionDispatch(
				$this->dispatcher,
				new \OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary(new \OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext()),
				$this->createMock(\OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry::class)
			),
			new \OCA\OpenRegister\Service\Lifecycle\TransitionPlatform($this->createMock(IUserSession::class), $appConfig, $this->createMock(LoggerInterface::class)),
			$permission
		);
	}//end setUp()

	/**
	 * A statusType sibling.
	 */
	private function statusType(string $uuid, string $name, int $order, bool $final): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setName($name);
		$entity->setObject(['name' => $name, 'caseType' => self::PARENT, 'order' => $order, 'isFinal' => $final]);
		return $entity;
	}//end statusType()

	/**
	 * Wire a case at a status, with extra data, under a graph block with the given extras.
	 *
	 * @param string               $status The case's status.
	 * @param array<string, mixed> $extras Keys added to the graph block.
	 * @param array<string, mixed> $data   Extra case data.
	 */
	private function wire(string $status, array $extras, array $data = []): void {
		$case = new ObjectEntity();
		$case->setUuid(self::CASE);
		$case->setSchema('case');
		$case->setRegister('1');
		$case->setObject(array_merge(['caseType' => self::PARENT, 'status' => $status], $data));

		$annotation = [
			'field' => 'status',
			'graph' => array_merge(
				[
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
				],
				$extras
			),
		];
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-lifecycle' => $annotation]);

		$this->objectService->method('find')->willReturn($case);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectService->method('findAll')->willReturn(
			[
				$this->statusType(self::S3, 'Afgehandeld', 3, true),
				$this->statusType(self::S1, 'Ontvangen', 1, false),
				$this->statusType(self::S2, 'In behandeling', 2, false),
			]
		);
	}//end wire()

	/**
	 * The result form, required on moves to a terminal status.
	 *
	 * @return array<string, mixed>
	 */
	private static function closingForm(): array {
		return ['form' => ['properties' => ['result', 'comment'], 'required' => ['result'], 'on' => 'final']];
	}//end closingForm()

	/**
	 * Suspend and resume on the `suspended` field.
	 *
	 * @return array<string, mixed>
	 */
	private static function suspension(): array {
		return [
			'sideMoves' => [
				'suspend' => ['field' => 'suspended', 'set' => true, 'label' => 'Opschorten'],
				'resume' => ['field' => 'suspended', 'set' => false, 'label' => 'Hervatten'],
			],
		];
	}//end suspension()

	/**
	 * The action names offered.
	 *
	 * @return list<string>
	 */
	private function offered(): array {
		return array_column($this->engine->availableActions(self::CASE), 'action');
	}//end offered()

	public function testTheFormIsPublishedOnTheMoveToATerminalStatusOnly(): void {
		$this->wire(self::S2, self::closingForm());

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertSame('move-to-' . self::S3, $actions[0]['action']);
		$this->assertSame(['properties' => ['result', 'comment'], 'required' => ['result']], $actions[0]['form']);
		$this->assertSame([['field' => 'result', 'required' => true], ['field' => 'comment', 'required' => false]], $actions[0]['inputs']);
	}//end testTheFormIsPublishedOnTheMoveToATerminalStatusOnly()

	public function testAMoveToANonTerminalStatusCarriesNoForm(): void {
		$this->wire(self::S1, self::closingForm());

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertSame('move-to-' . self::S2, $actions[0]['action']);
		$this->assertArrayNotHasKey('form', $actions[0]);
		$this->assertSame([], $actions[0]['inputs']);
	}//end testAMoveToANonTerminalStatusCarriesNoForm()

	public function testClosingWithAResultIsOneSave(): void {
		$this->wire(self::S2, self::closingForm());

		$this->engine->transition(self::CASE, 'move-to-' . self::S3, ['result' => 'granted']);

		$this->assertCount(1, $this->saves);
		$this->assertSame(self::S3, $this->saves[0]['status']);
		$this->assertSame('granted', $this->saves[0]['result']);
		$this->assertCount(1, $this->events);
		$this->assertSame(self::S2, $this->events[0]->getFrom());
		$this->assertSame(self::S3, $this->events[0]->getTo());
	}//end testClosingWithAResultIsOneSave()

	public function testAMissingRequiredResultIsRefusedNamingIt(): void {
		$this->wire(self::S2, self::closingForm());

		try {
			$this->engine->transition(self::CASE, 'move-to-' . self::S3, ['comment' => 'done']);
			$this->fail('A move without its required result was applied.');
		} catch (RuntimeException $e) {
			$this->assertNotInstanceOf(InvalidTransitionInputException::class, $e);
			$this->assertStringContainsString('"result"', $e->getMessage());
		}

		$this->assertSame([], $this->saves);
	}//end testAMissingRequiredResultIsRefusedNamingIt()

	public function testAKeyOutsideTheFormIsRefused(): void {
		$this->wire(self::S2, self::closingForm());

		try {
			$this->engine->transition(self::CASE, 'move-to-' . self::S3, ['result' => 'granted', 'status' => self::S1]);
			$this->fail('A key outside the form was accepted.');
		} catch (InvalidTransitionInputException $e) {
			$this->assertSame(['status'], $e->getFields());
		}

		$this->assertSame([], $this->saves);
	}//end testAKeyOutsideTheFormIsRefused()

	public function testSuspendIsOfferedBesideTheGraphMoves(): void {
		$this->wire(self::S2, self::suspension());

		$this->assertSame(['move-to-' . self::S3, 'suspend'], $this->offered());
	}//end testSuspendIsOfferedBesideTheGraphMoves()

	public function testASuspendedCaseOffersResumeOnly(): void {
		$this->wire(self::S2, self::suspension(), ['suspended' => true]);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertSame(['resume'], array_column($actions, 'action'));
		$this->assertSame('Hervatten', $actions[0]['label']);
		$this->assertSame(self::S2, $actions[0]['to']);
	}//end testASuspendedCaseOffersResumeOnly()

	public function testSuspendSetsTheSecondFieldAndKeepsTheStatus(): void {
		$this->wire(self::S2, self::suspension());

		$this->engine->transition(self::CASE, 'suspend');

		$this->assertCount(1, $this->saves);
		$this->assertTrue($this->saves[0]['suspended']);
		$this->assertSame(self::S2, $this->saves[0]['status']);
		$this->assertSame('suspend', $this->events[0]->getAction());
	}//end testSuspendSetsTheSecondFieldAndKeepsTheStatus()

	public function testResumeClearsTheSecondField(): void {
		$this->wire(self::S2, self::suspension(), ['suspended' => true]);

		$this->engine->transition(self::CASE, 'resume');

		$this->assertFalse($this->saves[0]['suspended']);
	}//end testResumeClearsTheSecondField()

	public function testAGraphMoveIsRefusedWhileSuspended(): void {
		$this->wire(self::S2, self::suspension(), ['suspended' => true]);

		$this->expectException(RuntimeException::class);
		try {
			$this->engine->transition(self::CASE, 'move-to-' . self::S3);
		} finally {
			$this->assertSame([], $this->saves);
		}
	}//end testAGraphMoveIsRefusedWhileSuspended()

	public function testSideMovesAreNotOfferedOnATerminalStatus(): void {
		$this->wire(self::S3, self::suspension());

		$this->assertSame([], $this->offered());
	}//end testSideMovesAreNotOfferedOnATerminalStatus()

	public function testAFieldlessSideMoveWritesOnlyItsForm(): void {
		$this->wire(
			self::S2,
			['sideMoves' => ['extend' => ['label' => 'Termijn wijzigen', 'form' => ['properties' => ['deadline'], 'required' => ['deadline']]]]]
		);

		$this->assertSame(['move-to-' . self::S3, 'extend'], $this->offered());

		$this->engine->transition(self::CASE, 'extend', ['deadline' => '2026-12-31']);

		$this->assertSame('2026-12-31', $this->saves[0]['deadline']);
		$this->assertSame(self::S2, $this->saves[0]['status']);
	}//end testAFieldlessSideMoveWritesOnlyItsForm()

	public function testAManagerIsOfferedReopenOnAClosedCase(): void {
		$this->wire(self::S3, ['reopen' => ['to' => 'first']]);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertCount(1, $actions);
		$this->assertSame('reopen', $actions[0]['action']);
		$this->assertSame(self::S1, $actions[0]['to']);
		$this->assertSame('manage', $actions[0]['requires']);
	}//end testAManagerIsOfferedReopenOnAClosedCase()

	public function testAHandlerWithoutManageIsNotOfferedReopen(): void {
		$this->grants = ['read', 'update'];
		$this->wire(self::S3, ['reopen' => ['to' => 'first']]);

		$this->assertSame([], $this->offered());
	}//end testAHandlerWithoutManageIsNotOfferedReopen()

	public function testAHandlerWithoutManageCannotApplyReopen(): void {
		$this->grants = ['read', 'update'];
		$this->wire(self::S3, ['reopen' => ['to' => 'first']]);

		$this->expectException(RuntimeException::class);
		try {
			$this->engine->transition(self::CASE, 'reopen');
		} finally {
			$this->assertSame([], $this->saves);
		}
	}//end testAHandlerWithoutManageCannotApplyReopen()

	public function testReopenMovesToTheFirstStatus(): void {
		$this->wire(self::S3, ['reopen' => ['to' => 'first']]);

		$this->engine->transition(self::CASE, 'reopen');

		$this->assertSame(self::S1, $this->saves[0]['status']);
		$this->assertSame('reopen', $this->events[0]->getAction());
		$this->assertSame(self::S3, $this->events[0]->getFrom());
		$this->assertSame(self::S1, $this->events[0]->getTo());
	}//end testReopenMovesToTheFirstStatus()

	public function testReopenToAFilterAndItsOwnRequirement(): void {
		$this->grants = ['read', 'update', 'reopen-cases'];
		$this->wire(self::S3, ['reopen' => ['to' => ['name' => 'In behandeling'], 'requires' => 'reopen-cases', 'label' => 'Heropenen']]);

		$actions = $this->engine->availableActions(self::CASE);

		$this->assertSame(self::S2, $actions[0]['to']);
		$this->assertSame('Heropenen', $actions[0]['label']);
		$this->assertSame('reopen-cases', $actions[0]['requires']);
	}//end testReopenToAFilterAndItsOwnRequirement()

	public function testWithoutADeclaredReopenATerminalStatusStaysLocked(): void {
		$this->wire(self::S3, []);

		$this->assertSame([], $this->offered());
	}//end testWithoutADeclaredReopenATerminalStatusStaysLocked()
}//end class

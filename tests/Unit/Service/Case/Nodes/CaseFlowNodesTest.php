<?php

/**
 * A flow step opens or advances a case, through the real case layer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case\Nodes;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Exception\CaseTransitionException;
use OCA\OpenRegister\Service\Case\CaseAnchorReader;
use OCA\OpenRegister\Service\Case\CaseBusinessStateWriter;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanCascade;
use OCA\OpenRegister\Service\Case\CasePlanDefinition;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\CasePlanStateMachine;
use OCA\OpenRegister\Service\Case\CasePlanTransitions;
use OCA\OpenRegister\Service\Case\CaseRealisationService;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseOpenNode;
use OCA\OpenRegister\Tests\Unit\Service\Case\CaseFixtures;
use OCA\OpenRegister\Tests\Unit\Service\Case\FakeCaseItemMapper;
use OCA\OpenRegister\Tests\Unit\Service\Case\RecordingAuditMapper;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use UnexpectedValueException;

/**
 * Real CasePlanService over in-memory rows; the run-as scope runs the work.
 */
class CaseFlowNodesTest extends TestCase {

	private FakeCaseItemMapper $items;

	private RecordingAuditMapper $audits;

	private ?string $sessionUid = null;

	/**
	 * Fresh tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->items = new FakeCaseItemMapper($this);
		$this->audits = new RecordingAuditMapper($this);
		$this->sessionUid = null;
	}//end setUp()

	/**
	 * The real case layer, with alice a caseworker and nobody an admin.
	 *
	 * @return CasePlanService The service.
	 */
	private function plans(): CasePlanService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => $uid === 'alice' && $gid === 'demo-behandelaars');
		$groups->method('groupExists')->willReturn(true);
		$realiser = $this->createMock(CaseRealisationService::class);
		$realiser->method('realise')->willReturnCallback(static function (CaseItem $row): void {
			$row->setRealisationKind(CaseItem::REALISATION_NONE);
		});
		$anchor = $this->createMock(CaseAnchorReader::class);
		$anchor->method('read')->willReturn([]);
		$anchor->method('mayRead')->willReturn(true);
		$writer = $this->createMock(CaseBusinessStateWriter::class);
		$sentries = new CaseSentryEvaluator(catalog: new EventCatalogService());
		$machine = new CasePlanStateMachine(items: $this->items, audits: $this->audits, table: new CasePlanTransitions(), realiser: $realiser, writer: $writer, db: $db, logger: new NullLogger());

		return new CasePlanService(
			items: $this->items,
			audits: $this->audits,
			machine: $machine,
			cascade: new CasePlanCascade(items: $this->items, machine: $machine, sentries: $sentries, realiser: $realiser, anchor: $anchor, db: $db, logger: new NullLogger()),
			sentries: $sentries,
			authorization: new CasePlanAuthorizationService(groupManager: $groups),
			anchor: $anchor,
			writer: $writer,
			definitions: new CasePlanDefinition(sentries: $sentries),
			db: $db,
			logger: new NullLogger()
		);
	}//end plans()

	/**
	 * The collaborators every case node takes.
	 *
	 * @return array<string, mixed> Named constructor arguments.
	 */
	private function collaborators(): array {
		$scope = $this->createMock(FlowRunAsScope::class);
		$scope->method('call')->willReturnCallback(static fn (array $context, callable $operation): mixed => $operation());
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->sessionUid === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->sessionUid);

				return $user;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/icon.svg');

		return ['plans' => $this->plans(), 'scope' => $scope, 'session' => $session, 'l10n' => $l10n, 'urls' => $urls];
	}//end collaborators()

	/**
	 * A small permit plan: an intake task and a decision milestone.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function definition(): array {
		return [
			'settings' => ['authorization' => ['demo-behandelaars']],
			'items' => [
				['key' => 'intake', 'type' => CaseItem::TYPE_HUMAN_TASK, 'name' => 'Intake'],
				[
					'key' => 'besluit-genomen',
					'type' => CaseItem::TYPE_MILESTONE,
					'name' => 'Besluit genomen',
					'entryCriteria' => [['id' => 'after-intake', 'on' => ['event' => 'case.item.completed', 'item' => 'intake']]],
				],
			],
		];
	}//end definition()

	/**
	 * The item a run on an object carries.
	 *
	 * @return array<int, array<string, mixed>> One item.
	 */
	private static function items(): array {
		return [[FlowItems::JSON => ['uuid' => CaseFixtures::OBJECT, '@self' => ['register' => '1', 'schema' => 2]]]];
	}//end items()

	/**
	 * The two nodes describe themselves like every other step.
	 *
	 * @return void
	 */
	public function testTheNodesDescribeThemselves(): void {
		$open = new CaseOpenNode(...$this->collaborators());
		$advance = new CaseAdvanceNode(...$this->collaborators());
		$this->assertSame('openregister.case-open', $open->getId());
		$this->assertSame('openregister.case-advance', $advance->getId());
		foreach ([$open, $advance] as $node) {
			$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN));
			$this->assertSame(IFlowNodeTaxonomy::KIND_SERVICE_TASK, $node->getKind());
			$this->assertSame(IFlowNodeTaxonomy::CATEGORY_OBJECTS, $node->getCategory());
			$this->assertNotSame('', $node->getDisplayName());
			$this->assertNotSame('', $node->getDescription());
			$this->assertSame('/icon.svg', $node->getIcon());
			$this->assertSame(array_column($node->configForm(), 'key'), $node->configKeys());
		}
	}//end testTheNodesDescribeThemselves()

	/**
	 * Opening creates the plan on the received object as the run's identity,
	 * with the object's register and schema; a second open reports `exists`.
	 *
	 * @return void
	 */
	public function testOpenCreatesThePlanAndASecondOpenSaysSo(): void {
		$node = new CaseOpenNode(...$this->collaborators());
		$out = $node->execute(items: self::items(), config: ['definition' => self::definition()], context: [FlowRunService::RUN_AS_CONTEXT_KEY => 'alice']);

		$this->assertSame(['opened' => true, 'reason' => 'opened'], $out[0][FlowItems::JSON]['case']);
		$rows = $this->items->findByObject(objectUuid: CaseFixtures::OBJECT);
		$this->assertCount(2, $rows);
		$this->assertSame(1, $rows[0]->getRegisterId());
		$this->assertSame(2, $rows[0]->getSchemaId());
		$this->assertSame('alice', $this->audits->findForItem((int)$rows[0]->getId())[0]->getActor());

		$again = $node->execute(items: self::items(), config: ['definition' => self::definition()], context: [FlowRunService::RUN_AS_CONTEXT_KEY => 'alice']);
		$this->assertSame(['opened' => false, 'reason' => 'exists'], $again[0][FlowItems::JSON]['case']);
		$this->assertCount(2, $this->items->findByObject(objectUuid: CaseFixtures::OBJECT));
	}//end testOpenCreatesThePlanAndASecondOpenSaysSo()

	/**
	 * Refusals: no plan in the config, nobody to act as, a caller the plan
	 * does not admit. None is swallowed.
	 *
	 * @return void
	 */
	public function testOpenRefusals(): void {
		$node = new CaseOpenNode(...$this->collaborators());
		try {
			$node->validateConfig(config: ['definition' => ['items' => []]]);
			$this->fail('an empty plan is refused at save time');
		} catch (UnexpectedValueException $refusal) {
			$this->assertStringContainsString('at least one item', $refusal->getMessage());
		}

		try {
			$node->execute(items: self::items(), config: ['definition' => self::definition()], context: []);
			$this->fail('nobody to act as');
		} catch (RuntimeException $refusal) {
			$this->assertStringContainsString('names nobody', $refusal->getMessage());
		}

		$this->expectException(CaseAccessDeniedException::class);
		$node->execute(items: self::items(), config: ['definition' => self::definition()], context: [FlowRunService::RUN_AS_CONTEXT_KEY => 'mallory']);
	}//end testOpenRefusals()

	/**
	 * Advancing reaches the milestone by key as the interactive user; a
	 * second advance of a terminal item fails the step with the engine's
	 * four facts; an unknown key fails naming it.
	 *
	 * @return void
	 */
	public function testAdvanceMovesTheItemAndRefusalsFailTheStep(): void {
		$collaborators = $this->collaborators();
		(new CaseOpenNode(...$collaborators))->execute(items: self::items(), config: ['definition' => self::definition()], context: [FlowRunService::RUN_AS_CONTEXT_KEY => 'alice']);

		$this->sessionUid = 'alice';
		$node = new CaseAdvanceNode(...$collaborators);
		$config = ['item' => 'besluit-genomen', 'to' => CaseItem::STATE_COMPLETED, 'reason' => 'Besluit verzonden'];
		$out = $node->execute(items: self::items(), config: $config, context: []);
		$this->assertSame(['item' => 'besluit-genomen', 'state' => 'completed'], $out[0][FlowItems::JSON]['case']);

		try {
			$node->execute(items: self::items(), config: $config, context: []);
			$this->fail('a terminal milestone accepts nothing further');
		} catch (CaseTransitionException $refusal) {
			$this->assertStringContainsString('milestone', $refusal->getMessage());
			$this->assertStringContainsString('completed', $refusal->getMessage());
		}

		try {
			$node->execute(items: self::items(), config: ['item' => 'ghost', 'to' => CaseItem::STATE_COMPLETED], context: []);
			$this->fail('unknown key');
		} catch (RuntimeException $refusal) {
			$this->assertStringContainsString('"ghost"', $refusal->getMessage());
		}

		$this->expectException(UnexpectedValueException::class);
		$node->validateConfig(config: ['item' => 'besluit-genomen', 'to' => 'done']);
	}//end testAdvanceMovesTheItemAndRefusalsFailTheStep()

	/**
	 * A configured `uuid` that names ANOTHER object than the received item
	 * must not anchor that object's plan to the item's register and schema;
	 * naming the item's own object keeps them.
	 *
	 * @return void
	 */
	public function testAConfiguredUuidNamingAnotherObjectIsNotAnchoredToTheItemsRegisterAndSchema(): void {
		$node = new CaseOpenNode(...$this->collaborators());
		$context = [FlowRunService::RUN_AS_CONTEXT_KEY => 'alice'];
		$other = CaseFixtures::OBJECT;
		$items = [[FlowItems::JSON => ['uuid' => 'received-object-of-schema-a', 'parent' => $other, '@self' => ['register' => '1', 'schema' => 2]]]];

		$out = $node->execute(items: $items, config: ['definition' => self::definition(), 'uuid' => '{{ parent }}'], context: $context);

		$this->assertSame(['opened' => true, 'reason' => 'opened'], $out[0][FlowItems::JSON]['case']);
		$rows = $this->items->findByObject(objectUuid: $other);
		$this->assertCount(2, $rows);
		$this->assertNull($rows[0]->getRegisterId(), 'the item\'s register is not the other object\'s');
		$this->assertNull($rows[0]->getSchemaId(), 'the item\'s schema is not the other object\'s');

		// A template that renders the item's own uuid still anchors to its register and schema.
		$ownItems = [[FlowItems::JSON => ['uuid' => 'own-object', 'again' => 'own-object', '@self' => ['register' => '1', 'schema' => 2]]]];
		$node->execute(items: $ownItems, config: ['definition' => self::definition(), 'uuid' => '{{ again }}'], context: $context);
		$own = $this->items->findByObject(objectUuid: 'own-object');
		$this->assertSame(1, $own[0]->getRegisterId());
		$this->assertSame(2, $own[0]->getSchemaId());
	}//end testAConfiguredUuidNamingAnotherObjectIsNotAnchoredToTheItemsRegisterAndSchema()

	/**
	 * A repeating item has one row per realisation, the oldest first in plan
	 * order. Advancing its key moves the realisation that is still open, not
	 * the finished first one the old first-match loop stopped at.
	 *
	 * @return void
	 */
	public function testAdvanceMovesTheLiveRealisationOfARepeatingItem(): void {
		$first = CaseFixtures::row(id: 1, key: 'assessment', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_COMPLETED);
		$second = CaseFixtures::row(id: 2, key: 'assessment', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$second->setRealisationCount(2);
		$second->setPosition(1);
		$first->setPosition(1);
		$this->items->seed([$first, $second]);

		$this->sessionUid = 'alice';
		$node = new CaseAdvanceNode(...$this->collaborators());
		$out = $node->execute(items: self::items(), config: ['item' => 'assessment', 'to' => CaseItem::STATE_COMPLETED], context: []);

		$this->assertSame(['item' => 'assessment', 'state' => 'completed'], $out[0][FlowItems::JSON]['case']);
		$this->assertSame(CaseItem::STATE_COMPLETED, $this->items->findByUuid('item-2')->getState(), 'the open realisation moved');
		$this->assertSame(CaseItem::STATE_COMPLETED, $this->items->findByUuid('item-1')->getState());
		$this->assertCount(1, $this->audits->findForItem(2), 'the transition is recorded against the second realisation');
		$this->assertCount(0, $this->audits->findForItem(1), 'and not against the first');
	}//end testAdvanceMovesTheLiveRealisationOfARepeatingItem()

	/**
	 * An empty batch passes straight through both nodes; an advance with no
	 * item key is refused at save time.
	 *
	 * @return void
	 */
	public function testEmptyBatchesPassThroughAndAnAdvanceNeedsAnItemKey(): void {
		$open = new CaseOpenNode(...$this->collaborators());
		$advance = new CaseAdvanceNode(...$this->collaborators());
		$this->assertSame([], $open->execute(items: [], config: ['definition' => self::definition()], context: []));
		$this->assertSame([], $advance->execute(items: [], config: [], context: []));

		$this->expectException(UnexpectedValueException::class);
		$advance->validateConfig(config: ['item' => ' ', 'to' => CaseItem::STATE_COMPLETED]);
	}//end testEmptyBatchesPassThroughAndAnAdvanceNeedsAnItemKey()

	/**
	 * The object of the case comes from the item, or from a configured
	 * template; an item with neither fails the step; a malformed payload or
	 * `@self` is read as empty.
	 *
	 * @return void
	 */
	public function testTheCaseObjectComesFromTheItemOrAConfiguredTemplate(): void {
		$collaborators = $this->collaborators();
		$node = new CaseOpenNode(...$collaborators);
		$context = [FlowRunService::RUN_AS_CONTEXT_KEY => 'alice'];

		$items = [[FlowItems::JSON => ['ref' => CaseFixtures::OBJECT, '@self' => 'not-an-array']]];
		$out = $node->execute(items: $items, config: ['definition' => self::definition(), 'uuid' => '{{ref}}'], context: $context);
		$this->assertSame(['opened' => true, 'reason' => 'opened'], $out[0][FlowItems::JSON]['case']);
		$rows = $this->items->findByObject(objectUuid: CaseFixtures::OBJECT);
		$this->assertNull($rows[0]->getRegisterId(), 'A malformed @self carries no register.');

		try {
			$node->execute(items: [[FlowItems::JSON => 'not-an-array']], config: ['definition' => self::definition()], context: $context);
			$this->fail('an item with no object cannot open a case');
		} catch (RuntimeException $refusal) {
			$this->assertStringContainsString('which object', $refusal->getMessage());
		}
	}//end testTheCaseObjectComesFromTheItemOrAConfiguredTemplate()
}//end class

<?php

/**
 * CasePlanCascadeGoldenTest.
 *
 * Pins the full call transcript of CasePlanCascade::evaluate() — every
 * transaction step, read, transition (with cause, ref, actor, reason), row
 * creation (with the order and value of every field set on the new row),
 * event flush or discard and log line — over a recording state machine, so
 * a restructuring of the cascade cannot change what it does. Written against
 * the code before its refactor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAuditMapper;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Exception\CaseCascadeBoundException;
use OCA\OpenRegister\Service\Case\CaseAnchorReader;
use OCA\OpenRegister\Service\Case\CaseCascadeReaders;
use OCA\OpenRegister\Service\Case\CasePlanCascade;
use OCA\OpenRegister\Service\Case\CasePlanStateMachine;
use OCA\OpenRegister\Service\Case\CasePlanStore;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use OCA\OpenRegister\Service\Case\CaseRealisationService;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Golden transcript of the case-plan cascade.
 */
class CasePlanCascadeGoldenTest extends TestCase {

	/**
	 * Every observable call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The plan's rows as the mapper returns them.
	 *
	 * @var array<int, CaseItem>
	 */
	private array $rows = [];

	/**
	 * Transaction depth of the recording connection.
	 *
	 * @var integer
	 */
	private int $depth = 0;

	/**
	 * Whether transitions move the row (false makes every pass change again).
	 *
	 * @var boolean
	 */
	private bool $moves = true;

	/**
	 * Called inside a transition, to re-enter the cascade.
	 *
	 * @var callable|null
	 */
	private $onTransition = null;

	/**
	 * Build the cascade over recording collaborators.
	 *
	 * @return CasePlanCascade
	 */
	private function cascade(): CasePlanCascade {
		$items = $this->createMock(CaseItemMapper::class);
		$items->method('findByObject')->willReturnCallback(
			function (string $objectUuid): array {
				$this->calls[] = 'find:' . $objectUuid;
				return $this->rows;
			}
		);
		$items->method('insert')->willReturnCallback(
			function (CaseItem $row): CaseItem {
				$fields = [];
				foreach (array_keys($row->getUpdatedFields()) as $field) {
					$fields[$field] = $row->{'get' . ucfirst($field)}();
				}

				$this->calls[] = 'insert:' . json_encode($fields);
				$row->setId(100 + count($this->rows));
				$row->setUuid('uuid-' . $row->getId());
				$this->rows[] = $row;
				return $row;
			}
		);

		$machine = $this->createMock(CasePlanStateMachine::class);
		$machine->method('transition')->willReturnCallback(
			function (CaseItem $item, string $to, string $cause, ?string $causeRef, ?string $actor, ?string $reason = null, ?CasePlanTree $tree = null): CaseItem {
				$this->calls[] = sprintf('transition:%d:%s:%s:%s:%s:%s:%s', $item->getId(), $to, $cause, $causeRef ?? '-', $actor ?? '-', $reason ?? '-', $tree === null ? 'no-tree' : 'tree');
				if ($this->moves === true) {
					$item->setState($to);
				}

				if ($this->onTransition !== null) {
					($this->onTransition)();
				}

				return $item;
			}
		);
		$machine->method('recordCreation')->willReturnCallback(
			function (CaseItem $item, string $cause, ?string $causeRef, ?string $actor): void {
				$this->calls[] = sprintf('created:%d:%s:%s:%s', $item->getId(), $cause, $causeRef ?? '-', $actor ?? '-');
			}
		);
		$machine->method('flushEvents')->willReturnCallback(
			function (): void {
				$this->calls[] = 'flush';
			}
		);
		$machine->method('discardEvents')->willReturnCallback(
			function (): void {
				$this->calls[] = 'discard';
			}
		);

		$realiser = $this->createMock(CaseRealisationService::class);
		$realiser->method('terminalOutcome')->willReturnCallback(
			function (CaseItem $item): ?string {
				$this->calls[] = 'outcome:' . $item->getId();
				return ['task-2' => 'completed', 'task-3' => 'failed'][(string)$item->getRealisationUuid()] ?? null;
			}
		);

		$anchor = $this->createMock(CaseAnchorReader::class);
		$anchor->method('read')->willReturnCallback(
			function (string $objectUuid, ?int $registerId, ?int $schemaId): array {
				$this->calls[] = sprintf('anchor:%s:%s:%s', $objectUuid, $registerId ?? '-', $schemaId ?? '-');
				return ['status' => 'withdrawn'];
			}
		);

		$catalog = $this->createMock(EventCatalogService::class);
		$catalog->method('aliasesFor')->willReturnCallback(static fn (string $dispatched): array => [$dispatched]);

		return new CasePlanCascade(
			new CasePlanStore($items, $this->createMock(CaseItemAuditMapper::class), $this->db(), $this->logger()),
			$machine,
			new CaseCascadeReaders(new CaseSentryEvaluator($catalog), $realiser, $anchor)
		);
	}//end cascade()

	/**
	 * A recording connection with a depth counter.
	 *
	 * @return IDBConnection
	 */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(fn (): bool => $this->depth > 0);
		$db->method('beginTransaction')->willReturnCallback(
			function (): void {
				$this->calls[] = 'begin';
				$this->depth++;
			}
		);
		$db->method('commit')->willReturnCallback(
			function (): void {
				$this->calls[] = 'commit';
				$this->depth--;
			}
		);
		$db->method('rollBack')->willReturnCallback(
			function (): void {
				$this->calls[] = 'rollback';
				$this->depth--;
			}
		);

		return $db;
	}//end db()

	/**
	 * A logger writing into the transcript.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$calls = &$this->calls;
		return new class($calls) extends AbstractLogger {
			/**
			 * @param array<int, string> $calls The transcript.
			 */
			public function __construct(private array &$calls) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|Stringable    $message The message.
			 * @param array<string, mixed> $context The context.
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->calls[] = 'log:' . $level . ':' . $message . ':' . json_encode($context);
			}
		};
	}//end logger()

	/**
	 * One row.
	 *
	 * @param int                  $id     The id.
	 * @param array<string, mixed> $fields Field => value.
	 *
	 * @return CaseItem
	 */
	private static function row(int $id, array $fields): CaseItem {
		$row = new CaseItem();
		$row->setId($id);
		$row->setUuid('uuid-' . $id);
		$row->setItemKey('k' . $id);
		$row->setObjectUuid('obj-1');
		$row->setRegisterId(7);
		$row->setSchemaId(9);
		$row->setPlanItemType(CaseItem::TYPE_HUMAN_TASK);
		$row->setParentItemId(1);
		$row->setRealisationKind(CaseItem::REALISATION_NONE);
		foreach ($fields as $field => $value) {
			$row->{'set' . ucfirst($field)}($value);
		}

		return $row;
	}//end row()

	/**
	 * A plan touching every rule.
	 *
	 * @return array<int, CaseItem>
	 */
	private static function plan(): array {
		return [
			self::row(1, ['planItemType' => CaseItem::TYPE_STAGE, 'parentItemId' => null, 'state' => CaseItem::STATE_ACTIVE]),
			self::row(2, ['state' => CaseItem::STATE_ACTIVE, 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => 'task-2']),
			self::row(3, ['state' => CaseItem::STATE_ACTIVE, 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => 'task-3']),
			self::row(
				4,
				[
					'planItemType'  => CaseItem::TYPE_MILESTONE,
					'state'         => CaseItem::STATE_AVAILABLE,
					'entryCriteria' => [['id' => 'after-two', 'on' => ['event' => 'case.item.completed', 'item' => 'k2']]],
				]
			),
			self::row(5, ['state' => CaseItem::STATE_ACTIVE, 'exitCriteria' => [['id' => 'withdrawn', 'if' => ['==' => [['var' => 'case.object.status'], 'withdrawn']]]]]),
			self::row(6, ['state' => CaseItem::STATE_ENABLED]),
			self::row(7, ['state' => CaseItem::STATE_AVAILABLE, 'discretionary' => true]),
			self::row(
				8,
				[
					'state'              => CaseItem::STATE_COMPLETED,
					'repetition'         => ['max' => 2],
					'realisationCount'   => 1,
					'name'               => 'Repeat',
					'description'        => 'Again',
					'flowUuid'           => 'flow-1',
					'flowVersion'        => 3,
					'definitionItemKey'  => 'def-8',
					'origin'             => 'definition',
					'position'           => 4,
					'required'           => false,
					'authorizationRules' => [['role' => 'x']],
					'candidateUsers'     => ['u1'],
					'candidateGroups'    => ['g1'],
					'candidateRole'      => 'r1',
					'dueAt'              => new DateTime('2030-01-02T03:04:05+00:00'),
					'expiresAt'          => new DateTime('2030-02-02T03:04:05+00:00'),
					'doorlooptijd'       => 'P5D',
					'servicenorm'        => 'P3D',
					'planSettings'       => ['a' => 1],
					'createdBy'          => 'bob',
				]
			),
			self::row(9, ['parentItemId' => null, 'state' => CaseItem::STATE_AVAILABLE]),
			self::row(10, ['planItemType' => CaseItem::TYPE_STAGE, 'parentItemId' => null, 'state' => CaseItem::STATE_ACTIVE, 'realisationKind' => CaseItem::REALISATION_RUN]),
			self::row(11, ['parentItemId' => 99, 'state' => CaseItem::STATE_ENABLED]),
		];
	}//end plan()

	/**
	 * Every rule, across passes, to the fixpoint.
	 *
	 * @return void
	 */
	public function testEveryRuleTranscript(): void {
		$this->rows = self::plan();
		$result     = $this->cascade()->evaluate('obj-1', 'object.updated', ['x' => 1], 'alice');

		$this->assertSame(['passes' => 3, 'transitions' => 8, 'skipped' => false], $result);
		$this->assertSame(
			[
				'begin',
				'find:obj-1',
				'anchor:obj-1:7:9',
				'outcome:2',
				'transition:2:completed:realisation:task-2:-:Realisation task \'task-2\' ended as \'completed\'.:tree',
				'outcome:3',
				'transition:3:failed:realisation:task-3:-:Realisation task \'task-3\' ended as \'failed\'.:tree',
				'outcome:10',
				'transition:5:terminated:sentry:withdrawn:-:Exit criterion \'withdrawn\' fired.:tree',
				'transition:4:completed:sentry:after-two:-:-:tree',
				'transition:9:active:sentry:entry:default:-:-:tree',
				'transition:6:active:user:enable:alice:-:tree',
				'insert:{"itemKey":"k8","name":"Repeat","description":"Again","objectUuid":"obj-1","registerId":7,"schemaId":9,'
					.'"flowUuid":"flow-1","flowVersion":3,"definitionItemKey":"def-8","origin":"definition","parentItemId":1,'
					.'"planItemType":"humanTask","position":4,"state":"available","required":false,"repetition":{"max":2},'
					.'"realisationCount":2,"authorizationRules":[{"role":"x"}],"candidateUsers":["u1"],"candidateGroups":["g1"],'
					.'"candidateRole":"r1","dueAt":{"date":"2030-01-02 03:04:05.000000","timezone_type":1,"timezone":"+00:00"},'
					.'"expiresAt":{"date":"2030-02-02 03:04:05.000000","timezone_type":1,"timezone":"+00:00"},'
					.'"doorlooptijd":"P5D","servicenorm":"P3D","planSettings":{"a":1},"createdBy":"bob"}',
				'created:111:realisation:uuid-8:-',
				'find:obj-1',
				'anchor:obj-1:7:9',
				'outcome:10',
				'transition:111:active:sentry:entry:default:-:-:tree',
				'find:obj-1',
				'anchor:obj-1:7:9',
				'outcome:10',
				'outcome:111',
				'commit',
				'flush',
			],
			$this->calls
		);
	}//end testEveryRuleTranscript()

	/**
	 * A stage whose required children are terminal completes; the event is
	 * only offered in the first pass.
	 *
	 * @return void
	 */
	public function testStageCompletionTranscript(): void {
		$this->rows = [
			self::row(1, ['planItemType' => CaseItem::TYPE_STAGE, 'parentItemId' => null, 'state' => CaseItem::STATE_ACTIVE]),
			self::row(2, ['state' => CaseItem::STATE_COMPLETED]),
			self::row(3, ['state' => CaseItem::STATE_AVAILABLE, 'required' => false]),
			self::row(4, ['state' => CaseItem::STATE_AVAILABLE, 'parentItemId' => null, 'entryCriteria' => [['id' => 'on-event', 'on' => ['event' => 'object.updated']]]]),
		];
		$this->rows[2]->setEntryCriteria([['id' => 'never', 'if' => ['==' => [['var' => 'case.object.status'], 'open']]]]);
		$result = $this->cascade()->evaluate('obj-1', 'object.updated');

		$this->assertSame(['passes' => 2, 'transitions' => 2, 'skipped' => false], $result);
		$this->assertSame(
			[
				'begin',
				'find:obj-1',
				'anchor:obj-1:7:9',
				'transition:4:active:sentry:on-event:-:-:tree',
				'transition:1:completed:cascade:children:-:Every required child is terminal and no child is active.:tree',
				'find:obj-1',
				'anchor:obj-1:7:9',
				'commit',
				'flush',
			],
			$this->calls
		);
	}//end testStageCompletionTranscript()

	/**
	 * An empty plan: one pass, nothing read but the rows.
	 *
	 * @return void
	 */
	public function testEmptyPlanTranscript(): void {
		$result = $this->cascade()->evaluate('obj-2');

		$this->assertSame(['passes' => 1, 'transitions' => 0, 'skipped' => false], $result);
		$this->assertSame(['begin', 'find:obj-2', 'commit', 'flush'], $this->calls);
	}//end testEmptyPlanTranscript()

	/**
	 * Inside an open transaction, events are not flushed.
	 *
	 * @return void
	 */
	public function testNestedTransactionDoesNotFlush(): void {
		$this->depth = 1;
		$result      = $this->cascade()->evaluate('obj-2');

		$this->assertSame(['passes' => 1, 'transitions' => 0, 'skipped' => false], $result);
		$this->assertSame(['begin', 'find:obj-2', 'commit'], $this->calls);
	}//end testNestedTransactionDoesNotFlush()

	/**
	 * A plan that never settles hits the bound, rolls back and says so; a
	 * re-entry during the evaluation is skipped; after the failure the
	 * object can be evaluated again.
	 *
	 * @return void
	 */
	public function testBoundTranscript(): void {
		$this->rows  = [self::row(6, ['parentItemId' => null, 'state' => CaseItem::STATE_ENABLED])];
		$this->moves = false;
		$cascade     = $this->cascade();
		$reentries   = [];
		$this->onTransition = function () use ($cascade, &$reentries): void {
			$reentries[] = $cascade->evaluate('obj-1');
		};

		try {
			$cascade->evaluate('obj-1', null, [], 'carol');
			$this->fail('The bound did not hold.');
		} catch (CaseCascadeBoundException $bound) {
			$this->assertSame(
				'Case-plan evaluation of object obj-1 did not settle within 50 passes (MAX_CASCADE_DEPTH); '
				.'the plan was rolled back to its state before evaluation.',
				$bound->getMessage()
			);
		}

		$this->assertCount(50, $reentries);
		$this->assertSame(['passes' => 0, 'transitions' => 0, 'skipped' => true], $reentries[49]);
		$pass = ['find:obj-1', 'anchor:obj-1:7:9', 'transition:6:active:user:enable:carol:-:tree'];
		$this->assertSame(
			array_merge(
				['begin'],
				...array_fill(0, 50, $pass),
				...[
					[
						'rollback',
						'discard',
						'log:warning:[CasePlanCascade] Evaluation rolled back: Case-plan evaluation of object obj-1 did not settle within 50 passes '
						.'(MAX_CASCADE_DEPTH); the plan was rolled back to its state before evaluation.:{"object":"obj-1","passes":50}',
					],
				]
			),
			$this->calls
		);

		$this->onTransition = null;
		$this->rows         = [];
		$this->calls        = [];
		$this->assertSame(['passes' => 1, 'transitions' => 0, 'skipped' => false], $cascade->evaluate('obj-1'));
	}//end testBoundTranscript()

	/**
	 * A failure inside a pass rolls back with the pass count reached.
	 *
	 * @return void
	 */
	public function testFailureInPassTranscript(): void {
		$this->rows         = [self::row(6, ['parentItemId' => null, 'state' => CaseItem::STATE_ENABLED])];
		$this->onTransition = static function (): void {
			throw new RuntimeException('boom');
		};

		try {
			$this->cascade()->evaluate('obj-1');
			$this->fail('The failure was swallowed.');
		} catch (RuntimeException $failure) {
			$this->assertSame('boom', $failure->getMessage());
		}

		$this->assertSame(
			[
				'begin',
				'find:obj-1',
				'anchor:obj-1:7:9',
				'transition:6:active:user:enable:-:-:tree',
				'rollback',
				'discard',
				'log:warning:[CasePlanCascade] Evaluation rolled back: boom:{"object":"obj-1","passes":1}',
			],
			$this->calls
		);
	}//end testFailureInPassTranscript()
}//end class

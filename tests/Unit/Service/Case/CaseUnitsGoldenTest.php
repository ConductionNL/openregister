<?php

/**
 * Golden test pinning the outcomes of the case layer's units: the definition
 * compiler, the convergent ensurer, the verbs of the plan service with the
 * state machine underneath, the flow nodes, the realisation task data and the
 * timer arming. Recorded on the code before its units were split; the
 * recording must not change when they are.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Service\Case\AppPlanWrite;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Case\CaseAnchor;
use OCA\OpenRegister\Service\Case\CaseItemsEnsureRequest;
use OCA\OpenRegister\Service\Case\CaseAnchorReader;
use OCA\OpenRegister\Service\Case\CaseBusinessStateWriter;
use OCA\OpenRegister\Service\Case\CaseCascadeReaders;
use OCA\OpenRegister\Service\Case\CasePlanAnchorAccess;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanBringOver;
use OCA\OpenRegister\Service\Case\CasePlanCascade;
use OCA\OpenRegister\Service\Case\CasePlanDefinition;
use OCA\OpenRegister\Service\Case\CasePlanEnsurer;
use OCA\OpenRegister\Service\Case\CasePlanInsertion;
use OCA\OpenRegister\Service\Case\CasePlanItemTimers;
use OCA\OpenRegister\Service\Case\CasePlanMechanics;
use OCA\OpenRegister\Service\Case\CasePlanPlacement;
use OCA\OpenRegister\Service\Case\CasePlanProvenance;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\CasePlanStateMachine;
use OCA\OpenRegister\Service\Case\CasePlanStore;
use OCA\OpenRegister\Service\Case\CasePlanTransitions;
use OCA\OpenRegister\Service\Case\CaseRealisationRuns;
use OCA\OpenRegister\Service\Case\CaseRealisationService;
use OCA\OpenRegister\Service\Case\CaseRealisationTasks;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Case\CaseTransitionEffects;
use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseOpenNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseStepRunner;
use OCA\OpenRegister\Service\Case\UnarmedTimerMarkers;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Throwable;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Service\Case\CaseAdHocRequest;
use OCA\OpenRegister\Service\Case\CaseCompletion;
use OCA\OpenRegister\Service\Case\CaseItemTransition;
use OCA\OpenRegister\Service\Case\CasePlanCreation;
use OCA\OpenRegister\Service\Case\CasePlanTrigger;

/**
 * Golden recordings of the case layer's observable outcomes.
 */
class CaseUnitsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/case-units-golden.json';

	private const OTHER = '00000000-0000-0000-0000-0000000000bb';

	private const THIRD = '00000000-0000-0000-0000-0000000000cc';

	/**
	 * Rows.
	 *
	 * @var FakeCaseItemMapper
	 */
	private FakeCaseItemMapper $items;

	/**
	 * Audit.
	 *
	 * @var RecordingAuditMapper
	 */
	private RecordingAuditMapper $audits;

	/**
	 * Fresh tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->items = new FakeCaseItemMapper($this);
		$this->audits = new RecordingAuditMapper($this);
	}//end setUp()

	/**
	 * The recorded scenarios.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (['definition', 'ensurer', 'service', 'nodes', 'realisation', 'timers'] as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * One scenario matches its recording.
	 *
	 * @param string $name The scenario.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(string $name): void {
		$actual = json_decode((string)json_encode($this->{$name . 'Scenario'}()), true);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this scenario.');
		$this->assertSame($golden[$name], $actual);
	}//end testMatchesGolden()

	private static function dateOrNull(?string $value): ?DateTime {
		if ($value === null) {
			return null;
		}

		return new DateTime($value);
	}//end dateOrNull()

	private function definitions(): CasePlanDefinition {
		return new CasePlanDefinition(sentries: new CaseSentryEvaluator(catalog: new EventCatalogService()));
	}//end definitions()

	/**
	 * Run a call, recording its result or its refusal.
	 *
	 * @param callable $call The call.
	 *
	 * @return mixed The result, or {class, message}.
	 */
	private static function attempt(callable $call): mixed {
		try {
			$result = $call();
		} catch (Throwable $failure) {
			return ['refused' => get_class($failure), 'message' => $failure->getMessage()];
		}

		if ($result instanceof CaseItem) {
			return self::row(row: $result);
		}

		return self::scrub(value: $result);
	}//end attempt()

	/**
	 * A value with clock-stamped fields removed.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The scrubbed value.
	 */
	private static function scrub(mixed $value): mixed {
		if ($value instanceof CaseItem) {
			return self::row(row: $value);
		}

		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $entry) {
			if (in_array($key, ['enteredAt', 'created', 'updated'], true) === true && is_array($entry) === false) {
				$entry = self::stamped(value: $entry);
			}

			$out[$key] = self::scrub(value: $entry);
		}

		return $out;
	}//end scrub()

	private static function stamped(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return 'stamped';
	}//end stamped()

	private static function row(CaseItem $row): array {
		return self::scrub(value: $row->jsonSerialize());
	}//end row()

	private function rows(): array {
		$out = [];
		foreach ([CaseFixtures::OBJECT, self::OTHER, self::THIRD] as $object) {
			$out[$object] = array_map(static fn (CaseItem $row): array => self::row(row: $row), $this->items->findByObject(objectUuid: $object));
		}

		return $out;
	}//end rows()

	private function auditRows(): array {
		return array_map(
			static function (CaseItemAudit $entry): array {
				$fields = array_keys($entry->getUpdatedFields());
				sort($fields);

				return [
					'item' => $entry->getCaseItemId(),
					'from' => $entry->getFromState(),
					'to' => $entry->getToState(),
					'cause' => $entry->getCause(),
					'causeRef' => $entry->getCauseRef(),
					'actor' => $entry->getActor(),
					'reason' => $entry->getReason(),
					'authorized' => $entry->getAuthorized(),
					'imported' => $entry->getImported(),
					'created' => $entry->getCreated()?->format('c'),
					'fields' => $fields,
				];
			},
			$this->audits->entries
		);
	}//end auditRows()

	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);

		return $db;
	}//end db()

	private function realiser(): CaseRealisationService {
		$realiser = $this->createMock(CaseRealisationService::class);
		$realiser->method('realise')->willReturnCallback(
			static function (CaseItem $row): void {
				$row->setRealisationKind(CaseItem::REALISATION_NONE);
				if ($row->getPlanItemType() === CaseItem::TYPE_HUMAN_TASK) {
					$row->setRealisationKind(CaseItem::REALISATION_TASK);
					$row->setRealisationUuid('task-for-' . (string)$row->getItemKey());
				}
			}
		);

		return $realiser;
	}//end realiser()

	private function machine(IDBConnection $db, CaseRealisationService $realiser, CaseBusinessStateWriter $writer): CasePlanStateMachine {
		return new CasePlanStateMachine(
			store: new CasePlanStore(items: $this->items, audits: $this->audits, db: $db, logger: new NullLogger()),
			effects: new CaseTransitionEffects(realiser: $realiser, writer: $writer)
		);
	}//end machine()

	private function cascade(
		CasePlanStateMachine $machine,
		IDBConnection $db,
		CaseRealisationService $realiser,
		CaseAnchorReader $anchor
	): CasePlanCascade {
		return new CasePlanCascade(
			machine: $machine,
			readers: new CaseCascadeReaders(sentries: new CaseSentryEvaluator(catalog: new EventCatalogService()), realiser: $realiser, anchor: $anchor)
		);
	}//end cascade()

	private function ensurer(?CasePlanItemTimers $timers = null): CasePlanEnsurer {
		return new CasePlanEnsurer(
			store: new CasePlanStore(items: $this->items, audits: $this->audits, db: $this->db(), logger: new NullLogger()),
			definitions: $this->definitions(),
			transitions: new CasePlanTransitions(),
			timers: $timers
		);
	}//end ensurer()

	private function service(array &$writes): CasePlanService {
		$db = $this->db();
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'boss');
		$groups->method('isInGroup')->willReturnCallback(
			static fn (
				string $uid,
				string $gid
			): bool => ($uid === 'alice' && $gid === 'demo-behandelaars') || ($uid === 'bob' && $gid === 'demo-beslissers')
		);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid !== 'ghost');
		$realiser = $this->realiser();
		$anchor = $this->createMock(CaseAnchorReader::class);
		$anchor->method('read')->willReturn(['status' => 'open']);
		$anchor->method('mayRead')->willReturnCallback(static fn (CaseAnchor $anchor): bool => $anchor->objectUuid !== self::THIRD);
		$writer = $this->createMock(CaseBusinessStateWriter::class);
		$writer->method('mirrorStatus')->willReturnCallback(
			static function (CaseItem $milestone) use (&$writes): bool {
				$writes[] = ['status', $milestone->getItemKey()];

				return true;
			}
		);
		$writer->method('mirrorResult')->willReturnCallback(
			static function (CaseItem $anyRow, string $result) use (&$writes): bool {
				$writes[] = ['result', $anyRow->getObjectUuid(), $result];

				return true;
			}
		);
		$sentries = new CaseSentryEvaluator(catalog: new EventCatalogService());
		$machine = $this->machine(db: $db, realiser: $realiser, writer: $writer);

		return new CasePlanService(
			store: new CasePlanStore(items: $this->items, audits: $this->audits, db: $db, logger: new NullLogger()),
			mechanics: new CasePlanMechanics(
				machine: $machine,
				cascade: $this->cascade(machine: $machine, db: $db, realiser: $realiser, anchor: $anchor),
				sentries: $sentries,
				definitions: new CasePlanDefinition(sentries: $sentries)
			),
			anchorAccess: new CasePlanAnchorAccess(
				authorization: new CasePlanAuthorizationService(groupManager: $groups),
				anchor: $anchor,
				writer: $writer
			),
			bringOver: new CasePlanBringOver()
		);
	}//end service()

	private function uuidOf(string $object, string $key): string {
		foreach ($this->items->findByObject(objectUuid: $object) as $row) {
			if ($row->getItemKey() === $key) {
				$uuid = (string)$row->getUuid();
			}
		}

		return ($uuid ?? 'missing');
	}//end uuidOf()

	/**
	 * The definition compiler: refusals, normalisation and compiled rows.
	 *
	 * @return array<string, mixed>
	 */
	private function definitionScenario(): array {
		$definitions = $this->definitions();
		$task = static fn (array $over): array => ['items' => [array_merge(['key' => 'a', 'type' => CaseItem::TYPE_HUMAN_TASK], $over)]];
		$stage = static fn (array $over): array => ['items' => [array_merge(['key' => 's', 'type' => CaseItem::TYPE_STAGE], $over)]];
		$inputs = [
			'no items' => ['items' => []],
			'items scalar' => ['items' => 'x'],
			'settings not object' => ['items' => [['key' => 'a', 'type' => 'stage']], 'settings' => 'x'],
			'bad root rules' => ['items' => [['key' => 'a', 'type' => 'stage']], 'settings' => ['authorization' => [1]]],
			'blank root rule' => ['items' => [['key' => 'a', 'type' => 'stage']], 'settings' => ['authorization' => [' ']]],
			'bad results' => ['items' => [['key' => 'a', 'type' => 'stage']], 'settings' => ['results' => 'verleend']],
			'good results' => [
				'items' => [['key' => 'a', 'type' => 'stage']],
				'settings' => ['results' => ['verleend'], 'writeThrough' => ['statusField' => 's']]
			],
			'bad writeThrough' => ['items' => [['key' => 'a', 'type' => 'stage']], 'settings' => ['writeThrough' => 'status']],
			'node not object' => ['items' => ['a']],
			'missing key' => ['items' => [['type' => 'stage']]],
			'bad key' => $task(['key' => 'has space']),
			'padded key' => $task(['key' => '  padded ']),
			'long key' => $task(['key' => str_repeat('k', 129)]),
			'unknown type' => $task(['type' => 'processTask']),
			'missing type' => ['items' => [['key' => 'a']]],
			'unknown event' => $task(['entryCriteria' => [['on' => ['event' => 'case.item.started']]]]),
			'bad exit if' => $task(['exitCriteria' => [['if' => ['nonsuch' => 1]]]]),
			'bad rules' => $task(['authorization' => 'behandelaars']),
			'bad repetition' => $task(['repetition' => ['max' => 0]]),
			'scalar repetition' => $task(['repetition' => 3]),
			'good repetition' => $task(['repetition' => ['max' => 2]]),
			'discretionary milestone' => $task(['type' => CaseItem::TYPE_MILESTONE, 'discretionary' => true]),
			'children on a task' => $task(['children' => []]),
			'flow on a task' => $task(['flow' => 'f']),
			'flow on a milestone' => $task(['type' => CaseItem::TYPE_MILESTONE, 'flow' => 'f']),
			'process task without flow' => $task(['type' => CaseItem::TYPE_PROCESS_TASK]),
			'process task blank flow' => $task(['type' => CaseItem::TYPE_PROCESS_TASK, 'flow' => '  ']),
			'process task with flow' => $task(['type' => CaseItem::TYPE_PROCESS_TASK, 'flow' => ' f-1 ']),
			'children not list' => $stage(['children' => 'x']),
			'flow and children' => $stage(['flow' => 'f', 'children' => [['key' => 'b', 'type' => 'milestone']]]),
			'flow stage' => $stage(['flow' => ' f-2 ']),
			'flow stage empty children' => $stage(['flow' => 'f-3', 'children' => []]),
			'duplicate key' => $stage(['children' => [['key' => 's', 'type' => 'milestone']]]),
			'bad child' => $stage(['children' => [['key' => 'b', 'type' => 'milestone'], 'x']]),
			'nested' => $stage(
				['children' => [['key' => 'inner', 'type' => 'stage', 'children' => [['key' => 'leaf', 'type' => 'humanTask', 'extra' => 1]]]]]
			),
			'permit' => CasePlanDefinitionTest::permitDefinition(),
		];
		$out = [];
		foreach ($inputs as $label => $definition) {
			$out['validate'][$label] = self::attempt(static fn (): array => $definitions->validate(definition: $definition));
		}

		$adHoc = [
			'authorization' => ['key' => 'x', 'type' => 'humanTask', 'authorization' => []],
			'discretionary' => ['key' => 'x', 'type' => 'humanTask', 'discretionary' => true],
			'children' => ['key' => 'x', 'type' => 'stage', 'children' => []],
			'flow' => ['key' => 'x', 'type' => 'stage', 'flow' => 'f'],
			'bad key' => ['key' => '', 'type' => 'humanTask'],
			'bad type' => ['key' => 'x', 'type' => 'nope'],
			'ok' => ['key' => ' advice ', 'type' => 'humanTask', 'name' => 'Advies', 'discretionary' => false],
			'stage' => ['key' => 'st', 'type' => 'stage'],
		];
		foreach ($adHoc as $label => $node) {
			$out['adHoc'][$label] = self::attempt(static fn (): array => $definitions->validateAdHoc(node: $node));
		}

		$full = [
			'key' => 'check',
			'type' => CaseItem::TYPE_HUMAN_TASK,
			'name' => ' Controle ',
			'description' => 7,
			'required' => false,
			'discretionary' => true,
			'entryCriteria' => [['if' => ['var' => 'json.a']]],
			'exitCriteria' => [],
			'repetition' => ['max' => 2],
			'authorization' => ['g'],
			'candidateUsers' => ['u1'],
			'candidateGroups' => ['g1'],
			'candidateRole' => 'r',
			'dueAt' => '2026-09-04T17:00:00+02:00',
			'expiresAt' => new DateTime('2026-10-04T17:00:00+00:00'),
			'doorlooptijd' => 'P8W',
			'servicenorm' => ['x'],
		];
		$rowFrom = static fn (array $node, string $origin, ?string $flowUuid = null, ?int $flowVersion = null): mixed => self::attempt(
			static fn (): CaseItem => $definitions->rowFrom(
				placed: new CasePlanPlacement(node: $node, parentId: 9, position: 3),
				insertion: new CasePlanInsertion(
					anchor: new CaseAnchor(objectUuid: 'obj', registerId: 1, schemaId: null),
					settings: ['a' => 1],
					actor: 'alice',
					provenance: new CasePlanProvenance(flowUuid: $flowUuid, flowVersion: $flowVersion, origin: $origin)
				)
			)
		);
		$out['rowFrom']['full'] = $rowFrom($full, CaseItem::ORIGIN_DISCRETIONARY, 'f', 4);
		$out['rowFrom']['adhoc'] = $rowFrom(['key' => 'x', 'type' => 'milestone', 'name' => '  ', 'dueAt' => ''], CaseItem::ORIGIN_ADHOC);
		$out['rowFrom']['defined'] = $rowFrom(
			['key' => 'y', 'type' => 'stage', 'required' => null, 'discretionary' => 'yes'],
			CaseItem::ORIGIN_DEFINED
		);
		$out['rowFrom']['bad date'] = $rowFrom(['key' => 'x', 'type' => 'milestone', 'expiresAt' => 'not a date'], CaseItem::ORIGIN_DEFINED);

		return $out;
	}//end definitionScenario()

	/**
	 * The convergent ensurer: a fresh bring-over, a resumed one and every refusal.
	 *
	 * @return array<string, mixed>
	 */
	private function ensurerScenario(): array {
		$history = [
			['item' => 'check', 'from' => 'available', 'to' => 'active', 'at' => '2026-09-01T10:00:00+00:00', 'actor' => 'alice'],
			['item' => 'check', 'from' => 'active', 'to' => 'completed', 'at' => '2026-09-02T10:00:00.5Z', 'actor' => ' ', 'reason' => ' done '],
			['item' => ' volledig ', 'to' => 'completed', 'at' => '2026-09-02T10:00:01+0000'],
		];
		$out = [];
		$out['fresh'] = self::attempt(
			fn (): array => $this->ensurer()->ensure(request: new CaseItemsEnsureRequest(
				anchor: new CaseAnchor(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 2),
				definition: CasePlanEnsurerTest::migrated(),
				history: $history,
				actor: 'system:dossiq'
			))
		);
		$grown = CasePlanEnsurerTest::migrated();
		$grown['items'][] = ['key' => 'archief', 'type' => CaseItem::TYPE_MILESTONE, 'state' => CaseItem::STATE_COMPLETED];
		$out['resumed'] = self::attempt(
			fn (): array => $this->ensurer()->ensure(request: new CaseItemsEnsureRequest(
				anchor: new CaseAnchor(objectUuid: CaseFixtures::OBJECT, registerId: null, schemaId: 2),
				definition: $grown,
				history: [],
				actor: 'system:dossiq'
			))
		);

		$refusals = [
			'unreachable state' => [['items' => [['key' => 'm', 'type' => CaseItem::TYPE_MILESTONE, 'state' => CaseItem::STATE_ACTIVE]]], []],
			'nested unreachable state' => [
				['items' => [['key' => 's', 'type' => 'stage', 'children' => [['key' => 'm', 'type' => 'milestone', 'state' => 'enabled']]]]],
				[]
			],
			'history not object' => [['items' => [['key' => 'm', 'type' => 'milestone']]], ['x']],
			'history unknown item' => [
				['items' => [['key' => 'm', 'type' => 'milestone']]],
				[['item' => 'nope', 'to' => 'completed', 'at' => '2026-09-01T10:00:00Z']]
			],
			'history bad to' => [
				['items' => [['key' => 'm', 'type' => 'milestone']]],
				[['item' => 'm', 'to' => 'done', 'at' => '2026-09-01T10:00:00Z']]
			],
			'history bad from' => [
				['items' => [['key' => 'm', 'type' => 'milestone']]],
				[['item' => 'm', 'from' => 'x', 'to' => 'completed', 'at' => '2026-09-01T10:00:00Z']]
			],
			'history no moment' => [['items' => [['key' => 'm', 'type' => 'milestone']]], [['item' => 'm', 'to' => 'completed']]],
			'history relative moment' => [
				['items' => [['key' => 'm', 'type' => 'milestone']]],
				[['item' => 'm', 'to' => 'completed', 'at' => 'yesterday']]
			],
			'history date only' => [
				['items' => [['key' => 'm', 'type' => 'milestone']]],
				[['item' => 'm', 'to' => 'completed', 'at' => '2026-09-01']]
			],
			'history impossible moment' => [
				['items' => [['key' => 'm', 'type' => 'milestone']]],
				[['item' => 'm', 'to' => 'completed', 'at' => '2026-13-45T10:00:00Z']]
			],
			'type conflict' => [['items' => [['key' => 'besluit', 'type' => 'milestone']]], []],
			'invalid definition' => [['items' => []], []],
		];
		foreach ($refusals as $label => [$definition, $entries]) {
			$out['refusals'][$label] = self::attempt(
				fn (): array => $this->ensurer()->ensure(request: new CaseItemsEnsureRequest(
					anchor: new CaseAnchor(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 2),
					definition: $definition,
					history: $entries,
					actor: 'system:dossiq'
				))
			);
		}

		$out['anchor register'] = self::attempt(
			fn (): array => $this->ensurer()->ensure(request: new CaseItemsEnsureRequest(
				anchor: new CaseAnchor(objectUuid: CaseFixtures::OBJECT, registerId: 5, schemaId: 2),
				definition: CasePlanEnsurerTest::migrated(),
				history: [],
				actor: 'system:dossiq'
			))
		);
		$out['anchor schema'] = self::attempt(
			fn (): array => $this->ensurer()->ensure(request: new CaseItemsEnsureRequest(
				anchor: new CaseAnchor(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 7),
				definition: CasePlanEnsurerTest::migrated(),
				history: [],
				actor: 'system:dossiq'
			))
		);

		$armed = [];
		$core = $this->createMock(FlowTimerService::class);
		$core->method('arm')->willReturnCallback(
			static function (array $config, ?string $actor) use (&$armed): FlowTimer {
				$armed[] = [$config['subjectUuid'], $config['purpose'], $config['legalEffect'], $actor];

				return new FlowTimer();
			}
		);
		$store = [];
		$markers = new UnarmedTimerMarkers(appConfig: CaseFixtures::memoryAppConfig($this, $store));
		$timed = [
			'settings' => ['statutory' => ['termijn', 'oud']],
			'items' => [
				[
					'key' => 'termijn',
					'type' => CaseItem::TYPE_HUMAN_TASK,
					'state' => CaseItem::STATE_ACTIVE,
					'dueAt' => '2999-01-01T00:00:00+00:00',
					'expiresAt' => '2999-02-01T00:00:00+00:00'
				],
				['key' => 'later', 'type' => CaseItem::TYPE_HUMAN_TASK, 'expiresAt' => '2999-02-01T00:00:00+00:00'],
			],
		];
		$timers = new CasePlanItemTimers(timers: $core, logger: new NullLogger(), markers: $markers);
		$out['timed'] = self::attempt(
			fn (): array => $this->ensurer($timers)->ensure(request: new CaseItemsEnsureRequest(
				anchor: new CaseAnchor(objectUuid: self::OTHER, registerId: 1, schemaId: 2),
				definition: $timed,
				history: [],
				actor: 'system:dossiq'
			))
		);
		$timed['items'][] = [
			'key' => 'oud',
			'type' => CaseItem::TYPE_HUMAN_TASK,
			'state' => CaseItem::STATE_ACTIVE,
			'expiresAt' => '2999-03-01T00:00:00+00:00'
		];
		$out['timed again'] = self::attempt(
			fn (): array => $this->ensurer($timers)->ensure(request: new CaseItemsEnsureRequest(
				anchor: new CaseAnchor(objectUuid: self::OTHER, registerId: 1, schemaId: 2),
				definition: $timed,
				history: [],
				actor: 'system:dossiq'
			))
		);
		$out['armed'] = $armed;
		$out['marked'] = array_keys($markers->all());
		$out['rows'] = $this->rows();
		$out['audit'] = $this->auditRows();

		return $out;
	}//end ensurerScenario()

	/**
	 * Every verb of the plan service, in an order that walks a permit case.
	 *
	 * @return array<string, mixed>
	 */
	private function serviceScenario(): array {
		$writes = [];
		$service = $this->service(writes: $writes);
		$obj = CaseFixtures::OBJECT;
		$out = [];
		$out['create'] = self::attempt(
			static fn (): array => $service->createPlan(creation: new CasePlanCreation(anchor: new CaseAnchor(objectUuid: $obj, registerId: 1, schemaId: 2), definition: CasePlanDefinitionTest::permitDefinition(), actor: 'alice', provenance: new CasePlanProvenance(flowUuid: 'flow-x', flowVersion: 3)))
		);
		$out['create again'] = self::attempt(
			static fn (): array => $service->createPlan(creation: new CasePlanCreation(anchor: new CaseAnchor(objectUuid: $obj, registerId: 1, schemaId: 2), definition: CasePlanDefinitionTest::permitDefinition(), actor: 'alice'))
		);
		$out['create invalid'] = self::attempt(
			static fn (): array => $service->createPlan(creation: new CasePlanCreation(anchor: new CaseAnchor(objectUuid: self::OTHER, registerId: 1, schemaId: 2), definition: ['items' => []], actor: 'alice'))
		);
		$out['create denied'] = self::attempt(
			static fn (): array => $service->createPlan(creation: new CasePlanCreation(anchor: new CaseAnchor(objectUuid: self::OTHER, registerId: 1, schemaId: 2), definition: CasePlanDefinitionTest::permitDefinition(), actor: 'mallory'))
		);
		$out['create anonymous'] = self::attempt(
			static fn (): array => $service->createPlan(creation: new CasePlanCreation(anchor: new CaseAnchor(objectUuid: self::OTHER, registerId: 1, schemaId: 2), definition: CasePlanDefinitionTest::permitDefinition(), actor: null))
		);
		$out['read stranger'] = self::attempt(static fn (): array => $service->getPlan(objectUuid: self::OTHER, uid: 'alice'));
		$check = $this->uuidOf(object: $obj, key: 'completeness-check');
		$out['transition denied'] = self::attempt(
			static fn (): CaseItem => $service->transition(transition: new CaseItemTransition(itemUuid: $check, to: 'completed', actor: 'mallory', reason: 'x'))
		);
		$out['transition bogus'] = self::attempt(static fn (): CaseItem => $service->transition(transition: new CaseItemTransition(itemUuid: $check, to: 'bogus', actor: 'alice')));
		$out['transition illegal'] = self::attempt(static fn (): CaseItem => $service->transition(transition: new CaseItemTransition(itemUuid: $check, to: 'enabled', actor: 'alice')));
		$out['transition'] = self::attempt(
			static fn (): CaseItem => $service->transition(transition: new CaseItemTransition(itemUuid: $check, to: 'completed', actor: 'alice', reason: 'Volledig'))
		);
		$out['enableable bob'] = self::attempt(static fn (): array => $service->enableableItems(objectUuid: $obj, uid: 'bob'));
		$advice = $this->uuidOf(object: $obj, key: 'external-advice');
		$out['enable denied'] = self::attempt(static fn (): CaseItem => $service->enableDiscretionary(itemUuid: $advice, uid: 'alice'));
		$decide = $this->uuidOf(object: $obj, key: 'decide');
		$out['enable not discretionary'] = self::attempt(static fn (): CaseItem => $service->enableDiscretionary(itemUuid: $decide, uid: 'bob'));
		$out['enable'] = self::attempt(static fn (): CaseItem => $service->enableDiscretionary(itemUuid: $advice, uid: 'bob'));
		$out['enable again'] = self::attempt(static fn (): CaseItem => $service->enableDiscretionary(itemUuid: $advice, uid: 'bob'));
		$out['attach'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: $obj, data: ['key' => 'extra', 'type' => 'humanTask', 'name' => 'Extra', 'parent' => 'assessment', 'candidateUsers' => ['bob']], actor: 'alice'))
		);
		$out['attach root'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: $obj, data: ['key' => 'loose', 'type' => 'milestone'], actor: 'alice'))
		);
		$out['attach duplicate'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: $obj, data: ['key' => 'extra', 'type' => 'humanTask'], actor: 'alice'))
		);
		$out['attach under task'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: $obj, data: ['key' => 'x2', 'type' => 'humanTask', 'parent' => 'decide'], actor: 'alice'))
		);
		$out['attach unknown parent'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: $obj, data: ['key' => 'x3', 'type' => 'humanTask', 'parent' => 'nope'], actor: 'alice'))
		);
		$out['attach inactive parent'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: $obj, data: ['key' => 'x4', 'type' => 'humanTask', 'parent' => 'intake'], actor: 'alice'))
		);
		$out['attach denied'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: $obj, data: ['key' => 'x5', 'type' => 'humanTask', 'parent' => 'assessment'], actor: 'mallory'))
		);
		$out['attach denied at root'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: $obj, data: ['key' => 'x8', 'type' => 'humanTask'], actor: 'mallory'))
		);
		$out['attach invalid'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: $obj, data: ['key' => 'x6', 'type' => 'humanTask', 'discretionary' => true], actor: 'alice'))
		);
		$out['attach no plan'] = self::attempt(
			static fn (): CaseItem => $service->attachAdHoc(request: new CaseAdHocRequest(objectUuid: self::OTHER, data: ['key' => 'x7', 'type' => 'humanTask'], actor: 'alice'))
		);
		$out[
			'complete unknown result'
		] = self::attempt(static fn (): array => $service->completeCase(completion: new CaseCompletion(objectUuid: $obj, result: 'nope', actor: 'alice')));
		$out['complete open'] = self::attempt(static fn (): array => $service->completeCase(completion: new CaseCompletion(objectUuid: $obj, result: 'verleend', actor: 'alice')));
		$out['complete denied'] = self::attempt(static fn (): array => $service->completeCase(completion: new CaseCompletion(objectUuid: $obj, result: 'verleend', actor: 'mallory')));
		$out['stuck boss'] = self::attempt(
			static fn (): array => $service->findStuck(query: new ListQuery(limit: 10, offset: 0, filters: ['type' => null, 'state' => 'active']), uid: 'boss')
		);
		$out['stuck alice'] = self::attempt(static fn (): array => $service->findStuck(query: new ListQuery(limit: 10, offset: 0, filters: ['type' => null, 'state' => null]), uid: 'alice'));
		$out['evaluate'] = self::attempt(static fn (): array => $service->evaluate(objectUuid: $obj, uid: 'alice'));
		$out['timer gone'] = self::attempt(static fn (): bool => $service->onTimerExpired(itemUuid: 'nope', timerUuid: 't-0'));
		$out['timer'] = self::attempt(static fn (): bool => $service->onTimerExpired(itemUuid: $decide, timerUuid: 't-1'));
		$out['timer terminal'] = self::attempt(static fn (): bool => $service->onTimerExpired(itemUuid: $decide, timerUuid: 't-2'));
		$out['realisation'] = self::attempt(static fn (): mixed => $service->onRealisationTerminal(taskUuid: 'task-for-extra'));
		$out['object event'] = self::attempt(
			static fn (): mixed => $service->onObjectEvent(trigger: new CasePlanTrigger(objectUuid: $obj, event: 'object.updated', payload: ['automate' => true]))
		);
		$out[
			'object event no plan'
		] = self::attempt(static fn (): mixed => $service->onObjectEvent(trigger: new CasePlanTrigger(objectUuid: self::OTHER, event: 'object.updated', payload: [])));
		foreach (['extra', 'loose', 'external-advice'] as $key) {
			$uuid = $this->uuidOf(object: $obj, key: $key);
			$out['finish ' . $key] = self::attempt(static fn (): CaseItem => $service->transition(transition: new CaseItemTransition(itemUuid: $uuid, to: 'completed', actor: 'boss')));
		}

		$out['complete'] = self::attempt(static fn (): array => $service->completeCase(completion: new CaseCompletion(objectUuid: $obj, result: 'verleend', actor: 'boss')));
		$out['system bad app'] = self::attempt(
			static fn (): array => $service->createPlanAsSystem(
				write: new AppPlanWrite(
					anchor: new CaseAnchor(objectUuid: self::OTHER, registerId: 1, schemaId: 2),
					definition: CasePlanDefinitionTest::permitDefinition(),
					app: 'Bad App'
				)
			)
		);
		$out['system'] = self::attempt(
			static fn (): array => $service->createPlanAsSystem(
				write: new AppPlanWrite(
					anchor: new CaseAnchor(objectUuid: self::OTHER, registerId: 1, schemaId: 2),
					definition: CasePlanDefinitionTest::permitDefinition(),
					app: 'dossiq'
				)
			)
		);
		$out['system read'] = self::attempt(static fn (): array => $service->getPlanAsSystem(objectUuid: self::OTHER, app: 'dossiq'));
		$out['system read none'] = self::attempt(static fn (): array => $service->getPlanAsSystem(objectUuid: self::THIRD, app: 'dossiq'));
		$out['ensure'] = self::attempt(
			static fn (): array => $service->ensureItems(
				objectUuid: self::THIRD,
				registerId: 1,
				schemaId: 2,
				definition: CasePlanEnsurerTest::migrated(),
				history: [],
				app: 'dossiq'
			)
		);
		$out['ensure bad app'] = self::attempt(
			static fn (): array => $service->ensureItems(
				objectUuid: self::THIRD,
				registerId: 1,
				schemaId: 2,
				definition: CasePlanEnsurerTest::migrated(),
				history: [],
				app: '9x'
			)
		);
		$out['read invisible'] = self::attempt(static fn (): array => $service->getPlan(objectUuid: self::THIRD, uid: 'alice'));
		$out['read invisible admin'] = self::attempt(static fn (): array => $service->getPlan(objectUuid: self::THIRD, uid: 'boss'));
		$out['read anonymous'] = self::attempt(static fn (): array => $service->getPlan(objectUuid: $obj, uid: null));
		$out['delete denied'] = self::attempt(static fn (): int => $service->deletePlan(objectUuid: self::OTHER, uid: 'mallory'));
		$out['delete'] = self::attempt(static fn (): int => $service->deletePlan(objectUuid: self::OTHER, uid: 'boss'));
		$out['delete none'] = self::attempt(static fn (): int => $service->deletePlan(objectUuid: self::OTHER, uid: 'boss'));
		$out['writes'] = $writes;
		$out['rows'] = $this->rows();
		$out['audit'] = $this->auditRows();

		return $out;
	}//end serviceScenario()

	/**
	 * The two flow nodes: their description, their form and what they do.
	 *
	 * @return array<string, mixed>
	 */
	private function nodesScenario(): array {
		$writes = [];
		$scope = $this->createMock(FlowRunAsScope::class);
		$scope->method('call')->willReturnCallback(static fn (array $context, callable $operation): mixed => $operation());
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/icon.svg');
		$collaborators = ['steps' => new CaseStepRunner(plans: $this->service(writes: $writes), scope: $scope, session: $session), 'presentation' => new \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation($l10n, $urls)];
		$open = new CaseOpenNode(...$collaborators);
		$advance = new CaseAdvanceNode(...$collaborators);
		$out = [];
		foreach (['open' => $open, 'advance' => $advance] as $label => $node) {
			$out['describe'][$label] = [
				$node->getId(),
				$node->getDisplayName(),
				$node->getDescription(),
				$node->configKeys(),
				$node->configForm(),
				$node->getIcon(),
				$node->getKind(),
				$node->getCategory(),
				$node->isAvailableForScope(scope: 0),
				$node->isAvailableForScope(scope: 7)
			];
		}

		$definition = [
			'settings' => ['authorization' => ['demo-behandelaars']],
			'items' => [
				['key' => 'intake', 'type' => CaseItem::TYPE_HUMAN_TASK, 'name' => 'Intake', 'repetition' => ['max' => 3]],
				[
					'key' => 'besluit',
					'type' => CaseItem::TYPE_MILESTONE,
					'entryCriteria' => [['on' => ['event' => 'case.item.completed', 'item' => 'intake']]]
				],
			],
		];
		$runAs = [FlowRunServiceInterface::RUN_AS_CONTEXT_KEY => 'alice'];
		$items = [
			7 => [FlowItems::JSON => ['uuid' => CaseFixtures::OBJECT, 'other' => self::OTHER, '@self' => ['register' => '1', 'schema' => 2]]],
			9 => [FlowItems::JSON => ['id' => CaseFixtures::OBJECT, '@self' => 'x']],
			11 => [FlowItems::JSON => 'x', 'extra' => true],
		];
		$out['open no items'] = self::attempt(static fn (): array => $open->execute(new FlowNodeCall(items: [], config: [], context: [])));
		$out['open bad config'] = self::attempt(
			static fn (): array => $open->execute(new FlowNodeCall(items: $items, config: ['definition' => ['items' => []]], context: $runAs))
		);
		$out['open nobody'] = self::attempt(static fn (): array => $open->execute(new FlowNodeCall(items: $items, config: ['definition' => $definition], context: [])));
		$out['open no object'] = self::attempt(
			static fn (): array => $open->execute(new FlowNodeCall(items: $items, config: ['definition' => $definition], context: $runAs))
		);
		$two = [7 => $items[7], 9 => $items[9]];
		$out['open'] = self::attempt(static fn (): array => $open->execute(new FlowNodeCall(items: $two, config: ['definition' => $definition], context: $runAs)));
		$out['open again'] = self::attempt(
			static fn (): array => $open->execute(new FlowNodeCall(items: $two, config: ['definition' => $definition], context: $runAs))
		);
		$out['open other'] = self::attempt(
			static fn (): array => $open->execute(new FlowNodeCall(items: [$items[7]], config: ['definition' => $definition, 'uuid' => '{{ other }}'], context: $runAs))
		);
		$out['advance no key'] = self::attempt(static fn (): array => $advance->execute(new FlowNodeCall(items: $two, config: ['to' => 'completed'], context: $runAs)));
		$out['advance bad state'] = self::attempt(
			static fn (): array => $advance->execute(new FlowNodeCall(items: $two, config: ['item' => 'intake', 'to' => 'done'], context: $runAs))
		);
		$out['advance unknown'] = self::attempt(
			static fn (): array => $advance->execute(new FlowNodeCall(items: $two, config: ['item' => 'nope', 'to' => 'completed'], context: $runAs))
		);
		$out['advance'] = self::attempt(
			static fn (): array => $advance->execute(new FlowNodeCall(
				items: $two,
				config: ['item' => 'intake', 'to' => 'completed', 'reason' => ' klaar '],
				context: $runAs
			))
		);
		$out['advance repeat'] = self::attempt(
			static fn (): array => $advance->execute(new FlowNodeCall(
				items: $two,
				config: ['item' => 'intake', 'to' => 'completed', 'reason' => '  '],
				context: $runAs
			))
		);
		$out['advance other'] = self::attempt(
			static fn (): array => $advance->execute(new FlowNodeCall(
				items: [$items[7]],
				config: ['item' => 'intake', 'to' => 'terminated', 'uuid' => '{{ other }}'],
				context: $runAs
			))
		);
		$out['rows'] = $this->rows();

		return $out;
	}//end nodesScenario()

	/**
	 * The task data a human item becomes, and how runs are queued.
	 *
	 * @return array<string, mixed>
	 */
	private function realisationScenario(): array {
		$queued = [];
		$runs = $this->createMock(FlowRunServiceInterface::class);
		$runs->method('queueRun')->willReturnCallback(
			static function (string $flowId, FlowRunQueueOptions $options) use (&$queued): \OCA\OpenRegister\Db\FlowRun {
				$queued[] = [$flowId, $options->subject, $options->trigger, $options->context, $options->user];
				$run = new \OCA\OpenRegister\Db\FlowRun();
				$run->setUuid('run-' . count($queued));

				return $run;
			}
		);
		$tasks = $this->createMock(TaskServiceInterface::class);
		$imported = [];
		$tasks->method('import')->willReturnCallback(
			static function (array $data, string $actor) use (&$imported): Task {
				$imported[] = [$data, $actor];
				$task = new Task();
				$task->setUuid('task-' . count($imported));

				return $task;
			}
		);
		$service = new CaseRealisationService(
			tasks: new CaseRealisationTasks(tasks: $tasks, taskRows: $this->createMock(TaskMapper::class)),
			runs: new CaseRealisationRuns(runs: $runs, runRows: $this->createMock(FlowRunMapperInterface::class)),
			logger: new NullLogger()
		);
		$bare = CaseFixtures::row(id: 1, key: 'bare', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$full = CaseFixtures::row(id: 2, key: 'full', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$full->setName(null);
		$full->setDescription('Beschrijving');
		$full->setCandidateUsers(['u1', 'u2']);
		$full->setCandidateGroups(['g1']);
		$full->setCandidateRole(' role ');
		$full->setDueAt(new DateTime('2026-09-04T17:00:00+02:00'));
		$full->setExpiresAt(new DateTime('2026-10-04T17:00:00+00:00'));
		$full->setDoorlooptijd('P8W');
		$full->setServicenorm('P6W');
		$full->setCreatedBy('carol');
		$groups = CaseFixtures::row(id: 3, key: 'groups', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$groups->setCandidateGroups(['g1']);
		$role = CaseFixtures::row(id: 4, key: 'role', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$role->setCandidateRole('behandelaar');
		$out = [];
		foreach ([$bare, $full, $groups, $role] as $row) {
			$out['taskData'][(string)$row->getItemKey()] = $service->taskDataFor(item: $row, actor: 'alice');
			$service->realise(item: $row, actor: 'alice');
			$out['realised'][(string)$row->getItemKey()] = [$row->getRealisationKind(), $row->getRealisationUuid()];
		}

		$stage = CaseFixtures::row(id: 5, key: 'stage', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_AVAILABLE);
		$stage->setPlanSettings(['flows' => ['stage' => ' flow-1 ', 'proc' => 'flow-2']]);
		$proc = CaseFixtures::row(id: 6, key: 'proc', type: CaseItem::TYPE_PROCESS_TASK, state: CaseItem::STATE_AVAILABLE);
		$proc->setPlanSettings(['flows' => ['proc' => 'flow-2']]);
		$milestone = CaseFixtures::row(id: 7, key: 'proc', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		$milestone->setPlanSettings(['flows' => ['proc' => 'flow-2']]);
		foreach (['alice' => $stage, 'system:dossiq' => $proc, 'case-plan' => $milestone] as $actor => $row) {
			$service->realise(item: $row, actor: (string)$actor);
			$out['realised'][(string)$row->getItemKey() . ' by ' . $actor] = [$row->getRealisationKind(), $row->getRealisationUuid()];
		}

		$out['queued'] = $queued;
		$out['imported'] = $imported;

		return $out;
	}//end realisationScenario()

	/**
	 * The timers an item arms, what a refusal logs and what is marked.
	 *
	 * @return array<string, mixed>
	 */
	private function timersScenario(): array {
		$now = new DateTimeImmutable('2026-10-10T10:00:00+00:00');
		$armed = [];
		$refuse = false;
		$core = $this->createMock(FlowTimerService::class);
		$core->method('arm')->willReturnCallback(
			static function (array $config, ?string $actor) use (&$armed, &$refuse): FlowTimer {
				$armed[] = [$config, $actor];
				if ($refuse === true) {
					throw new RuntimeException('clock refused');
				}

				return new FlowTimer();
			}
		);
		$log = new class extends AbstractLogger {
			/**
			 * What was logged.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $lines = [];

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, array_keys($context)];
			}
		};
		$store = [];
		$markers = new UnarmedTimerMarkers(appConfig: CaseFixtures::memoryAppConfig($this, $store));
		$timers = new CasePlanItemTimers(timers: $core, logger: $log, markers: $markers);
		$item = static function (int $id, string $key, ?string $due, ?string $expires, array $statutory): CaseItem {
			$row = CaseFixtures::row(id: $id, key: $key, type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
			$row->setDueAt(self::dateOrNull(value: $due));
			$row->setExpiresAt(self::dateOrNull(value: $expires));
			$row->setPlanSettings(['statutory' => $statutory]);

			return $row;
		};
		$rows = [
			'both statutory' => $item(1, 'a', '2026-10-12T10:30:00+00:00', '2026-11-10T10:00:00+00:00', ['a']),
			'long expiry' => $item(2, 'b', null, '2027-12-10T10:00:01+00:00', []),
			'short' => $item(3, 'c', '2026-10-10T10:00:01+00:00', null, []),
			'past statutory' => $item(4, 'd', '2026-10-01T10:00:00+00:00', '2026-10-01T10:00:00+00:00', ['d']),
			'none' => $item(5, 'e', null, null, ['e']),
		];
		$out = [];
		foreach ($rows as $label => $row) {
			$out['entered'][$label] = $timers->onEntered(item: $row, act: new FlowTimerAct(actor: 'alice', now: DateTimeImmutable::createFromInterface($now)));
			$out['rearm'][$label] = $timers->rearmStatutoryExpiry(item: $row, act: new FlowTimerAct(actor: null, now: $now));
			$out['mark'][$label] = $timers->markIfStatutory(item: $row);
		}

		$refuse = true;
		$out['refused'] = $timers->onEntered(item: $rows['both statutory'], act: new FlowTimerAct(actor: null, now: DateTimeImmutable::createFromInterface($now)));
		$out['refused rearm'] = $timers->rearmStatutoryExpiry(item: $rows['both statutory'], act: new FlowTimerAct(actor: 'bob', now: $now));
		$out['armed'] = $armed;
		$out['marked'] = array_keys($markers->all());
		$out['log'] = $log->lines;

		return $out;
	}//end timersScenario()
}//end class

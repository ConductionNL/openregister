<?php

/**
 * Golden test for the rules engine's services: trial, switch, replay, inventory, run log, validations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use DateTime;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RuleRun;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunSummary;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcer;
use OCA\OpenRegister\Service\Rules\AdministeredValidationRunLog;
use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\NamedConditionEvaluator;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCA\OpenRegister\Service\Rules\RuleAuditService;
use OCA\OpenRegister\Service\Rules\RuleCeilingService;
use OCA\OpenRegister\Service\Rules\RuleEnablementService;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleReplayService;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleTrialService;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\Rules\Support\AdministeredValidationChecks;
use OCA\OpenRegister\Service\Rules\Support\RuleTrialEngine;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Pins every outcome of the rules services over doubles for their stores,
 * compared with fixtures/rules-services-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class RulesServicesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/rules-services-golden.json';

	/**
	 * Calls the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('bezwaar');
		$schema->setProperties(
			[
				'bedrag' => ['type' => 'number'],
				'termijn' => ['type' => 'string', PropertyCalculations::PROPERTY_KEY => ['expression' => ['ok' => 'termijn']]],
			]
		);
		$schema->setConfiguration(
			[
				'x-openregister-calculations' => [
					'uiterlijkeDatum' => ['type' => 'date', 'expression' => ['ok' => 1], 'maxObjects' => 3, 'scope' => ['property' => 'zaaktype', 'value' => 'a']],
					'kapot' => ['expression' => ['throw' => 1], 'enabled' => false, 'maxObjects' => 0],
					'geen' => 'scalar',
				],
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'states' => [
						'open' => ['fields' => ['bedrag' => ['readOnly' => true]], 'condition' => ['==' => [1, 1]], 'maxObjects' => 9],
						'dicht' => [],
						'leeg' => ['fields' => 'x'],
					],
					'transitions' => [
						'sluiten' => ['label' => 'Sluiten', 'condition' => ['>' => [['var' => 'object.bedrag'], 500]], 'enabled' => false],
						'openen' => ['to' => 'open'],
						'ast' => ['condition' => ['gt' => [['prop' => 'object.bedrag'], 10]]],
					],
				],
			]
		);
		return $schema;
	}//end schema()

	private function inventory(): RuleInventoryService {
		$triggers = $this->createMock(FlowTriggerMapper::class);
		$triggers->method('findBySchema')->willReturn(
			[
				['flow_uuid' => 'flow-b', 'event' => 'update', 'register' => 'r', 'enabled' => false],
				['flow_uuid' => 'flow-a', 'event' => 'update', 'register' => 'r', 'enabled' => false],
				['flow_uuid' => 'flow-a', 'event' => 'create', 'register' => 'r', 'enabled' => true],
				['flow_uuid' => 'flow-a', 'event' => 'update', 'register' => 'r', 'enabled' => false],
				['flow_uuid' => 'flow-c', 'event' => 'delete', 'register' => 'r', 'enabled' => true],
			]
		);
		$summary = new RuleRunSummary();
		$summary->setRuleId('calculation:bezwaar:uiterlijkeDatum');
		$summary->setSchemaSlug('elders');
		$summary->setLastRun(new DateTime('2026-09-10T00:00:00+00:00'));
		$summary->setLastVerdict('fired');
		$old = new RuleRunSummary();
		$old->setRuleId('flow:bezwaar:flow-a');
		$old->setSchemaSlug('bezwaar');
		$old->setLastRun(new DateTime('2026-01-01T00:00:00+00:00'));
		$old->setLastVerdict('error');
		$old->setLastError('kapot');
		$old->setLastErrorAt(new DateTime('2026-01-01T00:00:00+00:00'));
		$summaries = $this->createMock(RuleRunSummaryMapper::class);
		$summaries->method('findBySchema')->willReturn(
			['calculation:bezwaar:uiterlijkeDatum' => $summary, 'flow:bezwaar:flow-a' => $old]
		);
		$published = $this->createMock(FlowPublishedGraph::class);
		$published->method('graphOf')->willReturnCallback(
			static fn (string $flowId): ?array => [
				'flow-a' => [
					'nodes' => [
						'x',
						['type' => 'other', 'config' => ['scope' => ['property' => 'zaaktype', 'value' => 'a']]],
						['type' => FlowTriggerDerivation::OBJECT_TRIGGER, 'config' => ['schema' => 'elders', 'scope' => ['property' => 'zaaktype', 'value' => 'b']]],
						['type' => FlowTriggerDerivation::OBJECT_TRIGGER, 'config' => ['schema' => 'bezwaar', 'scope' => ['property' => 'zaaktype', 'value' => 'c']]],
					],
				],
				'flow-b' => [
					'nodes' => [['type' => FlowTriggerDerivation::OBJECT_TRIGGER, 'config' => ['scope' => ['property' => 'zaaktype', 'value' => 'd']]]],
				],
				'flow-c' => ['nodes' => [['type' => FlowTriggerDerivation::OBJECT_TRIGGER, 'config' => []]]],
			][$flowId] ?? null
		);
		return new RuleInventoryService(
			triggers: $triggers,
			summaries: $summaries,
			propertyCalculations: new PropertyCalculations(),
			vocabulary: new RuleVocabulary(),
			published: $published
		);
	}//end inventory()

	public function testInventory(): void {
		$inventory = $this->inventory();
		$schema = $this->schema();
		$now = new DateTime('2026-09-14T00:00:00+00:00');
		$actual = [
			'describe' => array_map(static fn ($rule): array => $rule->jsonSerialize(), $inventory->describe($schema)),
			'window30' => $inventory->inventory($schema, 30, $now),
			'window0' => $inventory->inventory($schema, 0, $now),
			'scoped' => $inventory->inventory($schema, 365, $now, ['zaaktype' => 'c']),
			'find' => $inventory->find($schema, 'flow:bezwaar:flow-b')?->jsonSerialize(),
			'missing' => $inventory->find($schema, 'flow:bezwaar:nope'),
		];
		$empty = new Schema();
		$actual['empty'] = $inventory->describe($empty);
		$this->assertGolden('inventory', $actual);
	}//end testInventory()

	private function trialService(): RuleTrialService {
		$evaluator = $this->createMock(CalculationEvaluatorInterface::class);
		$evaluator->method('evaluate')->willReturnCallback(
			static function (array $data, mixed $node): mixed {
				if (is_array($node) === true && isset($node['throw']) === true) {
					throw new EvaluationException('kapot');
				}

				return ['evaluated' => $node, 'on' => $data];
			}
		);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if ($id === 'missing') {
					throw new RuntimeException('no register');
				}

				return new Register();
			}
		);
		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('findInRegisterSchemaTable')->willReturnCallback(
			static function (string $identifier): ObjectEntity {
				if ($identifier === 'gone') {
					throw new RuntimeException('no object');
				}

				$object = new ObjectEntity();
				$object->setUuid('uuid-' . $identifier);
				$object->setObject(['object' => ['bedrag' => 900], 'bedrag' => 900]);
				return $object;
			}
		);
		$dialect = new ConditionDialect(
			ast: new CalculationEvaluator(placeholders: new PlaceholderResolver(userSession: $this->createMock(IUserSession::class)))
		);
		return new RuleTrialService(
			inventory: $this->inventory(),
			engine: new RuleTrialEngine(evaluator: $evaluator, tracer: new ConditionTracer(dialect: $dialect), dialect: $dialect),
			registerMapper: $registers,
			objectMapper: $objects
		);
	}//end trialService()

	public function testTrial(): void {
		$trials = $this->trialService();
		$schema = $this->schema();
		$high = ['bedrag' => 600];
		$low = ['bedrag' => 1];
		$draft = ['expression' => ['ok' => 'draft']];
		$actual = [
			'calculation' => $trials->trySample($schema, 'calculation:bezwaar:uiterlijkeDatum', ['a' => 1]),
			'property calculation' => $trials->trySample($schema, 'calculation:bezwaar:termijn', []),
			'calculation throws' => $trials->trySample($schema, 'calculation:bezwaar:kapot', []),
			'condition holds' => $trials->trySample($schema, 'lifecycleCondition:bezwaar:sluiten', $high),
			'condition fails' => $trials->trySample($schema, 'lifecycleCondition:bezwaar:sluiten', $low),
			'ast condition' => $trials->trySample($schema, 'lifecycleCondition:bezwaar:ast', $low),
			'state rule' => $trials->trySample($schema, 'stateFieldRule:bezwaar:open', []),
			'flow' => $trials->trySample($schema, 'flow:bezwaar:flow-a', []),
			'unknown' => $trials->trySample($schema, 'calculation:bezwaar:nope', []),
			'unknown draft' => $trials->trySample($schema, 'calculation:bezwaar:nope', [], $draft),
			'draft on condition' => $trials->trySample($schema, 'lifecycleCondition:bezwaar:sluiten', $high, ['expression' => ['==' => [1, 1]]]),
			'override without expression' => $trials->trySample($schema, 'calculation:bezwaar:nope', [], ['x' => 1]),
			'object' => $trials->tryObject($schema, 'lifecycleCondition:bezwaar:sluiten', 'reg', 'o1'),
			'object calculation draft' => $trials->tryObject($schema, 'calculation:bezwaar:geen', 'reg', 'o2', $draft),
			'object register missing' => $trials->tryObject($schema, 'calculation:bezwaar:uiterlijkeDatum', 'missing', 'o1'),
			'object gone' => $trials->tryObject($schema, 'calculation:bezwaar:uiterlijkeDatum', 'reg', 'gone'),
			'object unknown rule' => $trials->tryObject($schema, 'calculation:bezwaar:nope', 'missing', 'gone'),
		];
		$this->assertGolden('trial', $actual);
	}//end testTrial()

	private function enablement(bool $failWrite = false): RuleEnablementService {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object) use ($failWrite): Schema {
				$this->calls[] = ['updateFromArray', $id, $object];
				if ($failWrite === true) {
					throw new RuntimeException('schema not saved');
				}

				$schema = $this->schema();
				$schema->setConfiguration($object['configuration']);
				$schema->setProperties($object['properties']);
				return $schema;
			}
		);
		$flows = $this->createMock(FlowService::class);
		$flows->method('save')->willReturnCallback(
			function (array $data, ?string $uuid = null): \OCA\OpenRegister\Db\Flow {
				$this->calls[] = ['flowSave', $data, $uuid];
				return new \OCA\OpenRegister\Db\Flow();
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$user->method('getDisplayName')->willReturn('Jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new RuleEnablementService(
			inventory: $this->inventory(),
			schemaMapper: $schemas,
			flows: $flows,
			audit: new RuleAuditService(userSession: $session, logger: $this->createMock(LoggerInterface::class))
		);
	}//end enablement()

	public function testEnablement(): void {
		$schema = $this->schema();
		$actual = [];
		$cases = [
			'calculation off' => ['calculation:bezwaar:uiterlijkeDatum', false],
			'calculation on' => ['calculation:bezwaar:kapot', true],
			'property calculation' => ['calculation:bezwaar:termijn', false],
			'state rule' => ['stateFieldRule:bezwaar:open', false],
			'condition' => ['lifecycleCondition:bezwaar:sluiten', true],
			'flow' => ['flow:bezwaar:flow-a', false],
			'unknown' => ['calculation:bezwaar:nope', true],
		];
		foreach ($cases as $name => [$ruleId, $enabled]) {
			$this->calls = [];
			$actual[$name] = ['outcome' => $this->enablement()->setEnabled($schema, $ruleId, $enabled), 'calls' => $this->calls];
		}

		$this->calls = [];
		$actual['write fails'] = [
			'outcome' => $this->enablement(failWrite: true)->setEnabled($schema, 'calculation:bezwaar:kapot', true),
			'calls' => $this->calls,
		];
		$this->assertGolden('enablement', $actual);
	}//end testEnablement()

	private function replay(int|Throwable $count): RuleReplayService {
		$jobs = $this->createMock(BulkJobService::class);
		$jobs->method('create')->willReturnCallback(
			function (...$args): BulkJob {
				$this->calls[] = ['create', $args];
				$job = $this->createMock(BulkJob::class);
				$job->method('jsonSerialize')->willReturn(['previewed' => $args[0]]);
				return $job;
			}
		);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('countSearchObjects')->willReturnCallback(
			function (array $query = []) use ($count): int {
				$this->calls[] = ['count', $query];
				if ($count instanceof Throwable) {
					throw $count;
				}

				return $count;
			}
		);
		return new RuleReplayService(inventory: $this->inventory(), ceiling: new RuleCeilingService(), jobs: $jobs, objects: $objects);
	}//end replay()

	public function testReplay(): void {
		$schema = $this->schema();
		$noSlug = $this->schema();
		$noSlug->setSlug(null);
		$query = ['query' => ['_limit' => 5, '_page' => 2, 'status' => 'open', '@self' => ['owner' => 'x', 'register' => 99]]];
		$cases = [
			'ids' => [2, $schema, 'calculation:bezwaar:uiterlijkeDatum', ['ids' => [1, '1', 2]], 4],
			'query' => [2, $schema, 'calculation:bezwaar:uiterlijkeDatum', $query, null],
			'query self not array' => [1, $schema, 'calculation:bezwaar:termijn', ['query' => ['@self' => 'x']], 4],
			'query plain' => [1, $schema, 'calculation:bezwaar:termijn', ['query' => ['a' => 1]], null],
			'over ceiling' => [4, $schema, 'calculation:bezwaar:uiterlijkeDatum', ['ids' => [1, 2, 3, 4]], 4],
			'count fails' => [new RuntimeException('db'), $schema, 'calculation:bezwaar:uiterlijkeDatum', $query, 4],
			'no selection' => [1, $schema, 'calculation:bezwaar:uiterlijkeDatum', ['ids' => 'x'], 4],
			'condition' => [1, $schema, 'lifecycleCondition:bezwaar:sluiten', ['ids' => [1]], 4],
			'unknown' => [1, $schema, 'calculation:bezwaar:nope', ['ids' => [1]], 4],
			'unknown without slug' => [1, $noSlug, 'calculation:bezwaar:nope', ['ids' => [1]], 4],
		];
		$actual = [];
		foreach ($cases as $name => [$count, $target, $ruleId, $selection, $registerId]) {
			$this->calls = [];
			try {
				$outcome = $this->replay($count)->preview($target, $ruleId, $selection, 'jan', 'reden', $registerId);
			} catch (Throwable $e) {
				$outcome = [get_class($e), $e->getMessage()];
			}

			$actual[$name] = ['outcome' => $outcome, 'calls' => $this->calls];
		}

		$this->assertGolden('replay', $actual);
	}//end testReplay()

	private function recorder(string $detail, bool $failSummary = false, bool $failInsert = false): RuleRunRecorder {
		$runs = $this->createMock(RuleRunMapper::class);
		$runs->method('insert')->willReturnCallback(
			function (RuleRun $run) use ($failInsert): RuleRun {
				$this->calls[] = ['insert', $run->jsonSerialize()];
				if ($failInsert === true) {
					throw new RuntimeException('insert failed');
				}

				return $run;
			}
		);
		$summaries = $this->createMock(RuleRunSummaryMapper::class);
		$summaries->method('record')->willReturnCallback(
			function (...$args) use ($failSummary): RuleRunSummary {
				$args[3] = $args[3]->format(DATE_ATOM);
				$this->calls[] = ['summary', $args];
				if ($failSummary === true) {
					throw new RuntimeException('summary failed');
				}

				return new RuleRunSummary();
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($user): IUser {
				$this->calls[] = ['getUser'];
				return $user;
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => [
				RuleRunRecorder::CONFIG_ENABLED => $detail,
				RuleRunRecorder::CONFIG_RETENTION_DAYS => $detail,
			][$key] ?? $default
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['warning', $message, array_keys($context)];
			}
		);
		return new RuleRunRecorder(runs: $runs, summaries: $summaries, userSession: $session, config: $config, logger: $logger);
	}//end recorder()

	public function testRecorder(): void {
		$moment = new DateTime('2026-03-04T05:06:07+00:00');
		$long = str_repeat('ab', 200);
		$traces = [
			'refused' => new RuleTrace('refused', $long, 'v', 'said'),
			'error' => RuleTrace::errored('kapot'),
			'fired' => RuleTrace::fired(),
		];
		$actual = [];
		foreach (['', 'off', 'yes', 'FALSE', '12'] as $detail) {
			foreach ($traces as $name => $trace) {
				$this->calls = [];
				$this->recorder($detail)->record('r:s:k', 's', $trace, 'uuid', 'reg', $moment);
				$actual[$detail][$name] = $this->calls;
			}

			$recorder = $this->recorder($detail);
			$actual[$detail]['detailEnabled'] = $recorder->detailEnabled();
			$actual[$detail]['retentionDays'] = $recorder->retentionDays();
		}

		foreach ([[true, false], [false, true], [true, true]] as [$failSummary, $failInsert]) {
			$this->calls = [];
			$this->recorder('', $failSummary, $failInsert)->record('r:s:k', 's', $traces['error'], null, null, $moment);
			$actual['failing'][] = $this->calls;
		}

		$this->calls = [];
		$this->recorder('')->record('r:s:k', 's', $traces['fired']);
		$actual['defaults'] = array_map(
			static function (array $call): array {
				unset($call[1]['created'], $call[1][3]);
				return $call;
			},
			$this->calls
		);
		$this->assertGolden('recorder', $actual);
	}//end testRecorder()

	private function enforcer(?Schema $schema, bool $failRecord = false): AdministeredValidationEnforcer {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id) use ($schema): Schema {
				$this->calls[] = ['findSchema', $id];
				if ($schema === null) {
					throw new RuntimeException('no schema');
				}

				return $schema;
			}
		);
		$recorder = $this->createMock(RuleRunRecorder::class);
		$recorder->method('record')->willReturnCallback(
			function (...$args) use ($failRecord): void {
				$args[2] = $args[2]->jsonSerialize();
				$this->calls[] = ['record', $args];
				if ($failRecord === true) {
					throw new RuntimeException('record failed');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['warning', $message];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');
		$library = new NamedConditionLibrary();
		$dialect = new ConditionDialect(
			ast: new CalculationEvaluator(placeholders: new PlaceholderResolver(userSession: $this->createMock(IUserSession::class)))
		);
		return new AdministeredValidationEnforcer(
			schemaMapper: $schemas,
			checks: new AdministeredValidationChecks(
				validations: new AdministeredValidations(evaluator: new NamedConditionEvaluator(dialect: $dialect, library: $library)),
				conditions: $library,
				l10n: $l10n
			),
			runLog: new AdministeredValidationRunLog(ruleRuns: $recorder, logger: $logger),
			logger: $logger
		);
	}//end enforcer()

	private function validatedSchema(?string $slug = 'bezwaar'): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setConfiguration(
			[
				NamedConditionLibrary::ANNOTATION => [
					'groot' => ['expression' => ['>' => [['var' => 'bedrag'], 100]]],
				],
				AdministeredValidations::ANNOTATION => [
					'mandaat' => [
						'severity' => AdministeredValidations::REFUSE,
						'properties' => ['mandaat', 'bedrag'],
						'condition' => ['and' => [[NamedConditionLibrary::REF => 'groot'], ['==' => [['var' => 'mandaat'], '']]]],
						'message' => ['nl' => 'Mandaat verplicht', 'en' => 'Mandate required'],
					],
					'gewijzigd' => [
						'severity' => AdministeredValidations::WARN,
						'condition' => ['!=' => [['var' => '$before.status'], ['var' => 'status']]],
						'message' => 'Status changed',
					],
					'kapot' => [
						'severity' => AdministeredValidations::WARN,
						'condition' => [NamedConditionLibrary::REF => 'ontbreekt'],
						'message' => 'Never',
					],
				],
			]
		);
		return $schema;
	}//end validatedSchema()

	public function testEnforcer(): void {
		$object = static function (?string $schemaRef, array $data): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setSchema($schemaRef);
			$entity->setUuid('uuid-1');
			$entity->setRegister('reg');
			$entity->setObject($data);
			return $entity;
		};
		$refused = ['bedrag' => 500, 'mandaat' => '', 'status' => 'b'];
		$passes = ['bedrag' => 5, 'mandaat' => 'm', 'status' => 'a'];
		$noValidations = new Schema();
		$noValidations->setSlug('x');
		$noValidations->setConfiguration([AdministeredValidations::ANNOTATION => []]);
		$cases = [
			'no schema ref' => [$this->validatedSchema(), $object(null, $refused), null, false],
			'empty schema ref' => [$this->validatedSchema(), $object('', $refused), null, false],
			'schema unresolvable' => [null, $object('7', $refused), null, false],
			'no validations' => [$noValidations, $object('7', $refused), null, false],
			'refused on create' => [$this->validatedSchema(), $object('7', $refused), null, false],
			'refused on update' => [$this->validatedSchema(), $object('7', $refused), $object('7', $passes), false],
			'passes on update' => [$this->validatedSchema(), $object('7', $passes), $object('7', $refused), false],
			'no slug' => [$this->validatedSchema(null), $object('7', $refused), null, false],
			'record fails' => [$this->validatedSchema(), $object('7', $refused), $object('7', $passes), true],
		];
		$actual = [];
		foreach ($cases as $name => [$schema, $new, $old, $failRecord]) {
			$this->calls = [];
			$outcome = $this->enforcer($schema, $failRecord)->refusalFor($new, $old);
			$actual[$name] = ['outcome' => $outcome, 'calls' => $this->calls];
		}

		$this->assertGolden('enforcer', $actual);
	}//end testEnforcer()

	/**
	 * Compare an outcome with its recording, or record it under UPDATE_GOLDEN=1.
	 *
	 * @param string $name   The recording's key.
	 * @param mixed  $actual The outcome.
	 *
	 * @return void
	 */
	private function assertGolden(string $name, mixed $actual): void {
		$actual = json_decode((string)json_encode($actual, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
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

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class

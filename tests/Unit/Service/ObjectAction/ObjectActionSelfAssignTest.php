<?php

/**
 * A declared self-assign action, run through the real gate and the real permission rules.
 *
 * Only the write (ObjectService) and the platform services are doubles, so a
 * grant, a matrix row or a missing rule decides exactly as it does on an
 * instance.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/declared-self-assign-action/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\EmptyPropertyClaim;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\MatrixRowsRequestedEvent;
use OCA\OpenRegister\Exception\PropertyAlreadySetException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\IDBConnection;
use ReflectionMethod;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionAudit;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationEntries;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionFlowEngines;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionFlowRun;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunRecorder;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunnerChecks;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSelfAssign;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionStepsFlow;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionWriters;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Flow\MacroActionValidator;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Who may take an unassigned object, and what the action may write.
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AuthorizationBlockCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SubjectDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Service\Object\RefLoopGuard
 * @uses \OCA\OpenRegister\Service\Object\RefLoopGuardParser
 */
class ObjectActionSelfAssignTest extends TestCase {

	/**
	 * The action key the case schema declares.
	 *
	 * @var string
	 */
	private const ACTION = 'take';

	/**
	 * Group memberships by uid.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const GROUPS = [
		'anna' => ['team'],
		'bram' => ['others'],
		'root' => ['admin'],
		'olga' => [],
	];

	/**
	 * What patchObject() was asked to write, per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * What the audit recorded, per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $audited = [];

	/**
	 * The signed-in uid.
	 *
	 * @var string
	 */
	private string $uid = 'anna';

	/**
	 * The object grants (shares) the permission handler reads, or null for none.
	 *
	 * @var ObjectGrantResolver|null
	 */
	private ?ObjectGrantResolver $grants = null;

	/**
	 * How often the app matrix rows were asked for.
	 *
	 * @var int
	 */
	private int $rowRequests = 0;

	/**
	 * The stored row's values, as the conditional write sees them.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	/**
	 * Matrix rows the provider app answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $appRows = [];

	/**
	 * A case schema that declares the self-assign action and carries the given block.
	 *
	 * @param array<string, mixed> $authorization The authorization block.
	 *
	 * @return Schema
	 */
	private static function schema(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('case');
		$schema->setProperties(['assignee' => ['type' => 'string'], 'group' => ['type' => 'string'], 'title' => ['type' => 'string']]);
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					self::ACTION => ['name' => 'Take', 'description' => 'Assign the case to yourself.', 'selfAssign' => ['field' => 'assignee']],
				],
			]
		);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * A case object.
	 *
	 * @param string|null $assignee The current assignee.
	 *
	 * @return ObjectEntity
	 */
	private static function object(?string $assignee): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('5b0c1f0e-0000-4000-8000-000000000001');
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setOwner('olga');
		$object->setObject(['assignee' => $assignee, 'group' => 'team', 'title' => 'A case']);

		return $object;
	}//end object()

	/**
	 * The read-only grant plus the action granted to `team`.
	 *
	 * @return array<string, mixed>
	 */
	private static function readOnlyTeam(): array {
		return ['read' => ['team', 'others'], 'update' => ['editors'], self::ACTION => ['team']];
	}//end readOnlyTeam()

	/**
	 * The permission handler over real rules, for the signed-in uid.
	 *
	 * @param Schema $schema The schema the handler resolves.
	 *
	 * @return array{0: PermissionHandler, 1: IUserSession}
	 */
	private function permissions(Schema $schema): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturnCallback(fn (): string => $this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(fn (): array => self::GROUPS[$this->uid] ?? []);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(static fn (string $app, string $key, bool $default = false): bool => $default);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($event instanceof MatrixRowsRequestedEvent) {
					$this->rowRequests++;
				}

				if ($event instanceof MatrixRowsRequestedEvent && $this->appRows !== []) {
					$event->addRows(rows: $this->appRows, appId: 'caseapp');
				}
			}
		);

		$handler = new PermissionHandler(
			$session,
			$users,
			$groups,
			$schemas,
			$this->createMock(MagicMapper::class),
			new ConditionMatcher($session, $this->createMock(ContainerInterface::class), new OperatorEvaluator(new NullLogger()), new NullLogger()),
			$config,
			new NullLogger(),
			$this->createMock(ContainerInterface::class),
			$dispatcher,
			null,
			$this->grants
		);

		return [$handler, $session];
	}//end permissions()

	/**
	 * The runner over the real definitions, gate and inputs, and a recording write.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return ObjectActionRunner
	 */
	private function runner(Schema $schema): ObjectActionRunner {
		[$permissions, $session] = $this->permissions(schema: $schema);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		/** @var ObjectService&MockObject $objects */
		$objects = $this->createMock(ObjectService::class);
		$objects->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, mixed $register, mixed $schema, bool $_rbac, bool $_multitenancy = true, ?IUser $currentUser = null): ObjectEntity {
				// The row, as the database holds it: a held claim writes only while its property is empty.
				$claimed = EmptyPropertyClaim::consume(uuid: $objectId);
				if ($claimed !== null && in_array($this->stored[$claimed] ?? null, [null, ''], true) === false) {
					throw new PropertyAlreadySetException(message: 'This object is already assigned.');
				}

				$this->patches[] = ['id' => $objectId, 'data' => $data, 'rbac' => $_rbac, 'user' => $currentUser?->getUID()];
				$this->stored = array_merge($this->stored, $data);
				$saved = self::object(assignee: null);
				$saved->setObject(array_merge($saved->getObject(), $data));
				return $saved;
			}
		);
		$audit = $this->createMock(ObjectActionAudit::class);
		$audit->method('record')->willReturnCallback(
			function (...$args): void {
				$this->audited[] = $args;
			}
		);

		$checks   = new ObjectActionRunnerChecks(new ObjectActionDefinitions($this->createMock(RegisterMapper::class)), new ObjectActionGate($permissions, $session, $l10n, $this->createMock(ObjectActionElevationEntries::class)), new ObjectActionInputs(new OasRequestValidator()));
		$recorder = new ObjectActionRunRecorder($checks->inputs, $audit, $checks->gate);

		return new ObjectActionRunner(
			$checks,
			new ObjectActionWriters($this->createMock(TransitionEngine::class), new ObjectActionSelfAssign($objects, $session)),
			$recorder,
			new ObjectActionFlowRun(new ObjectActionFlowEngines($this->createMock(ObjectActionStepsFlow::class), $this->createMock(FlowService::class), $this->createMock(MacroActionResolver::class)), $checks->inputs, $recorder, new NullLogger())
		);
	}//end runner()

	/**
	 * Run the action as the signed-in uid.
	 *
	 * @param Schema       $schema The schema.
	 * @param ObjectEntity $object The object.
	 * @param array        $given  What the request sent.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function take(Schema $schema, ObjectEntity $object, array $given = []): array {
		return $this->runner(schema: $schema)->run(
			object: $object,
			schema: $schema,
			key: self::ACTION,
			given: $given,
			subject: ['uuid' => (string)$object->getUuid(), 'register' => '3', 'schema' => '7']
		);
	}//end take()

	/**
	 * A member of a read-only group takes an unassigned case: one property, as themselves.
	 *
	 * @return void
	 */
	public function testAReadOnlyMemberTakesAnUnassignedCase(): void {
		$answer = $this->take(schema: self::schema(authorization: self::readOnlyTeam()), object: self::object(assignee: null));

		$this->assertSame(200, $answer['status'], json_encode($answer['body']));
		$this->assertSame(
			[['id' => '5b0c1f0e-0000-4000-8000-000000000001', 'data' => ['assignee' => 'anna'], 'rbac' => false, 'user' => 'anna']],
			$this->patches
		);
		$this->assertCount(1, $this->audited);
		$this->assertSame('anna', $this->audited[0][7] ?? null, 'the audit names the caller');
		$this->assertSame([['step' => 'assignee', 'kind' => 'selfAssign']], $this->audited[0][5] ?? null, 'the audit names the one property');
	}//end testAReadOnlyMemberTakesAnUnassignedCase()

	/**
	 * Two claims that both read the case as empty: exactly one wins, the other gets 409.
	 *
	 * @return void
	 */
	public function testOfTwoClaimsOnOneEmptyCaseExactlyOneWins(): void {
		$schema = self::schema(authorization: ['read' => ['team'], self::ACTION => ['team', 'others']]);

		$first = $this->take(schema: $schema, object: self::object(assignee: null));
		$this->uid = 'bram';
		$second = $this->take(schema: $schema, object: self::object(assignee: null));

		$this->assertSame([200, 409], [$first['status'], $second['status']]);
		$this->assertSame('action-precondition-unmet', $second['body']['error'] ?? null);
		$this->assertSame('anna', $this->stored['assignee'] ?? null, 'the first claim stands');
		$this->assertCount(1, $this->audited, 'only the winning run is audited');
	}//end testOfTwoClaimsOnOneEmptyCaseExactlyOneWins()

	/**
	 * A field that does not hold a string is refused at save.
	 *
	 * @return void
	 */
	public function testTheFieldMustHoldAString(): void {
		$validator = new ObjectActionValidator(
			$this->createMock(MacroActionValidator::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class)
		);
		$schema = self::schema(authorization: self::readOnlyTeam());
		$schema->setProperties(['assignee' => ['description' => 'Who handles it.']]);
		$this->assertSame([], $validator->refusals(schema: $schema), 'the control: an untyped property holds a string');

		$schema->setProperties(['assignee' => ['type' => 'object']]);

		$this->assertSame(
			['Action "take": "selfAssign" names "assignee", which does not hold a string.'],
			$validator->refusals(schema: $schema)
		);
	}//end testTheFieldMustHoldAString()

	/**
	 * An assigned case is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnAssignedCaseIsRefused(): void {
		$answer = $this->take(schema: self::schema(authorization: self::readOnlyTeam()), object: self::object(assignee: 'bram'));

		$this->assertSame(409, $answer['status']);
		$this->assertSame([], $this->patches);
		$this->assertSame([], $this->audited);
	}//end testAnAssignedCaseIsRefused()

	/**
	 * A request carrying values, a forged uid or another field, is refused and writes nothing.
	 *
	 * @return void
	 */
	public function testTheRequestCannotChooseTheUidOrAnotherField(): void {
		$answer = $this->take(
			schema: self::schema(authorization: self::readOnlyTeam()),
			object: self::object(assignee: ''),
			given: ['assignee' => 'mallory', 'uid' => 'mallory', 'title' => 'Changed']
		);

		$this->assertSame(422, $answer['status']);
		$this->assertSame([], $this->patches);
	}//end testTheRequestCannotChooseTheUidOrAnotherField()

	/**
	 * An empty-string assignee counts as unassigned, and only the caller's uid is written.
	 *
	 * @return void
	 */
	public function testOnlyTheCallersUidIsWritten(): void {
		$this->uid = 'anna';
		$answer = $this->take(schema: self::schema(authorization: self::readOnlyTeam()), object: self::object(assignee: ''));

		$this->assertSame(200, $answer['status'], json_encode($answer['body']));
		$this->assertSame(['assignee' => 'anna'], $this->patches[0]['data']);
		$this->assertSame('anna', $this->patches[0]['user']);
	}//end testOnlyTheCallersUidIsWritten()

	/**
	 * A reader the action is not granted to is refused.
	 *
	 * @return void
	 */
	public function testAReaderWithoutTheGrantIsRefused(): void {
		$this->uid = 'bram';

		$answer = $this->take(schema: self::schema(authorization: self::readOnlyTeam()), object: self::object(assignee: null));

		$this->assertSame(403, $answer['status']);
		$this->assertSame([], $this->patches);
	}//end testAReaderWithoutTheGrantIsRefused()

	/**
	 * With no rule and no matrix row, nobody may run it, also on an empty block.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function ungrantedBlocks(): array {
		return [
			'empty block' => [[]],
			'rules without the action' => [['read' => ['team'], 'update' => ['editors']]],
			'matrix row without the action' => [[
				'read' => ['team'],
				'matrix' => ['field' => 'group', 'userSource' => ['groups' => true], 'rows' => [['group' => 'team', 'value' => '$self', 'actions' => ['read']]]],
			]],
		];
	}//end ungrantedBlocks()

	/**
	 * A self-assign action nobody was granted is refused for everyone.
	 *
	 * @param array<string, mixed> $authorization The block.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('ungrantedBlocks')]
	public function testWithoutAGrantNobodyMayRunIt(array $authorization): void {
		$answer = $this->take(schema: self::schema(authorization: $authorization), object: self::object(assignee: null));

		$this->assertSame(403, $answer['status']);
		$this->assertSame([], $this->patches);
	}//end testWithoutAGrantNobodyMayRunIt()

	/**
	 * An administrator is not granted an action nobody was granted, by a run or by `canRun()`.
	 *
	 * @return void
	 */
	public function testAnAdministratorIsNotGrantedAnUngrantedAction(): void {
		$this->uid = 'root';
		$schema = self::schema(authorization: ['read' => ['team']]);

		$answer = $this->take(schema: $schema, object: self::object(assignee: null));

		[$permissions, $session] = $this->permissions(schema: $schema);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$list = new ObjectActionList(
			new ObjectActionDefinitions($this->createMock(RegisterMapper::class)),
			new ObjectActionGate($permissions, $session, $l10n, $this->createMock(ObjectActionElevationEntries::class)),
			new ObjectActionInputs(new OasRequestValidator()),
			$this->createMock(TransitionEngine::class),
			$l10n
		);

		$this->assertTrue($permissions->hasPermission(schema: $schema, action: self::ACTION, userId: 'root'), 'the control: an administrator passes the rules');
		$this->assertSame(403, $answer['status']);
		$this->assertSame([self::ACTION => false], $list->canRun(object: self::object(assignee: null), schema: $schema));
	}//end testAnAdministratorIsNotGrantedAnUngrantedAction()

	/**
	 * Without a rule or a row naming the action, an administrator is refused too.
	 *
	 * @param array<string, mixed> $authorization The block.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('ungrantedBlocks')]
	public function testWithoutAGrantNotEvenAnAdministratorMayRunIt(array $authorization): void {
		$this->uid = 'root';

		$answer = $this->take(schema: self::schema(authorization: $authorization), object: self::object(assignee: null));

		$this->assertSame(403, $answer['status']);
		$this->assertSame([], $this->patches);
	}//end testWithoutAGrantNotEvenAnAdministratorMayRunIt()

	/**
	 * App rows that do not name the action grant it to nobody: member, administrator or owner.
	 *
	 * @return void
	 */
	public function testAppRowsWithoutTheActionGrantItToNobody(): void {
		$schema = self::schema(
			authorization: ['read' => ['team'], 'matrix' => ['field' => 'group', 'userSource' => ['groups' => true], 'rowsFrom' => 'caseapp.rows']]
		);
		$this->appRows = [['group' => 'team', 'value' => '$self', 'actions' => ['read']]];

		$answers = [];
		foreach (['anna', 'root', 'olga'] as $uid) {
			$this->uid = $uid;
			$answers[$uid] = $this->take(schema: $schema, object: self::object(assignee: null))['status'];
		}

		$this->assertSame(['anna' => 403, 'root' => 403, 'olga' => 403], $answers);
		$this->assertSame([], $this->patches);
	}//end testAppRowsWithoutTheActionGrantItToNobody()

	/**
	 * Owning the object does not let its owner take it: the self-assign has no owner bypass.
	 *
	 * @return void
	 */
	public function testTheOwnerOutsideTheGrantIsRefused(): void {
		$this->uid = 'olga';

		$answer = $this->take(schema: self::schema(authorization: self::readOnlyTeam()), object: self::object(assignee: null));

		$this->assertSame(403, $answer['status']);
		$this->assertSame([], $this->patches);
	}//end testTheOwnerOutsideTheGrantIsRefused()

	/**
	 * A declared matrix row grants the action to the caller whose value matches.
	 *
	 * @return void
	 */
	public function testAMatrixRowGrantsTheAction(): void {
		$schema = self::schema(
			authorization: [
				'read' => ['team'],
				'matrix' => ['field' => 'group', 'userSource' => ['groups' => true], 'rows' => [['group' => 'team', 'value' => '$self', 'actions' => ['read', self::ACTION]]]],
			]
		);

		$answer = $this->take(schema: $schema, object: self::object(assignee: null));

		$this->assertSame(200, $answer['status'], json_encode($answer['body']));
		$this->assertSame(['assignee' => 'anna'], $this->patches[0]['data']);
	}//end testAMatrixRowGrantsTheAction()

	/**
	 * A row the app provides grants the action too; without it the caller is refused.
	 *
	 * @return void
	 */
	public function testAnAppProvidedRowGrantsTheAction(): void {
		$schema = self::schema(
			authorization: ['read' => ['team'], 'matrix' => ['field' => 'group', 'userSource' => ['groups' => true], 'rowsFrom' => 'caseapp.rows']]
		);

		$refused = $this->take(schema: $schema, object: self::object(assignee: null));
		$this->appRows = [['group' => 'team', 'value' => '$self', 'actions' => [self::ACTION]]];
		$granted = $this->take(schema: $schema, object: self::object(assignee: null));

		$this->assertSame(403, $refused['status'], 'no provider row, no grant');
		$this->assertSame(200, $granted['status'], json_encode($granted['body']));
	}//end testAnAppProvidedRowGrantsTheAction()

	/**
	 * `canRun()` answers per caller and per object.
	 *
	 * @return void
	 */
	public function testCanRunAnswersForEachCaller(): void {
		$schema = self::schema(authorization: self::readOnlyTeam());
		$answers = [];
		foreach (['anna' => null, 'bram' => null, 'anna-assigned' => 'bram'] as $case => $assignee) {
			$this->uid = explode('-', $case)[0];
			[$permissions, $session] = $this->permissions(schema: $schema);
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnArgument(0);
			$list = new ObjectActionList(
				new ObjectActionDefinitions($this->createMock(RegisterMapper::class)),
				new ObjectActionGate($permissions, $session, $l10n, $this->createMock(ObjectActionElevationEntries::class)),
				new ObjectActionInputs(new OasRequestValidator()),
				$this->createMock(TransitionEngine::class),
				$l10n
			);
			$answers[$case] = $list->canRun(object: self::object(assignee: $assignee), schema: $schema);
		}

		$this->assertSame(
			['anna' => [self::ACTION => true], 'bram' => [self::ACTION => false], 'anna-assigned' => [self::ACTION => false]],
			$answers
		);
	}//end testCanRunAnswersForEachCaller()
	/**
	 * Self-assign declarations the save refuses, with the word the refusal names.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function badDeclarations(): array {
		$base = ['name' => 'Take', 'description' => 'Take it.'];
		return [
			'no field' => [$base + ['selfAssign' => []], 'must be {"field"'],
			'a field list' => [$base + ['selfAssign' => ['field' => ['assignee']]], 'must be {"field"'],
			'an extra key' => [$base + ['selfAssign' => ['field' => 'assignee', 'value' => 'bob']], 'must be {"field"'],
			'inputs' => [$base + ['selfAssign' => ['field' => 'assignee'], 'inputs' => ['type' => 'object']], 'takes no inputs'],
			'elevate' => [$base + ['selfAssign' => ['field' => 'assignee'], 'elevate' => [['register' => 'r', 'schema' => 's', 'verbs' => ['update']]]], 'takes no elevate'],
			'async' => [$base + ['selfAssign' => ['field' => 'assignee'], 'mode' => 'async'], 'takes no mode'],
		];
	}//end badDeclarations()

	/**
	 * Names a self-assign action may not take: a verb would hand it to everybody holding that verb.
	 *
	 * @return array<string, array{string}>
	 */
	public static function reservedNames(): array {
		return ['a canonical verb' => ['read'], 'another canonical verb' => ['update'], 'a control key' => ['deny']];
	}//end reservedNames()

	/**
	 * A self-assign action named after a verb or a control key is refused.
	 *
	 * @param string $key The action key.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('reservedNames')]
	public function testASelfAssignActionNeedsItsOwnName(string $key): void {
		$refusals = ObjectActionDefinition::refusals(
			configuration: ['x-openregister-action' => [$key => ['name' => 'Take', 'description' => 'Take it.', 'selfAssign' => ['field' => 'assignee']]]],
			authorization: [$key => ['team']]
		);

		$this->assertSame([sprintf('Action "%s": a self-assign action needs its own name, not a verb or a control key.', $key)], $refusals);
	}//end testASelfAssignActionNeedsItsOwnName()

	/**
	 * A malformed self-assign declaration is refused at save.
	 *
	 * @param array<string, mixed> $declaration The declared entry.
	 * @param string               $expected    A fragment of the refusal.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('badDeclarations')]
	public function testAMalformedDeclarationIsRefused(array $declaration, string $expected): void {
		$refusals = ObjectActionDefinition::refusals(
			configuration: ['x-openregister-action' => ['take' => $declaration]],
			authorization: ['take' => ['team']]
		);

		$this->assertNotSame([], $refusals);
		$this->assertStringContainsString($expected, implode(' ', $refusals));
	}//end testAMalformedDeclarationIsRefused()

	/**
	 * A well-formed declaration is accepted, and its field must be a property of the schema.
	 *
	 * @return void
	 */
	public function testTheFieldMustBeAPropertyOfTheSchema(): void {
		$validator = new ObjectActionValidator(
			$this->createMock(MacroActionValidator::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class)
		);
		$schema = self::schema(authorization: self::readOnlyTeam());
		$this->assertSame([], $validator->refusals(schema: $schema), 'the control: a declared field saves');

		$schema->setProperties(['title' => ['type' => 'string']]);
		$this->assertSame(
			['Action "take": "selfAssign" names "assignee", which is not a property of this schema.'],
			$validator->refusals(schema: $schema)
		);
	}//end testTheFieldMustBeAPropertyOfTheSchema()

	/**
	 * A matrix row may name an action the schema declares, and no other name.
	 *
	 * @return void
	 */
	public function testAMatrixRowMayNameOnlyDeclaredActions(): void {
		$authorization = [
			'read' => ['team'],
			'matrix' => [
				'field' => 'group',
				'userSource' => ['groups' => true],
				'rows' => [['group' => 'team', 'value' => '$self', 'actions' => ['read', 'take', 'grab']]],
			],
		];
		$properties = ['group' => ['type' => 'string']];

		$codes = array_column((new DepartmentMatrixValidator(declaredActions: ['take']))->validate(properties: $properties, authorization: $authorization), 'message');
		$plain = array_column((new DepartmentMatrixValidator())->validate(properties: $properties, authorization: $authorization), 'message');

		$this->assertSame(['Row 0 names the action "grab", which is not one this engine resolves.'], $codes);
		$this->assertCount(2, $plain, 'without the declaration, "take" is unknown too');
	}//end testAMatrixRowMayNameOnlyDeclaredActions()

	/**
	 * The schema save accepts a matrix row naming the schema's declared action, and refuses another name.
	 *
	 * @return void
	 */
	public function testTheSchemaSaveReadsTheDeclaredActionsIntoTheMatrixCheck(): void {
		$mapper = new SchemaMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			new NullLogger()
		);
		$validate = new ReflectionMethod(SchemaMapper::class, 'checkAccessDeclarations');
		$matrix = static fn (string $action): array => [
			'read' => ['team'],
			'matrix' => ['field' => 'group', 'userSource' => ['groups' => true], 'rows' => [['group' => 'team', 'value' => '$self', 'actions' => [$action]]]],
		];

		$validate->invoke($mapper, self::schema(authorization: $matrix(self::ACTION)));

		$this->expectExceptionMessageMatches('/names the action "grab"/');
		$validate->invoke($mapper, self::schema(authorization: $matrix('grab')));
	}//end testTheSchemaSaveReadsTheDeclaredActionsIntoTheMatrixCheck()

	/**
	 * A write that fails before it reaches the table leaves no claim behind for the next write.
	 *
	 * @return void
	 */
	public function testAFailedWriteLeavesNoClaimBehind(): void {
		[, $session] = $this->permissions(schema: self::schema(authorization: self::readOnlyTeam()));
		$objects = $this->createMock(ObjectService::class);
		$objects->method('patchObject')->willThrowException(new \RuntimeException('validation failed'));
		$definition = ObjectActionDefinition::fromDeclaration(
			key: self::ACTION,
			definition: ['name' => 'Take', 'description' => 'Take it.', 'selfAssign' => ['field' => 'assignee']]
		);

		try {
			(new ObjectActionSelfAssign($objects, $session))->assign(object: self::object(assignee: null), definition: $definition);
			$this->fail('the failed write must still fail');
		} catch (\RuntimeException $e) {
			$this->assertSame('validation failed', $e->getMessage());
		}

		$this->assertNull(EmptyPropertyClaim::consume(uuid: '5b0c1f0e-0000-4000-8000-000000000001'));
	}//end testAFailedWriteLeavesNoClaimBehind()

	/**
	 * On a private object, neither its owner nor its owning group is granted the action by owning it.
	 *
	 * @return void
	 */
	public function testOnAPrivateObjectOwnershipGrantsNothing(): void {
		$schema = self::schema(authorization: self::readOnlyTeam() + ['scope' => 'private']);
		$object = self::object(assignee: null);
		$object->setAuthorization(['ownerGroup' => 'others']);

		$answers = [];
		foreach (['olga', 'bram', 'root'] as $uid) {
			$this->uid = $uid;
			$answers[$uid] = $this->take(schema: $schema, object: $object)['status'];
			$this->assertSame('olga', $object->getOwner(), 'the object itself keeps its owner');
			$this->assertSame(['ownerGroup' => 'others'], $object->getAuthorization(), 'the object itself keeps its owning group');
		}

		$this->assertSame(['olga' => 403, 'bram' => 403, 'root' => 200], $answers, 'the owner and the owning group are refused; an administrator holds every right');

		$this->uid = 'anna';
		$owned = self::object(assignee: null);
		$owned->setUuid('5b0c1f0e-0000-4000-8000-000000000002');
		$owned->setOwner('anna');
		$this->assertSame(403, $this->take(schema: $schema, object: $owned)['status'], 'an owner in the granted group is refused on a private object too');
	}//end testOnAPrivateObjectOwnershipGrantsNothing()

	/**
	 * A share on a private object does not admit a self-assign: a share carries only CRUD and share bits.
	 *
	 * @return void
	 */
	public function testAShareOnAPrivateObjectDoesNotAdmitASelfAssign(): void {
		$uuid = '5b0c1f0e-0000-4000-8000-000000000003';
		$this->grants = $this->getMockBuilder(ObjectGrantResolver::class)
			->setConstructorArgs([new NullLogger(), $this->createMock(ContainerInterface::class)])
			->onlyMethods(['grantedObjectUuids'])
			->getMock();
		$this->grants->method('grantedObjectUuids')->willReturn([$uuid => 31]);
		$this->assertTrue($this->grants->isGranted(userId: 'anna', objectUuid: $uuid, action: 'update'), 'the control: the share carries update');

		$object = self::object(assignee: null);
		$object->setUuid($uuid);
		$this->uid = 'anna';

		$answer = $this->take(schema: self::schema(authorization: self::readOnlyTeam() + ['scope' => 'private']), object: $object);

		$this->assertSame(403, $answer['status']);
		$this->assertSame([], $this->patches);
	}//end testAShareOnAPrivateObjectDoesNotAdmitASelfAssign()

	/**
	 * A row naming the action with surrounding spaces grants it, as the validator and compiler read it.
	 *
	 * @return void
	 */
	public function testARowNameIsReadTrimmed(): void {
		$schema = self::schema(
			authorization: [
				'read' => ['team'],
				'matrix' => ['field' => 'group', 'userSource' => ['groups' => true], 'rows' => [['group' => 'team', 'value' => '$self', 'actions' => [' ' . self::ACTION . ' ']]]],
			]
		);

		$answer = $this->take(schema: $schema, object: self::object(assignee: null));

		$this->assertSame(200, $answer['status'], json_encode($answer['body']));
	}//end testARowNameIsReadTrimmed()

	/**
	 * A run with app rows asks the provider once.
	 *
	 * @return void
	 */
	public function testTheAppRowsAreAskedForOnce(): void {
		$schema = self::schema(
			authorization: ['read' => ['team'], 'matrix' => ['field' => 'group', 'userSource' => ['groups' => true], 'rowsFrom' => 'caseapp.rows']]
		);
		$this->appRows = [['group' => 'team', 'value' => '$self', 'actions' => [self::ACTION]]];

		$answer = $this->take(schema: $schema, object: self::object(assignee: null));

		$this->assertSame(200, $answer['status'], json_encode($answer['body']));
		$this->assertSame(1, $this->rowRequests);
	}//end testTheAppRowsAreAskedForOnce()

	/**
	 * A selection offers a bulk self-assign to the granted caller only.
	 *
	 * @return void
	 */
	public function testASelectionOffersTheActionToTheGrantedCallerOnly(): void {
		$schema = self::schema(authorization: self::readOnlyTeam());
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					self::ACTION => ['name' => 'Take', 'description' => 'Take them.', 'surfaces' => ['bulk'], 'selfAssign' => ['field' => 'assignee']],
				],
			]
		);

		$ungranted = clone $schema;
		$ungranted->setAuthorization(['read' => ['team']]);

		$offered = [];
		foreach (['anna', 'bram', 'olga', 'root'] as $uid) {
			$this->uid = $uid;
			[$permissions, $session] = $this->permissions(schema: $schema);
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnArgument(0);
			$list = new ObjectActionList(
				new ObjectActionDefinitions($this->createMock(RegisterMapper::class)),
				new ObjectActionGate($permissions, $session, $l10n, $this->createMock(ObjectActionElevationEntries::class)),
				new ObjectActionInputs(new OasRequestValidator()),
				$this->createMock(TransitionEngine::class),
				$l10n
			);
			$offered[$uid] = array_column($list->forSelection(schema: $schema, register: '3'), 'key');
			$offered[$uid . '-ungranted'] = array_column($list->forSelection(schema: $ungranted, register: '3'), 'key');
		}

		$this->assertSame(
			[
				'anna' => [self::ACTION],
				'anna-ungranted' => [],
				'bram' => [],
				'bram-ungranted' => [],
				'olga' => [],
				'olga-ungranted' => [],
				'root' => [self::ACTION],
				'root-ungranted' => [],
			],
			$offered
		);
	}//end testASelectionOffersTheActionToTheGrantedCallerOnly()

}//end class

<?php

/**
 * Golden test: every answer a run can give, allowed and refused, and the right each one asks.
 *
 * Pinned on the runner before its units were split, so a refactor that
 * reorders a check, asks a different right or reshapes an answer fails here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\PropertyAlreadySetException;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionAudit;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationEntries;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionFlowEngines;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionFlowRun;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunRecorder;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunnerChecks;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSelfAssign;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionStepsFlow;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionWriters;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionTarget;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ObjectActionRunnerGoldenTest extends TestCase {

	private PermissionHandlerInterface&MockObject $permissions;

	private ObjectActionStepsFlow&MockObject $steps;

	private AuditTrailMapperInterface&MockObject $trail;

	private TransitionEngine&MockObject $transitions;

	private ObjectActionSelfAssign&MockObject $selfAssign;

	private FlowService&MockObject $flows;

	private MacroActionResolver&MockObject $macros;

	/**
	 * Rights the caller holds, by action; absent is granted.
	 *
	 * @var array<string, bool>
	 */
	private array $rights = [];

	/**
	 * The rights asked, in order.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * The audit entries written, as their named arguments.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $audited = [];

	protected function setUp(): void {
		parent::setUp();

		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->permissions->method('hasPermission')->willReturnCallback(
			function ($schema, string $action): bool {
				$this->asked[] = $action;
				return ($this->rights[$action] ?? true);
			}
		);
		$this->steps = $this->createMock(ObjectActionStepsFlow::class);
		$this->steps->method('flowFor')->willReturn('steps-flow');
		// The real ObjectActionAudit writes the entry, so the acting user is
		// the one its gate reads: record() no longer takes a userId argument.
		$this->trail = $this->createMock(AuditTrailMapperInterface::class);
		$this->trail->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context, ?string $actorId): AuditTrail {
				$this->audited[] = array_combine(
					['object', 'action', 'kind', 'run', 'inputsDigest', 'changes', 'outcome', 'userId'],
					[$object, $context['declaredAction'], $context['kind'], $context['run'], $context['inputsDigest'], $context['changes'], $context['outcome'], $actorId]
				);
				return new AuditTrail();
			}
		);
		$this->transitions = $this->createMock(TransitionEngine::class);
		$this->transitions->method('availableActions')->willReturn([['action' => 'reopen']]);
		$this->selfAssign = $this->createMock(ObjectActionSelfAssign::class);
		$this->flows = $this->createMock(FlowService::class);
		$this->macros = $this->createMock(MacroActionResolver::class);
		$this->macros->method('changesOf')->willReturn([['step' => 'Datum gezet', 'kind' => 'write']]);
		$this->macros->method('nextForRun')->willReturn('list');
	}//end setUp()

	/**
	 * The runner over real definitions, gate and inputs.
	 *
	 * @return ObjectActionRunner The runner.
	 */
	private function runner(): ObjectActionRunner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('getLanguageCode')->willReturn('en');

		$checks   = new ObjectActionRunnerChecks(new ObjectActionDefinitions($this->createMock(RegisterMapper::class)), new ObjectActionGate($this->permissions, $session, $l10n, $this->createMock(ObjectActionElevationEntries::class)), new ObjectActionInputs(new OasRequestValidator()));
		$recorder = new ObjectActionRunRecorder($checks->inputs, new ObjectActionAudit($this->trail, $this->createMock(LoggerInterface::class), $checks->gate));

		return new ObjectActionRunner(
			$checks,
			new ObjectActionWriters($this->transitions, $this->selfAssign),
			$recorder,
			new ObjectActionFlowRun(new ObjectActionFlowEngines($this->steps, $this->flows, $this->macros), $checks->inputs, $recorder, $this->createMock(LoggerInterface::class))
		);
	}//end runner()

	/**
	 * A schema with one action of every kind.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('besluit');
		$schema->setAuthorization(['claim' => [['group' => 'behandelaars']]]);
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => ['field' => 'status', 'transitions' => ['finish' => ['from' => ['decided'], 'to' => 'done']]],
				'x-openregister-action' => [
					'publish' => [
						'name' => 'Publish',
						'inputs' => ['type' => 'object', 'required' => ['from'], 'properties' => ['from' => ['type' => 'string']]],
						'when' => ['==' => [['var' => 'object.status'], 'decided']],
						'whenMessage' => ['en' => 'Only decided.'],
						'steps' => [['type' => 'openregister.set-fields']],
					],
					'finish' => ['name' => 'Finish', 'transition' => 'finish', 'next' => 'list'],
					'export' => ['name' => 'Export', 'mode' => 'async', 'steps' => [['type' => 'openregister.object-read']]],
					'claim' => ['name' => 'Claim', 'selfAssign' => ['field' => 'handler']],
					'grantOnly' => ['name' => 'Grant only'],
				],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * An object.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(array $data = ['status' => 'decided']): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setOwner('bob');
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * A run in a state.
	 *
	 * @param string $status The run status.
	 * @param string $error  The run error.
	 *
	 * @return FlowRun The run.
	 */
	private function flowRun(string $status, string $error = ''): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-9');
		$run->setStatus($status);
		if ($error !== '') {
			$run->setError($error);
		}

		return $run;
	}//end flowRun()

	/**
	 * Run an action.
	 *
	 * @param string            $key    The action.
	 * @param array             $inputs The inputs.
	 * @param ObjectEntity|null $object The object.
	 *
	 * @return array The answer.
	 */
	private function invoke(string $key, array $inputs = [], ?ObjectEntity $object = null): array {
		return $this->runner()->run(new ObjectActionTarget($object ?? $this->object(), $this->schema(), 'r', 'besluit'), $key, $inputs);
	}//end invoke()

	public function testRefusalsInOrderWithTheirExactAnswers(): void {
		$this->flows->expects($this->never())->method('runForAction');
		$this->transitions->expects($this->never())->method('transition');

		$this->assertSame(['status' => 404, 'body' => ['error' => 'No action "nope" runs on this object.']], $this->invoke('nope'));
		$this->assertSame(['status' => 404, 'body' => ['error' => 'No action "grantOnly" runs on this object.']], $this->invoke('grantOnly'));

		$this->rights = ['publish' => false];
		$this->assertSame(['status' => 403, 'body' => ['error' => 'You may not perform "publish" on this object.']], $this->invoke('publish', ['from' => 'x'], $this->object(['status' => 'draft'])));

		$this->rights = [];
		$this->assertSame(
			['status' => 409, 'body' => ['error' => 'action-precondition-unmet', 'message' => 'Only decided.', 'action' => 'publish']],
			$this->invoke('publish', ['from' => 5], $this->object(['status' => 'draft']))
		);
		$invalid = $this->invoke('publish', ['from' => 5]);
		$this->assertSame(422, $invalid['status']);
		$this->assertSame(['error', 'message', 'errors', 'action'], array_keys($invalid['body']));
		$this->assertSame(['invalid-inputs', 'The inputs do not fit this action.', 'publish'], [$invalid['body']['error'], $invalid['body']['message'], $invalid['body']['action']]);
		$this->assertSame('from', $invalid['body']['errors'][0]['field']);

		$this->assertSame(
			['status' => 409, 'body' => ['error' => 'action-precondition-unmet', 'message' => 'This object is already assigned.', 'action' => 'claim']],
			$this->invoke('claim', [], $this->object(['status' => 'decided', 'handler' => 'carl']))
		);
		$this->assertSame([], $this->audited);
	}//end testRefusalsInOrderWithTheirExactAnswers()

	public function testTheRightAskedIsTheKeyOrUpdateForAnUndeclaredTransition(): void {
		$this->asked = [];
		$this->rights = ['update' => false];
		$this->assertSame(['status' => 403, 'body' => ['error' => 'You may not perform "reopen" on this object.']], $this->invoke('reopen'));
		$this->assertSame(['update'], $this->asked);

		$this->asked = [];
		$this->rights = ['finish' => false, 'update' => true];
		$this->assertSame(403, $this->invoke('finish')['status']);
		$this->assertSame(['finish'], $this->asked);
	}//end testTheRightAskedIsTheKeyOrUpdateForAnUndeclaredTransition()

	public function testCheckPreviewsWithoutRunning(): void {
		$this->flows->expects($this->never())->method('runForAction');
		$checked = $this->runner()->check($this->object(), $this->schema(), 'publish', ['from' => 'today']);

		$this->assertNull($checked['refusal']);
		$this->assertSame('publish', $checked['definition']->key);
		$this->assertSame(['from' => 'today'], $checked['inputs']);

		$refused = $this->runner()->check($this->object(), $this->schema(), 'nope', []);
		$this->assertSame(['refusal' => ['status' => 404, 'body' => ['error' => 'No action "nope" runs on this object.']], 'definition' => null, 'inputs' => []], $refused);
	}//end testCheckPreviewsWithoutRunning()

	public function testATransitionAnswersTheSavedObjectOrItsRefusal(): void {
		$saved = $this->object(['status' => 'done']);
		$this->transitions->method('transition')->willReturnOnConsecutiveCalls(
			$saved,
			$this->throwException(new NotAuthorizedException('Guarded.')),
			$this->throwException(new RuntimeException('Broken.'))
		);

		$ok = $this->invoke('finish');
		$this->assertSame(200, $ok['status']);
		$this->assertSame(['run', 'outcome', 'action', 'kind', 'next', 'object'], array_keys($ok['body']));
		$this->assertSame([null, 'completed', 'finish', 'transition', 'list'], array_slice(array_values($ok['body']), 0, 5));
		$this->assertSame(['status' => 403, 'body' => ['error' => 'Guarded.', 'action' => 'finish']], $this->invoke('finish'));
		$this->assertSame(['status' => 422, 'body' => ['error' => 'Broken.', 'action' => 'finish']], $this->invoke('finish'));
		$this->assertCount(1, $this->audited);
		$this->assertSame(['finish', 'transition', null, [], 'completed', 'anna'], [$this->audited[0]['action'], $this->audited[0]['kind'], $this->audited[0]['run'], $this->audited[0]['changes'], $this->audited[0]['outcome'], $this->audited[0]['userId']]);
	}//end testATransitionAnswersTheSavedObjectOrItsRefusal()

	public function testASelfAssignAnswersAndAuditsItsField(): void {
		$this->selfAssign->method('assign')->willReturnOnConsecutiveCalls(
			$this->object(['status' => 'decided', 'handler' => 'anna']),
			$this->throwException(new PropertyAlreadySetException('Taken.')),
			$this->throwException(new RuntimeException('Broken.'))
		);

		$this->assertSame(200, $this->invoke('claim')['status']);
		$this->assertSame(['status' => 409, 'body' => ['error' => 'action-precondition-unmet', 'message' => 'Taken.', 'action' => 'claim']], $this->invoke('claim'));
		$this->assertSame(['status' => 422, 'body' => ['error' => 'Broken.', 'action' => 'claim']], $this->invoke('claim'));
		$this->assertSame([['step' => 'handler', 'kind' => 'selfAssign']], $this->audited[0]['changes']);
	}//end testASelfAssignAnswersAndAuditsItsField()

	public function testFlowAnswersForEveryRunState(): void {
		$this->flows->method('runForAction')->willReturnOnConsecutiveCalls(
			$this->flowRun(FlowRun::STATUS_COMPLETED),
			$this->flowRun(FlowRun::STATUS_FAILED, 'Step broke.'),
			$this->flowRun(FlowRun::STATUS_FAILED),
			$this->flowRun(FlowRun::STATUS_SUSPENDED),
			$this->flowRun(FlowRun::STATUS_QUEUED),
			$this->throwException(new RuntimeException('No engine.'))
		);
		$links = ['run' => '/api/flow-runs/run-9'];

		$this->assertSame(
			['status' => 200, 'body' => ['run' => 'run-9', 'outcome' => 'completed', 'action' => 'publish', 'kind' => 'steps', 'next' => 'list', 'links' => $links]],
			$this->invoke('publish', ['from' => 'today'])
		);
		$this->assertSame(
			['status' => 422, 'body' => ['error' => 'Step broke.', 'run' => 'run-9', 'outcome' => 'failed', 'action' => 'publish', 'links' => $links]],
			$this->invoke('publish', ['from' => 'today'])
		);
		$this->assertSame('The run ended failed.', $this->invoke('publish', ['from' => 'today'])['body']['error']);
		$this->assertSame(
			['status' => 202, 'body' => ['run' => 'run-9', 'status' => 'suspended', 'action' => 'publish', 'kind' => 'steps', 'links' => $links]],
			$this->invoke('publish', ['from' => 'today'])
		);
		$this->assertSame(
			['status' => 202, 'body' => ['run' => 'run-9', 'status' => 'queued', 'action' => 'export', 'kind' => 'steps', 'links' => $links]],
			$this->invoke('export')
		);
		$this->assertSame(['status' => 422, 'body' => ['error' => 'No engine.', 'action' => 'publish']], $this->invoke('publish', ['from' => 'today']));

		$outcomes = array_map(static fn (array $entry): array => [$entry['run'], $entry['outcome'], $entry['changes']], $this->audited);
		$changed = [['step' => 'Datum gezet', 'kind' => 'write']];
		$this->assertSame(
			[['run-9', 'completed', $changed], ['run-9', 'failed', $changed], ['run-9', 'failed', $changed], ['run-9', 'suspended', []], ['run-9', 'queued', []]],
			$outcomes
		);
	}//end testFlowAnswersForEveryRunState()
}//end class

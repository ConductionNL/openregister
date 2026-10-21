<?php

/**
 * Unit tests for running one action on one object: the refusal order and the three bodies.
 *
 * Nothing may be written before the body runs, so every refusal test also
 * pins that no run was queued and no transition was taken.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionAudit;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationEntries;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionStepsFlow;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ObjectActionRunnerTest extends TestCase {

	private PermissionHandler&MockObject $permissions;

	private ObjectActionStepsFlow&MockObject $steps;

	private ObjectActionAudit&MockObject $audit;

	private TransitionEngine&MockObject $transitions;

	private FlowService&MockObject $flows;

	private MacroActionResolver&MockObject $macros;

	private ObjectActionElevationEntries&MockObject $elevation;

	/**
	 * Rights the caller holds, by action.
	 *
	 * @var array<string, bool>
	 */
	private array $rights = [];

	protected function setUp(): void {
		parent::setUp();

		$this->permissions = $this->createMock(PermissionHandler::class);
		$this->permissions->method('hasPermission')->willReturnCallback(
			fn ($schema, string $action): bool => ($this->rights[$action] ?? true)
		);
		$this->steps = $this->createMock(ObjectActionStepsFlow::class);
		$this->steps->method('flowFor')->willReturn('steps-flow');
		$this->audit = $this->createMock(ObjectActionAudit::class);
		$this->transitions = $this->createMock(TransitionEngine::class);
		$this->transitions->method('availableActions')->willReturn([]);
		$this->flows = $this->createMock(FlowService::class);
		$this->macros = $this->createMock(MacroActionResolver::class);
		$this->macros->method('changesOf')->willReturn([['step' => 'Datum gezet', 'kind' => 'write']]);
		$this->macros->method('nextForRun')->willReturn('stay');
		$this->elevation = $this->createMock(ObjectActionElevationEntries::class);
	}//end setUp()

	/**
	 * The runner over real definitions, gate and inputs.
	 *
	 * @return ObjectActionRunner
	 */
	private function runner(): ObjectActionRunner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('getLanguageCode')->willReturn('nl');

		return new ObjectActionRunner(
			new ObjectActionDefinitions($this->createMock(RegisterMapper::class)),
			new ObjectActionGate($this->permissions, $session, $l10n, $this->elevation),
			new ObjectActionInputs(new OasRequestValidator()),
			$this->steps,
			$this->audit,
			$this->transitions,
			$this->flows,
			$this->macros,
			$this->createMock(LoggerInterface::class)
		);
	}//end runner()

	/**
	 * A decision schema with `publish` (steps, input `from`), `finish` over a transition,
	 * `close-and-notify` as an existing macro, `export` async and `sign` with a writeOnly pin.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('besluit');
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => ['field' => 'status', 'transitions' => ['finish' => ['from' => ['decided'], 'to' => 'done']]],
				'x-openregister-action' => [
					'publish' => [
						'name' => 'Publish',
						'description' => 'Make it public.',
						'inputs' => [
							'type' => 'object',
							'required' => ['from'],
							'properties' => ['from' => ['type' => 'string', 'format' => 'date', 'default' => '@now']],
						],
						'when' => ['==' => [['var' => 'object.status'], 'decided']],
						'whenMessage' => ['nl' => 'Alleen een vastgesteld besluit.', 'en' => 'Only a decided decision.'],
						'steps' => [['type' => 'openregister.set-fields', 'config' => ['set' => ['publicatiedatum' => '{{inputs.from}}']]]],
					],
					'finish' => ['name' => 'Finish', 'description' => 'Close it.', 'transition' => 'finish', 'next' => 'list'],
					'close-and-notify' => ['name' => 'Close', 'description' => 'Close it.', 'macro' => true, 'flow' => '0f6a1b2c-0000-4000-8000-000000000001'],
					'export' => ['name' => 'Export', 'description' => 'Export it.', 'mode' => 'async', 'steps' => [['type' => 'openregister.object-read']]],
					'sign' => [
						'name' => 'Sign',
						'description' => 'Sign it.',
						'inputs' => ['type' => 'object', 'properties' => ['pin' => ['type' => 'string', 'writeOnly' => true], 'note' => ['type' => 'string']]],
						'steps' => [['type' => 'acme.sign']],
					],
					'sendMail' => ['name' => 'Send mail', 'description' => 'Grant only.'],
					'release' => [
						'name' => 'Release',
						'description' => 'Publish into the publication register.',
						'steps' => [['type' => 'openregister.object-write']],
						'elevate' => [['register' => 'publication', 'schema' => 'publication', 'verbs' => ['create']]],
					],
				],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * A decided decision.
	 *
	 * @param string $status The status.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $status = 'decided'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setOwner('bob');
		$object->setObject(['status' => $status, 'applicant' => 'carl']);
		return $object;
	}//end object()

	/**
	 * A run in a given state.
	 *
	 * @param string $status The run status.
	 *
	 * @return FlowRun
	 */
	private function flowRun(string $status): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-9');
		$run->setStatus($status);
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
		return $this->runner()->run($object ?? $this->object(), $this->schema(), $key, $inputs, ['uuid' => 'obj-1', 'register' => 'r', 'schema' => 'besluit']);
	}//end invoke()

	/**
	 * Nothing may run.
	 *
	 * @return void
	 */
	private function expectNothingRuns(): void {
		$this->flows->expects($this->never())->method('run');
		$this->flows->expects($this->never())->method('runForAction');
		$this->transitions->expects($this->never())->method('transition');
		$this->audit->expects($this->never())->method('record');
	}//end expectNothingRuns()

	public function testAnUnknownActionIs404(): void {
		$this->expectNothingRuns();

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->invoke('fly')['status']);
	}//end testAnUnknownActionIs404()

	public function testABodilessActionIsNotRunnable(): void {
		$this->expectNothingRuns();

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->invoke('sendMail')['status']);
	}//end testABodilessActionIsNotRunnable()

	public function testNoRightIs403BeforeAnythingElse(): void {
		$this->rights['publish'] = false;
		$this->expectNothingRuns();

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->invoke('publish', ['from' => 'tomorrow-ish'], $this->object('draft'))['status']);
	}//end testNoRightIs403BeforeAnythingElse()

	public function testAPreconditionRefusalIs409WithTheDeclaredMessage(): void {
		$this->expectNothingRuns();

		$answer = $this->invoke('publish', [], $this->object('draft'));

		$this->assertSame(Http::STATUS_CONFLICT, $answer['status']);
		$this->assertSame(ObjectActionRunner::PRECONDITION_UNMET, $answer['body']['error']);
		$this->assertSame('Alleen een vastgesteld besluit.', $answer['body']['message']);
	}//end testAPreconditionRefusalIs409WithTheDeclaredMessage()

	public function testInvalidInputsAre422NamingTheFieldAndWriteNothing(): void {
		$this->expectNothingRuns();

		$answer = $this->invoke('publish', ['from' => 'tomorrow-ish']);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $answer['status']);
		$this->assertSame('from', $answer['body']['errors'][0]['field']);
	}//end testInvalidInputsAre422NamingTheFieldAndWriteNothing()

	public function testInputsReachTheStepsRunAndTheRunIsAudited(): void {
		$this->flows->expects($this->once())->method('runForAction')
			->with('steps-flow', $this->anything(), ['action' => 'publish', 'inputs' => ['from' => '2026-11-01']], true)
			->willReturn($this->flowRun(FlowRun::STATUS_COMPLETED));
		$this->audit->expects($this->once())->method('record')->with(
			$this->anything(),
			'publish',
			'steps',
			'run-9',
			$this->stringStartsWith('sha256:'),
			[['step' => 'Datum gezet', 'kind' => 'write']],
			'completed',
			'anna'
		);

		$answer = $this->invoke('publish', ['from' => '2026-11-01']);

		$this->assertSame(Http::STATUS_OK, $answer['status']);
		$this->assertSame('run-9', $answer['body']['run']);
		$this->assertSame('/api/flow-runs/run-9', $answer['body']['links']['run']);
	}//end testInputsReachTheStepsRunAndTheRunIsAudited()

	public function testADefaultIsResolvedBeforeValidation(): void {
		$this->flows->expects($this->once())->method('runForAction')->willReturnCallback(
			function (string $uuid, array $subject, array $context): FlowRun {
				$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $context['inputs']['from']);
				return $this->flowRun(FlowRun::STATUS_COMPLETED);
			}
		);

		$this->assertSame(Http::STATUS_OK, $this->invoke('publish')['status']);
	}//end testADefaultIsResolvedBeforeValidation()

	public function testAFailedStepsRunIs422NamingTheError(): void {
		$run = $this->flowRun(FlowRun::STATUS_FAILED);
		$run->setError('Step 1 failed: not-available');
		$this->flows->method('runForAction')->willReturn($run);

		$answer = $this->invoke('publish', ['from' => '2026-11-01']);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $answer['status']);
		$this->assertSame('Step 1 failed: not-available', $answer['body']['error']);
	}//end testAFailedStepsRunIs422NamingTheError()

	public function testAnActionOverATransitionKeepsTheTransitionsGuards(): void {
		$this->transitions->expects($this->once())->method('transition')
			->with('obj-1', 'finish', [])
			->willThrowException(new RuntimeException('The decision has no signature.'));

		$answer = $this->invoke('finish');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $answer['status']);
		$this->assertSame('The decision has no signature.', $answer['body']['error']);
	}//end testAnActionOverATransitionKeepsTheTransitionsGuards()

	public function testATransitionRunsSyncAndAnswersTheObject(): void {
		$saved = $this->object('done');
		$this->transitions->expects($this->once())->method('transition')->willReturn($saved);
		$this->audit->expects($this->once())->method('record')->with($saved, 'finish', 'transition', null);

		$answer = $this->invoke('finish');

		$this->assertSame(Http::STATUS_OK, $answer['status']);
		$this->assertSame('list', $answer['body']['next']);
		$this->assertNull($answer['body']['run']);
	}//end testATransitionRunsSyncAndAnswersTheObject()

	public function testAnUndeclaredLifecycleTransitionRunsUnderTheUpdateRight(): void {
		$schema = $this->schema();
		$this->transitions->method('declaredInputs')->willReturn([]);
		$this->transitions->expects($this->once())->method('transition')->with('obj-1', 'reopen', ['reason' => 'x'])->willReturn($this->object());
		$asked = [];
		$this->rights = [];
		$this->permissions = $this->createMock(PermissionHandler::class);
		$this->permissions->method('hasPermission')->willReturnCallback(
			function ($schema, string $action) use (&$asked): bool {
				$asked[] = $action;
				return true;
			}
		);

		$answer = $this->runner()->run($this->object(), $schema, 'reopen', ['reason' => 'x'], []);

		$this->assertSame(Http::STATUS_OK, $answer['status']);
		$this->assertSame(['update'], $asked);
	}//end testAnUndeclaredLifecycleTransitionRunsUnderTheUpdateRight()

	public function testAnAsyncActionAnswers202WithItsRun(): void {
		$this->flows->expects($this->once())->method('runForAction')->with('steps-flow', $this->anything(), $this->anything(), false)
			->willReturn($this->flowRun(FlowRun::STATUS_QUEUED));

		$answer = $this->invoke('export');

		$this->assertSame(Http::STATUS_ACCEPTED, $answer['status']);
		$this->assertSame('queued', $answer['body']['status']);
		$this->assertSame('/api/flow-runs/run-9', $answer['body']['links']['run']);
	}//end testAnAsyncActionAnswers202WithItsRun()

	public function testASuspendedSyncRunDoesNotClaimCompletion(): void {
		$this->flows->method('run')->willReturn($this->flowRun(FlowRun::STATUS_SUSPENDED));

		$answer = $this->invoke('close-and-notify');

		$this->assertSame(Http::STATUS_ACCEPTED, $answer['status']);
		$this->assertSame('suspended', $answer['body']['status']);
	}//end testASuspendedSyncRunDoesNotClaimCompletion()

	public function testAnExistingMacroRunsItsFlowAsBefore(): void {
		$this->flows->expects($this->never())->method('runForAction');
		$this->flows->expects($this->once())->method('run')
			->with('0f6a1b2c-0000-4000-8000-000000000001', ['uuid' => 'obj-1', 'register' => 'r', 'schema' => 'besluit'], ['action' => 'close-and-notify', 'inputs' => []], true)
			->willReturn($this->flowRun(FlowRun::STATUS_COMPLETED));

		$answer = $this->invoke('close-and-notify');

		$this->assertSame(Http::STATUS_OK, $answer['status']);
		$this->assertSame('flow', $answer['body']['kind']);
	}//end testAnExistingMacroRunsItsFlowAsBefore()

	public function testAnElevatingActionOpensItsElevationAroundTheBodyOnly(): void {
		$this->elevation->expects($this->once())->method('resolve')
			->with([['register' => 'publication', 'schema' => 'publication', 'verbs' => ['create']]])
			->willReturn([['register' => '4', 'schema' => '9', 'verbs' => ['create']]]);
		$during = null;
		$this->flows->method('runForAction')->willReturnCallback(
			function () use (&$during): FlowRun {
				$during = ObjectActionElevationScope::allows(schemaId: 9, registerId: '4', verb: 'create');
				return $this->flowRun(FlowRun::STATUS_COMPLETED);
			}
		);

		$this->assertSame(Http::STATUS_OK, $this->invoke('release')['status']);
		$this->assertTrue($during, 'the listed write was not elevated during the run');
		$this->assertFalse(ObjectActionElevationScope::allows(schemaId: 9, registerId: '4', verb: 'create'), 'the elevation outlived the run');
	}//end testAnElevatingActionOpensItsElevationAroundTheBodyOnly()

	public function testARefusedActionNeverOpensItsElevation(): void {
		$this->rights['release'] = false;
		$this->elevation->expects($this->never())->method('resolve');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->invoke('release')['status']);
	}//end testARefusedActionNeverOpensItsElevation()

	public function testAWriteOnlyInputNeverReachesTheStoredContextOrTheDigest(): void {
		$seen = [];
		$this->flows->method('runForAction')->willReturnCallback(
			function (string $uuid, array $subject, array $context) use (&$seen): FlowRun {
				$seen = $context;
				$seen['held'] = ObjectActionSecrets::read($context[ObjectActionSecrets::CONTEXT_KEY] ?? null);
				return $this->flowRun(FlowRun::STATUS_COMPLETED);
			}
		);
		$digests = [];
		$this->audit->method('record')->willReturnCallback(
			function ($object, $action, $kind, $run, string $digest) use (&$digests): void {
				$digests[] = $digest;
			}
		);

		$this->invoke('sign', ['pin' => '1234', 'note' => 'ok']);

		$this->assertSame(['note' => 'ok'], $seen['inputs']);
		$this->assertSame(['pin' => '1234'], $seen['held']);
		$this->assertSame([], ObjectActionSecrets::read($seen[ObjectActionSecrets::CONTEXT_KEY]));
		$this->assertSame('sha256:' . hash('sha256', (string)json_encode(['note' => 'ok'])), $digests[0]);
	}//end testAWriteOnlyInputNeverReachesTheStoredContextOrTheDigest()
}//end class

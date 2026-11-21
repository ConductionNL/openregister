<?php

/**
 * OpenRegister TransitionAction tests
 *
 * The bulk lifecycle move: each member through the single-move engine call as
 * the job's actor; applied, skipped when already in the target state, failed
 * with the single move's message when the lifecycle refuses; the preview asks
 * the available actions and changes nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/records-bulk-transition/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BulkAction;

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkObjectAccess;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\BulkAction\TransitionAction;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The engine is the boundary: it is the single move, tested on its own.
 *
 * @covers \OCA\OpenRegister\BulkAction\TransitionAction
 * @uses   \OCA\OpenRegister\BulkAction\BulkActionResult
 * @uses   \OCA\OpenRegister\BulkAction\BulkObjectAccess
 * @uses   \OCA\OpenRegister\BulkAction\BulkStep
 */
class TransitionActionTest extends TestCase {

	private TransitionEngine&MockObject $engine;

	private ObjectService&MockObject $objects;

	private TransitionAction $action;

	/**
	 * Who the preview ran as, in order.
	 *
	 * @var array<int, string>
	 */
	private array $ranAs = [];

	protected function setUp(): void {
		parent::setUp();

		$this->engine  = $this->createMock(TransitionEngine::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$this->ranAs[] = $user->getUID();
				return $operation();
			}
		);

		$schema = new Schema();
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'transitions' => [
						'start' => ['from' => ['ontvangen'], 'to' => 'in behandeling'],
					],
				],
			]
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$this->action = new TransitionAction($this->engine, new BulkObjectAccess($this->objects, $schemas, new NullLogger()));
	}//end setUp()

	/**
	 * An aanvraag in a state.
	 *
	 * @param string $status The state.
	 *
	 * @return ObjectEntity
	 */
	private function aanvraag(string $status): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('a-1');
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setObject(['status' => $status]);

		return $object;
	}//end aanvraag()

	/**
	 * The case handler who started the job.
	 *
	 * @return IUser
	 */
	private function handler(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');

		return $user;
	}//end handler()

	/**
	 * A step of the job.
	 *
	 * @param ObjectEntity         $object The member.
	 * @param bool                 $commit Commit or preview.
	 * @param array<string, mixed> $data   The inputs.
	 *
	 * @return BulkStep
	 */
	private function step(ObjectEntity $object, bool $commit, array $data = []): BulkStep {
		return new BulkStep($object, ['action' => 'start', 'data' => $data], $commit, $this->handler());
	}//end step()

	public function testIdLabelAndGuards(): void {
		$this->assertSame('openregister:transition', $this->action->getId());
		$this->assertSame([BulkActionInterface::GUARD_HOMOGENEITY], $this->action->getGuards());
		$this->assertFalse($this->action->requiresJustification());
		$this->assertStringContainsString('Cannot be undone', $this->action->getDescription());
	}//end testIdLabelAndGuards()

	/**
	 * A member is moved through the engine with the job's data, as the job's actor.
	 *
	 * @return void
	 */
	public function testACommittedMoveGoesThroughTheEngineAsTheActor(): void {
		$step = $this->step(object: $this->aanvraag(status: 'ontvangen'), commit: true, data: ['toelichting' => 'gestart']);
		$this->engine->expects($this->once())->method('transition')
			->with('a-1', 'start', ['toelichting' => 'gestart'], $step->actor)
			->willReturn($this->aanvraag(status: 'in behandeling'));

		$result = $this->action->apply($step);

		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome());
	}//end testACommittedMoveGoesThroughTheEngineAsTheActor()

	/**
	 * A member already in the target state is skipped, in preview and commit, and not moved.
	 *
	 * @return void
	 */
	public function testAMemberAlreadyThereIsSkipped(): void {
		$this->engine->expects($this->never())->method('transition');
		$this->engine->expects($this->never())->method('availableActions');

		foreach ([true, false] as $commit) {
			$result = $this->action->apply($this->step(object: $this->aanvraag(status: 'in behandeling'), commit: $commit));
			$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
			$this->assertSame('the object is already in state "in behandeling"', $result->getReason());
		}
	}//end testAMemberAlreadyThereIsSkipped()

	/**
	 * Each refusal of the single move fails the member with the single move's message.
	 *
	 * @return void
	 */
	public function testARefusedMoveFailsWithTheEnginesMessage(): void {
		$refusals = [
			'not available' => new RuntimeException('Transition "start" is not allowed from current state "afgewezen".'),
			'missing input' => new InvalidTransitionInputException('Missing required transition input(s): reden', ['reden']),
			'stopped by a listener' => new HookStoppedException('The case type is closed for new work.'),
			'no update right' => new NotAuthorizedException(message: 'You do not have permission to transition object "a-1".'),
		];
		$this->engine->method('transition')->willReturnOnConsecutiveCalls(
			...array_map(fn (\Exception $e) => $this->throwException($e), array_values($refusals))
		);

		foreach ($refusals as $case => $exception) {
			$result = $this->action->apply($this->step(object: $this->aanvraag(status: 'ontvangen'), commit: true));
			$this->assertSame(BulkJobMember::OUTCOME_FAILED, $result->getOutcome(), $case);
			$this->assertSame($exception->getMessage(), $result->getReason(), $case);
		}
	}//end testARefusedMoveFailsWithTheEnginesMessage()

	/**
	 * The preview lists a member as available when the actor may start it, and as
	 * failed when the action is not offered or is blocked; nothing is moved.
	 *
	 * @return void
	 */
	public function testThePreviewAsksTheAvailableActionsAsTheActor(): void {
		$this->engine->expects($this->never())->method('transition');
		$this->engine->method('availableActions')->with('a-1')->willReturnOnConsecutiveCalls(
			[['action' => 'reject', 'to' => 'afgewezen'], ['action' => 'start', 'to' => 'in behandeling']],
			[['action' => 'reject', 'to' => 'afgewezen']],
			[['action' => 'start', 'to' => 'in behandeling', 'blocked' => true]],
			$this->throwException(new NotAuthorizedException(message: 'You do not have permission to read object "a-1".'))
		);

		$available = $this->action->apply($this->step(object: $this->aanvraag(status: 'ontvangen'), commit: false));
		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $available->getOutcome());

		$notOffered = $this->action->apply($this->step(object: $this->aanvraag(status: 'ontvangen'), commit: false));
		$this->assertSame(BulkJobMember::OUTCOME_FAILED, $notOffered->getOutcome());
		$this->assertSame('"start" is not available in state "ontvangen"', $notOffered->getReason());

		$blocked = $this->action->apply($this->step(object: $this->aanvraag(status: 'ontvangen'), commit: false));
		$this->assertSame(BulkJobMember::OUTCOME_FAILED, $blocked->getOutcome());

		$unreadable = $this->action->apply($this->step(object: $this->aanvraag(status: 'ontvangen'), commit: false));
		$this->assertSame('You do not have permission to read object "a-1".', $unreadable->getReason());

		$this->assertSame(['handler', 'handler', 'handler', 'handler'], $this->ranAs);
	}//end testThePreviewAsksTheAvailableActionsAsTheActor()

	/**
	 * A job without an action, or with data that is not an object, is refused before it starts.
	 *
	 * @return void
	 */
	public function testParametersAreChecked(): void {
		$this->action->validateParameters(['action' => 'start']);
		$this->action->validateParameters(['action' => 'start', 'data' => ['reden' => 'x']]);

		foreach ([[], ['action' => '  '], ['action' => 'start', 'data' => 'reden'], ['action' => 'start', 'data' => ['x', 'y']]] as $parameters) {
			try {
				$this->action->validateParameters($parameters);
				$this->fail('Accepted '.json_encode($parameters));
			} catch (InvalidArgumentException $refused) {
				$this->assertStringContainsString('openregister:transition', $refused->getMessage());
			}
		}
	}//end testParametersAreChecked()
}//end class

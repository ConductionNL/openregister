<?php

/**
 * Task, form, event and exception classes consuming apps construct or call by name keep their published arguments.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use DateTime;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxAnchor;
use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskInboxDueWindow;
use OCA\OpenRegister\Db\TaskInboxFilter;
use OCA\OpenRegister\Db\TaskInboxOrder;
use OCA\OpenRegister\Db\TaskInboxSelection;
use OCA\OpenRegister\Event\DecisionChangeRequestedEvent;
use OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent;
use OCA\OpenRegister\Event\FlowEmailItem;
use OCA\OpenRegister\Event\FlowEmailMessage;
use OCA\OpenRegister\Event\FlowEmailOrigin;
use OCA\OpenRegister\Event\FlowEmailSentEvent;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Kernel\Contracts\ReviewPolicyInterface;
use OCA\OpenRegister\Service\Decision\DecisionQuestion;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCA\OpenRegister\Service\Form\FormDestination;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormFieldRules;
use OCA\OpenRegister\Service\Form\PublishedForm;
use OCA\OpenRegister\Service\Task\FormCompletionRequest;
use OCA\OpenRegister\Service\Task\TaskAct;
use OCA\OpenRegister\Service\Task\TaskAnswer;
use OCA\OpenRegister\Service\Task\TaskFormCompletion;
use OCA\OpenRegister\Service\Task\TaskFormResolver;
use OCA\OpenRegister\Service\Task\TaskFormSubjectWriter;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskFormInlineSave;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Consuming apps build these with named arguments (dossiq builds TaskInboxCriteria by
 * spreading a named array into it, and FormDependent inside a catch-and-log, so a
 * renamed parameter there fails silently). Each test pins the names published in
 * v2.1.47 and checks that the published form and the value-object form give the same
 * result.
 */
class LegacyValueObjectArgumentsTest extends TestCase {

	/**
	 * The published parameter names, in order, per method.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: list<string>}>
	 */
	public static function publishedParameters(): array {
		return [
			'TaskFormCompletion::complete'     => [TaskFormCompletion::class, 'complete', ['uuid', 'outcome', 'resultText', 'comment', 'data', 'actor', 'answer', 'request']],
			'TaskInboxCriteria::__construct'   => [
				TaskInboxCriteria::class,
				'__construct',
				[
					'uid', 'groupIds', 'isAdmin', 'scope', 'states', 'isTerminal', 'priority', 'objectUuid', 'runUuid', 'overdueAt',
					'dueAfter', 'dueBefore', 'sort', 'sortDescending', 'kind', 'includeBlocked', 'openFirst', 'term', 'audience', 'selection',
				],
			],
			'FlowEmailSentEvent::__construct'  => [
				FlowEmailSentEvent::class,
				'__construct',
				['register', 'schema', 'objectUuid', 'recipient', 'channelKind', 'subject', 'body', 'flowId', 'runId', 'stepName', 'actingUser', 'item', 'message', 'origin'],
			],
			'FormDestinationValidator::validate' => [FormDestinationValidator::class, 'validate', ['mapping', 'schema', 'options', 'destination']],
			'HookStoppedException::__construct'  => [HookStoppedException::class, '__construct', ['message', 'errors', 'code', 'previous']],
			'FormDependent::__construct'         => [FormDependent::class, '__construct', ['id', 'app', 'title', 'author', 'mapping', 'unpublish', 'audience', 'form']],
		];
	}//end publishedParameters()

	/**
	 * The published parameter names stay, in their published order.
	 *
	 * @param class-string $class  The class.
	 * @param string       $method The method.
	 * @param list<string> $names  The parameter names, in order.
	 *
	 * @return void
	 */
	#[DataProvider('publishedParameters')]
	public function testThePublishedParameterNamesAreStillThere(string $class, string $method, array $names): void {
		$this->assertSame(
			$names,
			array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod($class, $method))->getParameters()),
			"{$class}::{$method}() must keep the parameter names consuming apps call it with."
		);
	}//end testThePublishedParameterNamesAreStillThere()

	/**
	 * A subject writer whose collaborators are doubles.
	 *
	 * @return TaskFormSubjectWriter
	 */
	private function writer(): TaskFormSubjectWriter {
		return new TaskFormSubjectWriter(
			engine: $this->createMock(TransitionEngine::class),
			inline: new TaskFormInlineSave(
				objects: $this->createMock(ObjectService::class),
				userSession: $this->createMock(IUserSession::class),
				runContext: new FlowRunContext()
			)
		);
	}//end writer()

	public function testTaskCompletionGivesTheSameCallInEveryForm(): void {
		$task  = new Task();
		$tasks = $this->createMock(TaskService::class);
		$tasks->method('authorizedOpenTask')->with(new TaskAct(verb: 'complete', actor: 'alice'), 't-7')->willReturn($task);
		$tasks->expects($this->exactly(5))->method('completeWithAnswer')
			->with('t-7', new TaskAnswer(outcome: 'done', resultText: 'r', comment: 'c'), 'alice')
			->willReturn($task);
		$forms = $this->createMock(TaskFormResolver::class);
		$forms->method('describe')->willReturn(['form' => null, 'requireChecklist' => false]);
		$completion = new TaskFormCompletion(tasks: $tasks, forms: $forms, writer: $this->writer());

		$answer  = new TaskAnswer(outcome: 'done', resultText: 'r', comment: 'c');
		$request = new FormCompletionRequest(uuid: 't-7', answer: $answer, data: []);

		$completion->complete('t-7', 'done', 'r', 'c', [], 'alice');
		$completion->complete(uuid: 't-7', outcome: 'done', resultText: 'r', comment: 'c', data: [], actor: 'alice');
		$completion->complete(uuid: 't-7', answer: $answer, data: [], actor: 'alice');
		$completion->complete(request: $request, actor: 'alice');
		$completion->complete($request, 'alice');
	}

	public function testTaskCompletionWithoutAnOutcomeIsRefused(): void {
		$completion = new TaskFormCompletion(
			tasks: $this->createMock(TaskService::class),
			forms: $this->createMock(TaskFormResolver::class),
			writer: $this->writer()
		);

		$this->expectException(\InvalidArgumentException::class);
		$completion->complete(uuid: 't-7', actor: 'alice');
	}

	public function testInboxCriteriaFromNamedCriteriaEqualsTheValueObjectForm(): void {
		$overdue = new DateTime('2026-11-01T00:00:00+00:00');
		$named   = [
			'uid'            => 'alice',
			'groupIds'       => ['reviewers'],
			'isAdmin'        => true,
			'scope'          => TaskInboxCriteria::SCOPE_ALL,
			'states'         => ['active'],
			'isTerminal'     => false,
			'priority'       => 'high',
			'objectUuid'     => 'obj-1',
			'runUuid'        => 'run-1',
			'overdueAt'      => $overdue,
			'sort'           => TaskInboxCriteria::SORT_PRIORITY,
			'sortDescending' => true,
			'kind'           => 'onboarding',
			'includeBlocked' => true,
			'openFirst'      => true,
		];

		$audience  = new TaskInboxAudience(uid: 'alice', groupIds: ['reviewers'], isAdmin: true, scope: TaskInboxCriteria::SCOPE_ALL);
		$selection = new TaskInboxSelection(
			filter: new TaskInboxFilter(states: ['active'], isTerminal: false, priority: 'high', kind: 'onboarding'),
			window: new TaskInboxDueWindow(overdueAt: $overdue),
			anchor: new TaskInboxAnchor(objectUuid: 'obj-1', runUuid: 'run-1', includeBlocked: true),
			order: new TaskInboxOrder(sort: TaskInboxCriteria::SORT_PRIORITY, sortDescending: true, openFirst: true)
		);

		$expected = new TaskInboxCriteria(audience: $audience, selection: $selection);
		$this->assertEquals($expected, new TaskInboxCriteria(...$named));
		$this->assertEquals($expected, new TaskInboxCriteria($audience, $selection));
		$this->assertEquals(new TaskInboxCriteria(audience: new TaskInboxAudience(uid: 'bob')), new TaskInboxCriteria(uid: 'bob'));
	}

	public function testInboxCriteriaWithoutACallerIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		new TaskInboxCriteria(isAdmin: true);
	}

	public function testFlowEmailEventFromSeparateValuesReadsBackEveryGetter(): void {
		$legacy = new FlowEmailSentEvent(
			register: 'r',
			schema: 's',
			objectUuid: 'o',
			recipient: 'alice',
			channelKind: FlowEmailSentEvent::KIND_USER,
			subject: 'Hi',
			body: 'Body',
			flowId: 'f',
			runId: 'run',
			stepName: 'mail',
			actingUser: 'system'
		);
		$item    = new FlowEmailItem(register: 'r', schema: 's', objectUuid: 'o');
		$message = new FlowEmailMessage(recipient: 'alice', channelKind: FlowEmailSentEvent::KIND_USER, subject: 'Hi', body: 'Body');
		$origin  = new FlowEmailOrigin(flowId: 'f', runId: 'run', stepName: 'mail', actingUser: 'system');

		foreach ([new FlowEmailSentEvent(item: $item, message: $message, origin: $origin), new FlowEmailSentEvent($item, $message, $origin)] as $event) {
			foreach (['getRegister', 'getSchema', 'getObjectUuid', 'getRecipient', 'getChannelKind', 'getSubject', 'getBody', 'getFlowId', 'getRunId', 'getStepName', 'getActingUser'] as $getter) {
				$this->assertSame($legacy->{$getter}(), $event->{$getter}(), $getter);
			}
		}

		$this->assertSame(['r', 's', 'o', 'alice', 'user', 'Hi', 'Body', 'f', 'run', 'mail', 'system'], [
			$legacy->getRegister(), $legacy->getSchema(), $legacy->getObjectUuid(), $legacy->getRecipient(), $legacy->getChannelKind(),
			$legacy->getSubject(), $legacy->getBody(), $legacy->getFlowId(), $legacy->getRunId(), $legacy->getStepName(), $legacy->getActingUser(),
		]);
	}

	public function testDestinationValidatorGivesTheSameFindingsInEveryForm(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : vsprintf($text, $parameters)
		);
		$validator = new FormDestinationValidator(l10n: $l10n, rules: new FormFieldRules());

		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setRequired(['title']);
		$schema->setProperties(['title' => ['type' => 'string', 'maxLength' => 10]]);
		$mapping = ['fields' => [['field' => 'onderwerp', 'property' => 'missing', 'type' => 'text']]];

		$expected = $validator->validate(mapping: $mapping, destination: new FormDestination(schema: $schema, audience: 'public'));

		$this->assertNotSame([], $expected);
		$this->assertSame($expected, $validator->validate(mapping: $mapping, schema: $schema, options: ['audience' => 'public']));
		$this->assertSame($expected, $validator->validate($mapping, $schema, ['audience' => 'public']));
		$this->assertSame($expected, $validator->validate($mapping, new FormDestination(schema: $schema, audience: 'public')));
		$this->assertSame(
			$validator->validate(mapping: $mapping, destination: new FormDestination(schema: $schema)),
			$validator->validate(mapping: $mapping, schema: $schema)
		);
	}

	public function testHookStoppedExceptionKeepsCodeAndPrevious(): void {
		$previous = new RuntimeException('cause');
		$stopped  = new HookStoppedException(message: 'stopped', errors: ['e' => 1], code: 409, previous: $previous);

		$this->assertSame(['e' => 1], $stopped->getErrors());
		$this->assertSame(409, $stopped->getCode());
		$this->assertSame($previous, $stopped->getPrevious());
		$this->assertSame(0, (new HookStoppedException('stopped'))->getCode());
	}

	public function testFormDependentFromIdAppAndTitleEqualsThePublishedFormForm(): void {
		$unpublish = static function (): void {
		};

		$legacy = new FormDependent(id: 'case-1', app: 'dossiq', title: 'Intake', author: 'alice', mapping: ['fields' => []], unpublish: $unpublish, audience: 'public');
		$new    = new FormDependent(
			form: new PublishedForm(id: 'case-1', app: 'dossiq', title: 'Intake', audience: 'public'),
			author: 'alice',
			mapping: ['fields' => []],
			unpublish: $unpublish
		);

		$this->assertEquals($new, $legacy);
		$this->assertSame(['case-1', 'dossiq', 'Intake', 'public'], [$legacy->id, $legacy->app, $legacy->title, $legacy->audience]);
		$this->assertEquals($new, new FormDependent(new PublishedForm('case-1', 'dossiq', 'Intake', 'public'), author: 'alice', mapping: ['fields' => []], unpublish: $unpublish));
		$this->assertEquals(
			new FormDependent(form: new PublishedForm(id: 'f', app: 'a', title: 't'), author: null, mapping: []),
			new FormDependent('f', 'a', 't', null, [])
		);
	}

	public function testFormDependentWithoutATitleIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		new FormDependent(id: 'case-1', app: 'dossiq', author: null, mapping: []);
	}

	public function testDecisionRequestEventsStillHandOutAReviewPolicy(): void {
		$this->assertContains(ReviewPolicyInterface::class, class_implements(ReviewPolicy::class));

		$question = (new ReflectionMethod(DecisionQuestion::class, '__construct'))->getParameters()[1];
		$this->assertSame(ReviewPolicy::class, (string) $question->getType(), 'Every producer passes the DecisionQuestion policy, so it must stay a ReviewPolicy.');

		$policy     = ReviewPolicy::fromDeclaration(['ownership' => 'decider']);
		$change     = new DecisionChangeRequestedEvent(decision: new Decision(), actorId: 'alice', policy: $policy, subject: [], allowed: false);
		$visibility = new DecisionVisibilityRequestedEvent(decision: null, actorId: 'alice', policy: $policy, subject: []);

		$this->assertInstanceOf(ReviewPolicy::class, $change->getPolicy());
		$this->assertInstanceOf(ReviewPolicy::class, $visibility->getPolicy());
		$this->assertSame($policy, $change->getPolicy());
	}
}//end class

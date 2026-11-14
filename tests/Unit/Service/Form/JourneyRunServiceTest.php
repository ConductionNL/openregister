<?php

/**
 * A journey run commits only at a writing step, through the submit path, and scopes its answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Form\FormSubmitServiceInterface;
use OCA\OpenRegister\Service\Form\FormSubmitter;
use OCA\OpenRegister\Service\Form\JourneyRunService;
use OCA\OpenRegister\Service\Form\LocalConditionEvaluator;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Start, answer and save a run over a stored journey with a mocked submit path.
 *
 * The all-or-none deletion of a step's earlier writes is FormSubmitService's
 * own and is covered by FormSubmitServiceTest::testARefusedSecondWriteDeletesTheFirst;
 * this test pins that a run reaches it only at a writing step, with the
 * step's writes in declared order, and passes its refusal on unchanged.
 *
 * @covers \OCA\OpenRegister\Service\Form\JourneyRunService
 * @uses \OCA\OpenRegister\Service\Form\JourneyDefinition
 * @uses \OCA\OpenRegister\Service\Form\LocalConditionEvaluator
 * @uses \OCA\OpenRegister\Service\Form\FormSubmission
 * @uses \OCA\OpenRegister\Service\Form\FormSubmitter
 * @uses \OCA\OpenRegister\Exception\FormSubmitRefusedException
 * @uses \OCA\OpenRegister\Service\Object\DraftOwner
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 */
class JourneyRunServiceTest extends TestCase {

	/**
	 * The organisation write of the registration step.
	 *
	 * @var array<string, mixed>
	 */
	private const ORGANISATION_WRITE = [
		'register' => 'crm',
		'schema' => 'organisation',
		'mapping' => ['fields' => [['field' => 'orgName', 'property' => 'name']]],
		'as' => 'organisation',
	];

	/**
	 * The contact write, referencing the organisation's id.
	 *
	 * @var array<string, mixed>
	 */
	private const CONTACT_WRITE = [
		'register' => 'crm',
		'schema' => 'contact',
		'mapping' => [
			'fields' => [['field' => 'email', 'property' => 'email']],
			'fixed' => ['organisation' => ['$write' => 'organisation']],
		],
		'as' => 'contact',
	];

	/**
	 * The submit path.
	 *
	 * @var FormSubmitServiceInterface&MockObject
	 */
	private FormSubmitServiceInterface&MockObject $submits;

	/**
	 * Mock the submit path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->submits = $this->createMock(FormSubmitServiceInterface::class);
	}//end setUp()

	/**
	 * A three-step registration journey: about, organisation (writes), confirmation.
	 *
	 * @param string $access The declared access.
	 *
	 * @return array<string, mixed> The journey body.
	 */
	private function registration(string $access='anonymous'): array {
		return [
			'status' => 'published',
			'access' => $access,
			'steps' => [
				[
					'id' => 'about',
					'type' => 'form',
					'fields' => ['kind', ['key' => 'reason']],
					'next' => [['when' => ['field' => 'kind', 'value' => 'none'], 'step' => 'done']],
				],
				['id' => 'organisation', 'type' => 'form', 'fields' => ['email'], 'writes' => [self::ORGANISATION_WRITE, self::CONTACT_WRITE]],
				['id' => 'done', 'type' => 'confirmation'],
			],
		];
	}//end registration()

	/**
	 * A service over one stored journey body (null: no object).
	 *
	 * @param array<string, mixed>|null $body The stored journey body.
	 *
	 * @return JourneyRunService The service.
	 */
	private function service(?array $body): JourneyRunService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function () use ($body): ?ObjectEntity {
				if ($body === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('journey-1');
				$entity->setObject($body);

				return $entity;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => vsprintf(str_replace(['%1$s', '%2$s'], '%s', $text), $parameters)
		);

		return new JourneyRunService(objects: $objects, submits: $this->submits, conditions: new LocalConditionEvaluator(), l10n: $l10n);
	}//end service()

	/**
	 * The refusal a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return FormSubmitRefusedException The refusal.
	 */
	private function refusal(callable $call): FormSubmitRefusedException {
		try {
			$call();
		} catch (FormSubmitRefusedException $refusal) {
			return $refusal;
		}

		$this->fail('Expected a refusal.');
	}//end refusal()

	/**
	 * Starting names the first step and its fields.
	 *
	 * @return void
	 */
	public function testStartNamesTheFirstStepAndItsFields(): void {
		$started = $this->service(body: $this->registration())->start(journeyId: 'journey-1', submitter: new FormSubmitter());

		$this->assertSame(['journey' => 'journey-1', 'step' => 'about', 'fields' => ['kind', 'reason']], $started);
	}//end testStartNamesTheFirstStepAndItsFields()

	/**
	 * Advancing past a step without writes reaches neither submit nor draft.
	 *
	 * @return void
	 */
	public function testAStepWithoutWritesWritesNothing(): void {
		$this->submits->expects($this->never())->method('submit');
		$this->submits->expects($this->never())->method('saveDraft');

		$outcome = $this->service(body: $this->registration())->answer(
			journeyId: 'journey-1',
			stepId: 'about',
			answers: ['kind' => 'company', 'reason' => 'trade'],
			submitter: new FormSubmitter()
		);

		$this->assertNull($outcome['committed']);
		$this->assertSame('organisation', $outcome['next']);
		$this->assertSame(['email', 'orgName'], $outcome['fields']);
	}//end testAStepWithoutWritesWritesNothing()

	/**
	 * A next rule whose condition holds jumps to its step.
	 *
	 * @return void
	 */
	public function testANextRuleBranches(): void {
		$outcome = $this->service(body: $this->registration())->answer(
			journeyId: 'journey-1',
			stepId: 'about',
			answers: ['kind' => 'none'],
			submitter: new FormSubmitter()
		);

		$this->assertSame('done', $outcome['next']);
		$this->assertSame([], $outcome['fields']);
	}//end testANextRuleBranches()

	/**
	 * The writing step hands its writes, in declared order, to the submit path, scoped to the journey.
	 *
	 * @return void
	 */
	public function testAWritingStepCommitsItsWritesInDeclaredOrder(): void {
		$subject   = $this->createMock(IUser::class);
		$submitted = null;
		$this->submits->expects($this->once())->method('submit')->willReturnCallback(
			static function (FormSubmission $submission, ?string $idempotencyKey=null) use (&$submitted): array {
				$submitted = [$submission, $idempotencyKey];

				return ['id' => 'org-1', 'objects' => [['as' => 'organisation', 'id' => 'org-1'], ['as' => 'contact', 'id' => 'contact-1']]];
			}
		);

		$answers = ['kind' => 'company', 'orgName' => 'Acme', 'email' => 'a@acme.nl'];
		$outcome = $this->service(body: $this->registration(access: 'authenticated'))->answer(
			journeyId: 'journey-1',
			stepId: 'organisation',
			answers: $answers,
			submitter: new FormSubmitter(subject: $subject, scope: 'ignored', draftId: 'draft-9'),
			idempotencyKey: 'key-1'
		);

		[$submission, $key] = $submitted;
		$this->assertSame([self::ORGANISATION_WRITE, self::CONTACT_WRITE], $submission->writes);
		$this->assertSame($answers, $submission->payload);
		$this->assertSame('journey-1', $submission->scope);
		$this->assertSame('draft-9', $submission->draftId);
		$this->assertSame($subject, $submission->subject);
		$this->assertSame('key-1', $key);
		$this->assertSame('org-1', $outcome['committed']['id']);
		$this->assertSame('done', $outcome['next']);
	}//end testAWritingStepCommitsItsWritesInDeclaredOrder()

	/**
	 * A refused write reaches the caller with its findings; the run claims no commit.
	 *
	 * @return void
	 */
	public function testARefusedWriteReachesTheCallerWithItsFindings(): void {
		$findings = [['field' => 'email', 'code' => 'format', 'message' => 'Not an e-mail address.']];
		$this->submits->method('submit')->willThrowException(new FormSubmitRefusedException(message: 'Refused.', status: 422, findings: $findings));

		$refusal = $this->refusal(
			fn () => $this->service(body: $this->registration())->answer(
				journeyId: 'journey-1',
				stepId: 'organisation',
				answers: ['orgName' => 'Acme', 'email' => 'nope'],
				submitter: new FormSubmitter()
			)
		);

		$this->assertSame(422, $refusal->getStatus());
		$this->assertSame($findings, $refusal->getFindings());
	}//end testARefusedWriteReachesTheCallerWithItsFindings()

	/**
	 * An answer for a later step's field is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnAnswerForALaterStepsFieldIsRefused(): void {
		$this->submits->expects($this->never())->method('submit');

		$refusal = $this->refusal(
			fn () => $this->service(body: $this->registration())->answer(
				journeyId: 'journey-1',
				stepId: 'about',
				answers: ['kind' => 'company', 'email' => 'a@acme.nl'],
				submitter: new FormSubmitter()
			)
		);

		$this->assertSame(422, $refusal->getStatus());
		$this->assertSame('email', $refusal->getFindings()[0]['field']);
		$this->assertSame('out-of-step', $refusal->getFindings()[0]['code']);
		$this->assertCount(1, $refusal->getFindings());
	}//end testAnAnswerForALaterStepsFieldIsRefused()

	/**
	 * Saving part-way saves a draft of the first destination and activates nothing.
	 *
	 * @return void
	 */
	public function testSavingPartWaySavesADraftOfTheFirstDestination(): void {
		$subject = $this->createMock(IUser::class);
		$this->submits->expects($this->never())->method('submit');
		$this->submits->expects($this->once())->method('saveDraft')->with(
			$this->callback(
				static fn (FormSubmission $submission): bool => $submission->writes === [self::ORGANISATION_WRITE]
					&& $submission->payload === ['kind' => 'company']
					&& $submission->scope === 'journey-1'
			)
		)->willReturn(['id' => 'draft-1', 'status' => 'draft']);

		$draft = $this->service(body: $this->registration(access: 'authenticated'))->save(
			journeyId: 'journey-1',
			stepId: 'about',
			answers: ['kind' => 'company'],
			submitter: new FormSubmitter(subject: $subject)
		);

		$this->assertSame(['id' => 'draft-1', 'status' => 'draft'], $draft);
	}//end testSavingPartWaySavesADraftOfTheFirstDestination()

	/**
	 * A journey that writes nothing has nothing to save.
	 *
	 * @return void
	 */
	public function testAJourneyWithoutWritesHasNothingToSave(): void {
		$body = $this->registration();
		unset($body['steps'][1]['writes']);

		$refusal = $this->refusal(
			fn () => $this->service(body: $body)->save(journeyId: 'journey-1', stepId: 'about', answers: [], submitter: new FormSubmitter())
		);

		$this->assertSame(422, $refusal->getStatus());
	}//end testAJourneyWithoutWritesHasNothingToSave()

	/**
	 * Unknown, unpublished and access-less journeys answer the same 404.
	 *
	 * @return void
	 */
	public function testMissingUnpublishedAndAccessLessJourneysLookAlike(): void {
		$unpublished = $this->registration();
		unset($unpublished['status']);
		$accessLess = $this->registration();
		unset($accessLess['access']);
		$unknownAccess = $this->registration(access: 'everyone');

		$answers = [];
		foreach ([null, $unpublished, $accessLess, $unknownAccess] as $body) {
			$refusal   = $this->refusal(fn () => $this->service(body: $body)->start(journeyId: 'journey-1', submitter: new FormSubmitter()));
			$answers[] = [$refusal->getStatus(), $refusal->getMessage(), $refusal->getFindings()];
		}

		$this->assertSame([404, 'This journey does not exist.', []], $answers[0]);
		$this->assertSame([$answers[0], $answers[0], $answers[0]], array_slice($answers, 1));
	}//end testMissingUnpublishedAndAccessLessJourneysLookAlike()

	/**
	 * An authenticated journey refuses an anonymous submitter; minTrust fails closed.
	 *
	 * @return void
	 */
	public function testAccessIsEnforced(): void {
		$anonymous = $this->refusal(
			fn () => $this->service(body: $this->registration(access: 'authenticated'))->start(journeyId: 'journey-1', submitter: new FormSubmitter())
		);
		$trust     = $this->refusal(
			fn () => $this->service(body: $this->registration(access: 'minTrust'))->start(
				journeyId: 'journey-1',
				submitter: new FormSubmitter(subject: $this->createMock(IUser::class))
			)
		);

		$this->assertSame(401, $anonymous->getStatus());
		$this->assertSame(403, $trust->getStatus());
	}//end testAccessIsEnforced()

	/**
	 * An unknown step is a 404.
	 *
	 * @return void
	 */
	public function testAnUnknownStepIsA404(): void {
		$refusal = $this->refusal(
			fn () => $this->service(body: $this->registration())->answer(journeyId: 'journey-1', stepId: 'nope', answers: [], submitter: new FormSubmitter())
		);

		$this->assertSame(404, $refusal->getStatus());
	}//end testAnUnknownStepIsA404()
	/**
	 * Resuming reads the draft's properties back to the fields that wrote them.
	 *
	 * @return void
	 */
	public function testResumeReadsTheAnswersBackThroughTheMapping(): void {
		$owner = JourneyRunService::tokenOwner(token: 'tok')->value();
		$this->submits->expects($this->once())->method('findDraft')->willReturnCallback(
			function (array $destination, FormSubmitter $submitter) use ($owner): array {
				$this->assertSame(self::ORGANISATION_WRITE, $destination);
				$this->assertSame($owner, $submitter->owner?->value());
				$this->assertSame('journey-1', $submitter->scope);

				return ['id' => 'run-1', 'status' => 'draft', 'data' => ['name' => 'Acme', 'website' => 'x'], 'updatedAt' => 'now'];
			}
		);

		$run = $this->service(body: $this->registration())->resume(journeyId: 'journey-1', runId: null, token: 'tok', submitter: new FormSubmitter());

		$this->assertSame(['orgName' => 'Acme'], $run['answers']);
		$this->assertSame('about', $run['step']);
		$this->assertSame(['kind', 'reason'], $run['fields']);
		$this->assertSame('run-1', $run['run']);
	}//end testResumeReadsTheAnswersBackThroughTheMapping()

	/**
	 * The reached step follows the `next` rules over the recorded answers.
	 *
	 * @return void
	 */
	public function testResumeFollowsTheNextRulesToTheReachedStep(): void {
		$body = $this->registration();
		$body['steps'][1]['writes'] = [['register' => 'crm', 'schema' => 'lead', 'mapping' => null]];
		$this->submits->method('findDraft')->willReturn(
			['id' => 'run-2', 'status' => 'draft', 'data' => ['kind' => 'none', 'reason' => 'just looking', '_owner' => 'x', 'id' => 'run-2'], 'updatedAt' => 'now']
		);

		$branched = $this->service(body: $body)->resume(journeyId: 'journey-1', runId: null, token: 'tok', submitter: new FormSubmitter());

		$this->assertSame(['kind' => 'none', 'reason' => 'just looking'], $branched['answers']);
		$this->assertSame('done', $branched['step']);
		$this->assertSame([], $branched['fields']);
	}//end testResumeFollowsTheNextRulesToTheReachedStep()

	/**
	 * An authenticated journey still asks an anonymous caller to sign in; only not-found is folded into one 404.
	 *
	 * @return void
	 */
	public function testResumeKeepsTheSignInRefusal(): void {
		$this->submits->expects($this->never())->method('findDraft');

		$refusal = $this->refusal(
			fn () => $this->service(body: $this->registration(access: 'authenticated'))->resume(journeyId: 'journey-1', runId: null, token: 'tok', submitter: new FormSubmitter())
		);
		$unknown = $this->refusal(
			fn () => $this->service(body: null)->resume(journeyId: 'journey-1', runId: null, token: 'tok', submitter: new FormSubmitter())
		);

		$this->assertSame(401, $refusal->getStatus());
		$this->assertSame(404, $unknown->getStatus());
		$this->assertSame('This run does not exist.', $unknown->getMessage());
	}//end testResumeKeepsTheSignInRefusal()

	/**
	 * The token owner is a hash of the token that fits the owner column, never the token.
	 *
	 * @return void
	 */
	public function testTheTokenOwnerIsAHashOfTheToken(): void {
		$token = str_repeat('a', 43);
		$owner = JourneyRunService::tokenOwner(token: $token)->value();

		$this->assertStringStartsWith('journey-run:', $owner);
		$this->assertStringNotContainsString($token, $owner);
		$this->assertLessThanOrEqual(64, strlen($owner));
		$this->assertSame($owner, JourneyRunService::tokenOwner(token: $token)->value());
		$this->assertNotSame($owner, JourneyRunService::tokenOwner(token: str_repeat('b', 43))->value());
	}//end testTheTokenOwnerIsAHashOfTheToken()

	/**
	 * An anonymous save of an anonymous journey is owned by its new token's hash; the token is answered once.
	 *
	 * @return void
	 */
	public function testAnAnonymousSaveIsOwnedByItsTokensHash(): void {
		$seen = null;
		$this->submits->method('saveDraft')->willReturnCallback(
			function (FormSubmission $submission) use (&$seen): array {
				$seen = $submission->owner?->value();

				return ['id' => 'run-3', 'status' => 'draft'];
			}
		);
		$service = $this->service(body: $this->registration());

		$first  = $service->save(journeyId: 'journey-1', stepId: 'about', answers: ['kind' => 'company'], submitter: new FormSubmitter());
		$second = $service->save(journeyId: 'journey-1', stepId: 'about', answers: ['kind' => 'company'], submitter: new FormSubmitter(draftId: 'run-3'), token: $first['token']);

		$this->assertSame(JourneyRunService::tokenOwner(token: $first['token'])->value(), $seen);
		$this->assertSame(['id' => 'run-3', 'status' => 'draft'], $second);
	}//end testAnAnonymousSaveIsOwnedByItsTokensHash()
}//end class

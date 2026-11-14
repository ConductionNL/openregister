<?php

/**
 * The journey run API: its posture, its oracle-free refusals, its throttling and its ownerless anonymous writes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-anonymous-submission-must-be-throttled-and-must-stamp-no-ownership
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\JourneyRunController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Form\FormSubmitServiceInterface;
use OCA\OpenRegister\Service\Form\FormSubmitter;
use OCA\OpenRegister\Service\Form\JourneyRunService;
use OCA\OpenRegister\Service\Form\LocalConditionEvaluator;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfacesInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The controller over the real run service, with the stored journeys and the submit path mocked.
 *
 * @covers \OCA\OpenRegister\Controller\JourneyRunController
 * @uses \OCA\OpenRegister\Service\Form\JourneyRunService
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
class JourneyRunControllerTest extends TestCase {

	/**
	 * The stored journeys by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $journeys = [];

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The request headers.
	 *
	 * @var array<string, string>
	 */
	private array $headers = [];

	/**
	 * The signed-in user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The attempts the throttler was told of, as `[action, ip]`.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $attempts = [];

	/**
	 * The submit path.
	 *
	 * @var FormSubmitServiceInterface&MockObject
	 */
	private FormSubmitServiceInterface&MockObject $submits;

	/**
	 * The throttler.
	 *
	 * @var IThrottler&MockObject
	 */
	private IThrottler&MockObject $throttler;

	/**
	 * The controller.
	 *
	 * @var JourneyRunController
	 */
	private JourneyRunController $controller;

	/**
	 * Wire the controller over in-memory journeys.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->journeys = [
			'open' => [
				'status' => 'published',
				'access' => 'anonymous',
				'steps' => [
					['id' => 'about', 'type' => 'form', 'fields' => ['name']],
					['id' => 'send', 'type' => 'form', 'fields' => ['email'], 'writes' => [['register' => 'crm', 'schema' => 'contact', 'mapping' => null]]],
				],
			],
			'hidden' => ['status' => 'draft', 'access' => 'anonymous', 'steps' => [['id' => 'about', 'type' => 'form']]],
		];

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($this->params[$key] ?? $default));
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($this->headers[$name] ?? ''));
		$request->method('getRemoteAddress')->willReturn('192.0.2.7');

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (string|int $id): ?ObjectEntity {
				if (isset($this->journeys[(string)$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->journeys[(string)$id]);

				return $entity;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->submits = $this->createMock(FormSubmitServiceInterface::class);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);

		$this->throttler = $this->createMock(IThrottler::class);
		$this->throttler->method('registerAttempt')->willReturnCallback(
			function (string $action, string $ip): void {
				$this->attempts[] = [$action, $ip];
			}
		);

		$this->controller = new JourneyRunController(
			appName: 'openregister',
			request: $request,
			runs: new JourneyRunService(objects: $objects, submits: $this->submits, conditions: new LocalConditionEvaluator(), l10n: $l10n),
			userSession: $session,
			throttler: $this->throttler,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The attribute names on a controller method.
	 *
	 * @param string $method The method.
	 *
	 * @return list<string> The attribute class names.
	 */
	private function attributes(string $method): array {
		return array_map(
			static fn (\ReflectionAttribute $attribute): string => $attribute->getName(),
			(new ReflectionMethod(JourneyRunController::class, $method))->getAttributes()
		);
	}//end attributes()

	/**
	 * The full response, as a comparable value: status, body and headers.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed, 2: array<string, mixed>} The comparable value.
	 */
	private function whole(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData(), $response->getHeaders()];
	}//end whole()

	/**
	 * Start, answer, save and resume are public, rate-limited and brute-force protected.
	 *
	 * @return void
	 */
	public function testEachRouteDeclaresItsPosture(): void {
		foreach (['start', 'answer', 'save', 'resume'] as $method) {
			$this->assertSame(
				[PublicPage::class, NoCSRFRequired::class, AnonRateLimit::class, UserRateLimit::class, BruteForceProtection::class],
				$this->attributes(method: $method),
				$method
			);
			$protection = (new ReflectionMethod(JourneyRunController::class, $method))->getAttributes(BruteForceProtection::class)[0];
			$this->assertSame(['action' => ThrottledSurfacesInterface::JOURNEY_RUN], $protection->getArguments());
		}

		$this->assertContains(ThrottledSurfacesInterface::JOURNEY_RUN, ThrottledSurfacesInterface::ALL);
	}//end testEachRouteDeclaresItsPosture()

	/**
	 * An anonymous visitor starts an anonymous journey.
	 *
	 * @return void
	 */
	public function testAnAnonymousVisitorStartsARun(): void {
		$response = $this->controller->start(journeyId: 'open');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['journey' => 'open', 'step' => 'about', 'fields' => ['name']], $response->getData());
		$this->assertSame([], $this->attempts);
	}//end testAnAnonymousVisitorStartsARun()

	/**
	 * An unpublished journey and an unknown one answer the same full response, and each counts an attempt.
	 *
	 * @return void
	 */
	public function testAnUnpublishedAndAnUnknownJourneyLookIdentical(): void {
		$hidden  = $this->controller->start(journeyId: 'hidden');
		$unknown = $this->controller->start(journeyId: 'nope');

		$this->assertSame(404, $unknown->getStatus());
		$this->assertSame($this->whole(response: $unknown), $this->whole(response: $hidden));
		$this->assertSame(
			[[ThrottledSurfacesInterface::JOURNEY_RUN, '192.0.2.7'], [ThrottledSurfacesInterface::JOURNEY_RUN, '192.0.2.7']],
			$this->attempts
		);
	}//end testAnUnpublishedAndAnUnknownJourneyLookIdentical()

	/**
	 * An anonymous writing answer is submitted with no subject and no owner, so nothing is stamped.
	 *
	 * @return void
	 */
	public function testAnAnonymousWriteCarriesNoOwner(): void {
		$this->params  = ['step' => 'send', 'answers' => ['name' => 'Ada', 'email' => 'ada@example.org']];
		$this->headers = ['Idempotency-Key' => 'k-1'];
		$seen = null;
		$this->submits->expects($this->once())->method('submit')->willReturnCallback(
			static function (FormSubmission $submission, ?string $key=null) use (&$seen): array {
				$seen = [$submission, $key];

				return ['id' => 'contact-1'];
			}
		);

		$response = $this->controller->answer(journeyId: 'open');

		[$submission, $key] = $seen;
		$this->assertSame(200, $response->getStatus());
		$this->assertNull($submission->subject);
		$this->assertNull($submission->owner);
		$this->assertSame('anonymous', $submission->submitter());
		$this->assertSame(['name' => 'Ada', 'email' => 'ada@example.org'], $submission->payload);
		$this->assertSame('k-1', $key);
		$this->assertSame(['id' => 'contact-1'], $response->getData()['committed']);
	}//end testAnAnonymousWriteCarriesNoOwner()

	/**
	 * A signed-in filer's write is submitted as them, with the draft it continues.
	 *
	 * @return void
	 */
	public function testASignedInWriteIsSubmittedAsTheFiler(): void {
		$this->user   = $this->createMock(IUser::class);
		$this->params = ['step' => 'send', 'answers' => ['email' => 'ada@example.org'], '_draft' => 'draft-3'];
		$this->submits->expects($this->once())->method('submit')->with(
			$this->callback(fn (FormSubmission $submission): bool => $submission->subject === $this->user && $submission->draftId === 'draft-3')
		)->willReturn(['id' => 'contact-1']);

		$this->assertSame(200, $this->controller->answer(journeyId: 'open')->getStatus());
	}//end testASignedInWriteIsSubmittedAsTheFiler()

	/**
	 * An out-of-step answer is a 422 with findings and no attempt; nothing is submitted.
	 *
	 * @return void
	 */
	public function testAnOutOfStepAnswerIsRefused(): void {
		$this->params = ['step' => 'about', 'answers' => ['email' => 'ada@example.org']];
		$this->submits->expects($this->never())->method('submit');

		$response = $this->controller->answer(journeyId: 'open');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('email', $response->getData()['findings'][0]['field']);
		$this->assertSame([], $this->attempts);
	}//end testAnOutOfStepAnswerIsRefused()

	/**
	 * An anonymous first save is the token's draft, and answers the token once.
	 *
	 * @return void
	 */
	public function testAnAnonymousSaveAnswersItsResumeTokenOnce(): void {
		$this->params = ['step' => 'about', 'answers' => ['name' => 'Ada']];
		$owners       = [];
		$this->submits->method('saveDraft')->willReturnCallback(
			function (FormSubmission $submission) use (&$owners): array {
				$owners[] = $submission->owner?->value();
				$this->assertNull($submission->subject);

				return ['id' => 'draft-1', 'status' => 'draft'];
			}
		);

		$first = $this->controller->save(journeyId: 'open');

		$this->assertSame(200, $first->getStatus());
		$token = $first->getData()['token'];
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
		$this->assertSame(JourneyRunService::tokenOwner(token: $token)->value(), $owners[0]);
		$this->assertStringNotContainsString($token, (string)$owners[0]);

		$this->headers = ['X-Journey-Run-Token' => $token];
		$this->params['_draft'] = 'draft-1';
		$again = $this->controller->save(journeyId: 'open');

		$this->assertSame(['id' => 'draft-1', 'status' => 'draft'], $again->getData());
		$this->assertSame($owners[0], $owners[1]);
	}//end testAnAnonymousSaveAnswersItsResumeTokenOnce()

	/**
	 * A signed-in save is the filer's own draft and mints no token.
	 *
	 * @return void
	 */
	public function testASignedInSaveMintsNoToken(): void {
		$this->user   = $this->createMock(IUser::class);
		$this->params = ['step' => 'about', 'answers' => ['name' => 'Ada']];
		$this->submits->expects($this->once())->method('saveDraft')->willReturnCallback(
			function (FormSubmission $submission): array {
				$this->assertSame($this->user, $submission->subject);
				$this->assertNull($submission->owner);

				return ['id' => 'draft-2', 'status' => 'draft'];
			}
		);

		$this->assertSame(['id' => 'draft-2', 'status' => 'draft'], $this->controller->save(journeyId: 'open')->getData());
	}//end testASignedInSaveMintsNoToken()

	/**
	 * An anonymous filer resumes on a new device with the token: the reached step and the answers.
	 *
	 * @return void
	 */
	public function testAnAnonymousFilerResumesWithTheToken(): void {
		$owner = JourneyRunService::tokenOwner(token: 'the-token')->value();
		$this->submits->method('findDraft')->willReturnCallback(
			fn (array $destination, FormSubmitter $submitter): ?array => match ($submitter->owner?->value()) {
				$owner => ['id' => 'draft-1', 'status' => 'draft', 'data' => ['name' => 'Ada', '_owner' => $owner], 'updatedAt' => '2026-11-14T10:00:00+00:00'],
				default => null,
			}
		);
		$this->headers = ['X-Journey-Run-Token' => 'the-token'];

		$response = $this->controller->resume(journeyId: 'open');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['journey' => 'open', 'run' => 'draft-1', 'step' => 'send', 'fields' => ['email'], 'answers' => ['name' => 'Ada'], 'updatedAt' => '2026-11-14T10:00:00+00:00'],
			$response->getData()
		);
		$this->assertSame([], $this->attempts);
	}//end testAnAnonymousFilerResumesWithTheToken()

	/**
	 * A token for another run, an unknown run, no token and an unknown journey give one response.
	 *
	 * @return void
	 */
	public function testAMismatchedTokenAndAnUnknownRunLookIdentical(): void {
		$this->submits->method('readDraft')->willThrowException(new FormSubmitRefusedException(message: 'This draft does not exist.', status: 404));
		$this->submits->method('findDraft')->willReturn(null);

		$this->headers = ['X-Journey-Run-Token' => 'token-of-run-a'];
		$this->params  = ['run' => 'run-b'];
		$mismatch      = $this->whole($this->controller->resume(journeyId: 'open'));
		$this->params  = ['run' => 'no-such-run'];
		$unknown       = $this->whole($this->controller->resume(journeyId: 'open'));
		$this->params  = [];
		$newest        = $this->whole($this->controller->resume(journeyId: 'open'));
		$this->headers = [];
		$tokenless     = $this->whole($this->controller->resume(journeyId: 'open'));
		$nowhere       = $this->whole($this->controller->resume(journeyId: 'nope'));

		$this->assertSame(404, $unknown[0]);
		$this->assertSame($unknown, $mismatch);
		$this->assertSame($unknown, $newest);
		$this->assertSame($unknown, $tokenless);
		$this->assertSame($unknown, $nowhere);
		$this->assertCount(5, $this->attempts);
	}//end testAMismatchedTokenAndAnUnknownRunLookIdentical()

	/**
	 * A signed-in filer resumes the run named, read as their own draft.
	 *
	 * @return void
	 */
	public function testASignedInFilerResumesTheNamedRun(): void {
		$this->user   = $this->createMock(IUser::class);
		$this->params = ['run' => 'draft-9'];
		$this->submits->expects($this->once())->method('readDraft')->willReturnCallback(
			function (array $destination, FormSubmitter $submitter): array {
				$this->assertSame(['register' => 'crm', 'schema' => 'contact', 'mapping' => null], $destination);
				$this->assertSame('draft-9', $submitter->draftId);
				$this->assertSame($this->user, $submitter->subject);
				$this->assertNull($submitter->owner);

				return ['id' => 'draft-9', 'status' => 'draft', 'data' => ['name' => 'Ada', 'email' => 'ada@example.org'], 'updatedAt' => 'then'];
			}
		);

		$data = $this->controller->resume(journeyId: 'open')->getData();

		$this->assertSame('send', $data['step']);
		$this->assertSame(['name' => 'Ada', 'email' => 'ada@example.org'], $data['answers']);
	}//end testASignedInFilerResumesTheNamedRun()

	/**
	 * A failing throttler is logged, and the refusal still answers.
	 *
	 * @return void
	 */
	public function testAFailingThrottlerDoesNotHideTheRefusal(): void {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('registerAttempt')->willThrowException(new RuntimeException('cache down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnArgument(1);
		$objects = $this->createMock(ObjectService::class);
		$l10n    = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$controller = new JourneyRunController(
			appName: 'openregister',
			request: $request,
			runs: new JourneyRunService(objects: $objects, submits: $this->submits, conditions: new LocalConditionEvaluator(), l10n: $l10n),
			userSession: $this->createMock(IUserSession::class),
			throttler: $throttler,
			logger: $logger
		);

		$this->assertSame(404, $controller->start(journeyId: 'nope')->getStatus());
	}//end testAFailingThrottlerDoesNotHideTheRefusal()
}//end class

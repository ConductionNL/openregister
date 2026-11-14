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
use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Form\FormSubmitServiceInterface;
use OCA\OpenRegister\Service\Form\JourneyRunService;
use OCA\OpenRegister\Service\Form\LocalConditionEvaluator;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfacesInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
	 * Start and answer are public, rate-limited and brute-force protected; save is for a signed-in filer.
	 *
	 * @return void
	 */
	public function testEachRouteDeclaresItsPosture(): void {
		foreach (['start', 'answer'] as $method) {
			$this->assertSame(
				[PublicPage::class, NoCSRFRequired::class, AnonRateLimit::class, UserRateLimit::class, BruteForceProtection::class],
				$this->attributes(method: $method),
				$method
			);
			$protection = (new ReflectionMethod(JourneyRunController::class, $method))->getAttributes(BruteForceProtection::class)[0];
			$this->assertSame(['action' => ThrottledSurfacesInterface::JOURNEY_RUN], $protection->getArguments());
		}

		$this->assertSame([NoAdminRequired::class, UserRateLimit::class], $this->attributes(method: 'save'));
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
	 * Saving is refused for an anonymous visitor by the submit path, and passed on as its status.
	 *
	 * @return void
	 */
	public function testSaveAnswersWithTheDraftOrTheRefusal(): void {
		$this->params = ['step' => 'about', 'answers' => ['name' => 'Ada']];
		$this->submits->method('saveDraft')->willReturn(['id' => 'draft-1', 'status' => 'draft']);

		$response = $this->controller->save(journeyId: 'open');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 'draft-1', 'status' => 'draft'], $response->getData());
	}//end testSaveAnswersWithTheDraftOrTheRefusal()

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

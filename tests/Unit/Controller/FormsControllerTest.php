<?php

/**
 * The forms API: validate a mapping while authoring, submit into the destination, upload a file first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FormsController;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormDefinitionResolver;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormSubmitService;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfaces;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Status codes, bodies and the auth posture of each route.
 *
 * @covers \OCA\OpenRegister\Controller\FormsController
 * @uses \OCA\OpenRegister\Exception\FormSubmitRefusedException
 * @uses \OCA\OpenRegister\Db\Schema
 */
class FormsControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private FormDefinitionResolver&MockObject $resolver;

	private FormSubmitService&MockObject $submitter;

	private FormDestinationValidator&MockObject $validator;

	private FormUploadStore&MockObject $uploads;

	private SchemaMapper&MockObject $schemas;

	private IUserSession&MockObject $session;

	private IThrottler&MockObject $throttler;

	private FormsController $controller;

	/**
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * @var array<string, string>
	 */
	private array $headers = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$this->request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default));
		$this->request->method('getHeader')->willReturnCallback(fn (string $name): string => ($this->headers[$name] ?? ''));
		$this->request->method('getRemoteAddress')->willReturn('192.0.2.7');

		$this->resolver = $this->createMock(FormDefinitionResolver::class);
		$this->submitter = $this->createMock(FormSubmitService::class);
		$this->validator = $this->createMock(FormDestinationValidator::class);
		$this->uploads = $this->createMock(FormUploadStore::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new FormsController(
			appName: 'openregister',
			request: $this->request,
			resolver: $this->resolver,
			submitter: $this->submitter,
			validator: $this->validator,
			uploads: $this->uploads,
			schemas: $this->schemas,
			userSession: $this->session,
			throttler: $this->throttler,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A published public form.
	 *
	 * @return array<string, mixed>
	 */
	private function form(string $audience = 'public'): array {
		return [
			'id' => 'form-1',
			'writes' => [['as' => 'destination', 'register' => 'dossiq', 'schema' => 'case', 'mapping' => null]],
			'audience' => $audience,
		];
	}//end form()

	/**
	 * The attribute names on a controller method.
	 *
	 * @return array<int, string>
	 */
	private function attributes(string $method): array {
		return array_map(
			static fn (\ReflectionAttribute $attribute): string => $attribute->getName(),
			(new ReflectionMethod(FormsController::class, $method))->getAttributes()
		);
	}//end attributes()

	/**
	 * Submit and upload are public, throttled per ADR-082, and brute-force protected; validate is for signed-in authors.
	 */
	public function testEachRouteDeclaresItsPosture(): void {
		foreach (['submit', 'upload'] as $method) {
			$attributes = $this->attributes(method: $method);
			$this->assertContains(PublicPage::class, $attributes, $method);
			$this->assertContains(AnonRateLimit::class, $attributes, $method);
			$this->assertContains(UserRateLimit::class, $attributes, $method);
			$this->assertContains(BruteForceProtection::class, $attributes, $method);
		}

		$this->assertContains(NoAdminRequired::class, $this->attributes(method: 'validate'));
		$this->assertNotContains(PublicPage::class, $this->attributes(method: 'validate'));
		$this->assertContains(ThrottledSurfaces::FORM_SUBMIT, ThrottledSurfaces::ALL);
	}//end testEachRouteDeclaresItsPosture()

	/**
	 * A valid submit answers 201 with the service's answer; control keys and the route id never reach the payload.
	 */
	public function testASubmitAnswers201(): void {
		$this->params = ['formId' => 'form-1', 'onderwerp' => 'Kapvergunning', '_route' => 'x'];
		$this->headers = ['Idempotency-Key' => 'k1'];
		$this->resolver->method('resolve')->with('form-1')->willReturn($this->form());
		$answer = ['reference' => '2026-0412', 'id' => 'u', 'receivedAt' => null, 'confirmation' => [], 'objects' => []];
		$this->submitter->expects($this->once())->method('submit')
			->with(['register' => 'dossiq', 'schema' => 'case'], null, ['onderwerp' => 'Kapvergunning'], null, 'k1', 'form-1')
			->willReturn($answer);

		$response = $this->controller->submit(formId: 'form-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testASubmitAnswers201()

	/**
	 * A journey form with several writes goes through submitAll(), all or none.
	 */
	public function testAJourneyFormGoesThroughSubmitAll(): void {
		$writes = [['as' => 'org', 'register' => 'crm', 'schema' => 'organisation'], ['as' => 'contact', 'register' => 'crm', 'schema' => 'contact']];
		$this->params = ['formId' => 'form-2', 'naam' => 'De Korst'];
		$this->resolver->method('resolve')->willReturn(['id' => 'form-2', 'writes' => $writes, 'audience' => 'public']);
		$this->submitter->expects($this->never())->method('submit');
		$this->submitter->expects($this->once())->method('submitAll')->with($writes, ['naam' => 'De Korst'], null, null, 'form-2')
			->willReturn(['reference' => 'r', 'id' => 'i', 'receivedAt' => null, 'confirmation' => [], 'objects' => []]);

		$this->assertSame(201, $this->controller->submit(formId: 'form-2')->getStatus());
	}//end testAJourneyFormGoesThroughSubmitAll()

	/**
	 * A refusal answers with its own status and findings.
	 */
	public function testARefusalAnswersWithItsStatusAndFindings(): void {
		$this->params = ['formId' => 'form-1'];
		$this->resolver->method('resolve')->willReturn($this->form());
		$findings = [['property' => 'caseType', 'code' => 'required', 'message' => 'm']];
		$this->submitter->method('submit')->willThrowException(new FormSubmitRefusedException(message: 'Not accepted', status: 422, findings: $findings));

		$response = $this->controller->submit(formId: 'form-1');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['message' => 'Not accepted', 'findings' => $findings], $response->getData());
	}//end testARefusalAnswersWithItsStatusAndFindings()

	/**
	 * An unknown form is a 404 and a registered brute-force attempt.
	 */
	public function testAnUnknownFormIsA404AndAnAttempt(): void {
		$this->resolver->method('resolve')->willThrowException(new FormSubmitRefusedException(message: 'This form does not exist.', status: 404));
		$this->throttler->expects($this->once())->method('registerAttempt')->with(ThrottledSurfaces::FORM_SUBMIT, '192.0.2.7');
		$this->submitter->expects($this->never())->method('submitAll');

		$this->assertSame(404, $this->controller->submit(formId: 'nope')->getStatus());
	}//end testAnUnknownFormIsA404AndAnAttempt()

	/**
	 * A filled honeypot answers 202 with no reference and stores nothing.
	 */
	public function testAFilledHoneypotStoresNothing(): void {
		$this->params = ['formId' => 'form-1', '_hp' => 'http://spam.example'];
		$this->submitter->expects($this->never())->method('submitAll');
		$this->resolver->expects($this->never())->method('resolve');

		$response = $this->controller->submit(formId: 'form-1');

		$this->assertSame(202, $response->getStatus());
		$this->assertSame([], $response->getData());
	}//end testAFilledHoneypotStoresNothing()

	/**
	 * An anonymous visitor cannot submit a form for signed-in people; a signed-in one passes as the subject.
	 */
	public function testTheAudienceIsHonoured(): void {
		$this->params = ['formId' => 'form-1'];
		$this->resolver->method('resolve')->willReturn($this->form(audience: 'authenticated'));
		$this->submitter->expects($this->once())->method('submit')->willReturn(['reference' => 'r', 'id' => 'i', 'receivedAt' => null, 'confirmation' => [], 'objects' => []]);

		$this->assertSame(401, $this->controller->submit(formId: 'form-1')->getStatus());

		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->assertSame(201, $this->controller->submit(formId: 'form-1')->getStatus());
	}//end testTheAudienceIsHonoured()

	/**
	 * Q9 (Ruben): refuse from day one. A mapping with findings is not accepted; one without is.
	 */
	public function testValidateRefusesAMappingWithFindings(): void {
		$schema = new Schema();
		$this->params = ['mapping' => ['fields' => []], 'destination' => ['register' => 'dossiq', 'schema' => 'case'], 'audience' => 'public'];
		$this->schemas->method('find')->with('case')->willReturn($schema);
		$findings = [['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'm']];
		$this->validator->expects($this->exactly(2))->method('validate')->with(['fields' => []], $schema, ['audience' => 'public'])
			->willReturnOnConsecutiveCalls($findings, []);

		$refused = $this->controller->validate();
		$this->assertSame(200, $refused->getStatus());
		$this->assertSame(['accepted' => false, 'findings' => $findings], $refused->getData());
		$this->assertSame(['accepted' => true, 'findings' => []], $this->controller->validate()->getData());
	}//end testValidateRefusesAMappingWithFindings()

	/**
	 * Validate against a schema that does not exist is a 404.
	 */
	public function testValidateAgainstAnUnknownSchemaIsA404(): void {
		$this->params = ['mapping' => [], 'destination' => ['schema' => 'nope']];
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->assertSame(404, $this->controller->validate()->getStatus());
	}//end testValidateAgainstAnUnknownSchemaIsA404()

	/**
	 * An upload is checked against the property of the form's destination and answers a token.
	 */
	public function testAnUploadAnswersAToken(): void {
		$schema = new Schema();
		$schema->setProperties(['bijlage' => ['type' => 'file', 'maxSize' => 10]]);
		$this->params = ['property' => 'bijlage'];
		$file = ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 5];
		$this->request->method('getUploadedFile')->with('file')->willReturn($file);
		$this->resolver->method('resolve')->willReturn($this->form());
		$this->schemas->method('find')->willReturn($schema);
		$this->uploads->expects($this->once())->method('issue')
			->with('form-1', 'bijlage', ['type' => 'file', 'maxSize' => 10], $file)
			->willReturn(['token' => 'abc', 'expiresAt' => 1]);

		$response = $this->controller->upload(formId: 'form-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['token' => 'abc', 'expiresAt' => 1], $response->getData());
	}//end testAnUploadAnswersAToken()

	/**
	 * An upload for a property no destination of the form has is a 422, and no token.
	 */
	public function testAnUploadForAnUnknownPropertyIs422(): void {
		$this->params = ['property' => 'nope'];
		$this->request->method('getUploadedFile')->willReturn(['tmp_name' => '/tmp/x', 'error' => 0]);
		$this->resolver->method('resolve')->willReturn($this->form());
		$this->schemas->method('find')->willReturn(new Schema());
		$this->uploads->expects($this->never())->method('issue');

		$this->assertSame(422, $this->controller->upload(formId: 'form-1')->getStatus());
	}//end testAnUploadForAnUnknownPropertyIs422()
	/**
	 * The three routes exist, with their verbs, and point at methods that exist.
	 */
	public function testTheRoutesAreRegistered(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$found = [];
		foreach ($routes['routes'] as $route) {
			if (str_starts_with((string)$route['name'], 'forms#') === true) {
				$found[$route['name']] = [$route['url'], $route['verb']];
				$this->assertTrue(method_exists(FormsController::class, substr((string)$route['name'], 6)), (string)$route['name']);
			}
		}

		$this->assertSame(
			[
				'forms#validate' => ['/api/forms/validate', 'POST'],
				'forms#submit' => ['/api/forms/{formId}/submit', 'POST'],
				'forms#upload' => ['/api/forms/{formId}/uploads', 'POST'],
			],
			$found
		);
	}//end testTheRoutesAreRegistered()
}//end class

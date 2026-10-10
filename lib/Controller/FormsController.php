<?php

/**
 * The forms API: judge a mapping while authoring, submit into the destination, upload a file first.
 *
 * Decision 179, ADR-117. `POST /api/forms/validate` lets buildiq and portaliq
 * show findings while a form is built. `POST /api/forms/{formId}/submit`
 * creates the destination object in one request for a form stored in
 * OpenRegister. `POST /api/forms/{formId}/uploads` holds one file for that
 * submit. Callers keeping their form elsewhere call FormSubmitService directly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormDefinitionResolver;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormSubmitService;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfaces;
use OCP\AppFramework\Controller;
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin wrappers over the validator, the submit service and the upload store.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One controller for the three form routes;
 * each collaborator serves one of them.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
class FormsController extends Controller {

	/**
	 * The honeypot field: a person never fills it, a bot does.
	 *
	 * @var string
	 */
	public const HONEYPOT = '_hp';

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app id.
	 * @param IRequest                 $request     The request.
	 * @param FormDefinitionResolver   $resolver    Resolves a stored form.
	 * @param FormSubmitService        $submitter   Creates the destination.
	 * @param FormDestinationValidator $validator   Judges a mapping.
	 * @param FormUploadStore          $uploads     Holds upload tokens.
	 * @param SchemaMapper             $schemas     Resolves a destination schema.
	 * @param IUserSession             $userSession The signed-in subject, if any.
	 * @param IThrottler               $throttler   Counts guesses at form ids.
	 * @param IL10N                    $l10n        Translations.
	 * @param LoggerInterface          $logger      Logs a throttler failure.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Three routes over four services, plus the
	 * request, session, throttler, translations and logger every public controller here takes.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FormDefinitionResolver $resolver,
		private readonly FormSubmitService $submitter,
		private readonly FormDestinationValidator $validator,
		private readonly FormUploadStore $uploads,
		private readonly SchemaMapper $schemas,
		private readonly IUserSession $userSession,
		private readonly IThrottler $throttler,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Judge a mapping against a destination schema, for an author building a form.
	 *
	 * Body: `{ mapping, destination: { register?, schema }, audience? }`.
	 * Answer: `{ accepted, findings }`. A form with findings is not saved:
	 * the check refuses from day one (Q9, Ruben, 10 October 2026).
	 *
	 * @return JSONResponse 200 with the findings, 404 when the schema does not exist.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	#[NoAdminRequired]
	public function validate(): JSONResponse {
		$destination = $this->request->getParam('destination', []);
		$mapping = $this->request->getParam('mapping', []);
		$schemaRef = '';
		if (is_array($destination) === true) {
			$schemaRef = trim((string)($destination['schema'] ?? ''));
		}

		try {
			$schema = $this->schemas->find($schemaRef, _multitenancy: false);
		} catch (Throwable) {
			return new JSONResponse(data: ['message' => $this->l10n->t('The destination schema does not exist.')], statusCode: 404);
		}

		$options = [];
		$audience = trim((string)$this->request->getParam('audience', ''));
		if ($audience !== '') {
			$options['audience'] = $audience;
		}

		if (is_array($mapping) === false) {
			$mapping = [];
		}

		$findings = $this->validator->validate(mapping: $mapping, schema: $schema, options: $options);

		return new JSONResponse(data: ['accepted' => ($findings === []), 'findings' => $findings]);
	}//end validate()

	/**
	 * Submit a form stored in OpenRegister: create its destination in one request.
	 *
	 * @param string $formId The form object's uuid.
	 *
	 * @return JSONResponse 201 with reference, id, receivedAt and confirmation; 202 for a honeypot hit;
	 *                      401, 403, 404, 422 or 503 with `{ message, findings }`.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: ThrottledSurfaces::FORM_SUBMIT)]
	public function submit(string $formId): JSONResponse {
		// D7: the honeypot first. A hit stores nothing and tells the bot nothing.
		if (trim((string)$this->request->getParam(self::HONEYPOT, '')) !== '') {
			return new JSONResponse(data: [], statusCode: 202);
		}

		try {
			$form = $this->resolveForm(formId: $formId);
			$subject = $this->userSession->getUser();
			if ($subject === null && $form['audience'] !== 'public') {
				return new JSONResponse(data: ['message' => $this->l10n->t('Please sign in to send this form.'), 'findings' => []], statusCode: 401);
			}

			$key = trim($this->request->getHeader('Idempotency-Key'));
			if ($key === '') {
				$key = null;
			}

			$answer = $this->submitForm(form: $form, subject: $subject, key: $key);
		} catch (FormSubmitRefusedException $refused) {
			return new JSONResponse(data: $refused->toBody(), statusCode: $refused->getStatus());
		}

		return new JSONResponse(data: $answer, statusCode: 201);
	}//end submit()

	/**
	 * Hold one file for a later submit of this form, checked against the property's file rules.
	 *
	 * Multipart: `file` (the file) and `property` (the destination property).
	 *
	 * @param string $formId The form object's uuid.
	 *
	 * @return JSONResponse 201 `{ token, expiresAt }`; 404 or 422 with `{ message, findings }`.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[BruteForceProtection(action: ThrottledSurfaces::FORM_SUBMIT)]
	public function upload(string $formId): JSONResponse {
		$property = trim((string)$this->request->getParam('property', ''));
		$file = $this->request->getUploadedFile('file');
		if (is_array($file) === false) {
			$file = [];
		}

		try {
			$form = $this->resolveForm(formId: $formId);
			$rule = $this->ruleFor(writes: $form['writes'], property: $property);
			$issued = $this->uploads->issue(formId: $formId, property: $property, rule: $rule, file: $file);
		} catch (FormSubmitRefusedException $refused) {
			return new JSONResponse(data: $refused->toBody(), statusCode: $refused->getStatus());
		}

		return new JSONResponse(data: $issued, statusCode: 201);
	}//end upload()

	/**
	 * One destination through FormSubmitService::submit(), a journey's writes through submitAll().
	 *
	 * @param array{id: string, writes: array<int, array<string, mixed>>, audience: string} $form    The form.
	 * @param IUser|null                                                                         $subject The subject.
	 * @param string|null                                                                   $key     The Idempotency-Key.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @throws FormSubmitRefusedException On any refusal.
	 */
	private function submitForm(array $form, ?IUser $subject, ?string $key): array {
		if (count($form['writes']) !== 1) {
			return $this->submitter->submitAll($form['writes'], $this->payload(), $subject, $key, $form['id']);
		}

		$write = $form['writes'][0];
		$mapping = null;
		if (is_array($write['mapping'] ?? null) === true) {
			$mapping = $write['mapping'];
		}

		return $this->submitter->submit(
			['register' => ($write['register'] ?? null), 'schema' => ($write['schema'] ?? null)],
			$mapping,
			$this->payload(),
			$subject,
			$key,
			$form['id']
		);
	}//end submitForm()

	/**
	 * The stored form, registering a brute-force attempt when it does not resolve.
	 *
	 * @param string $formId The form id.
	 *
	 * @return array{id: string, writes: array<int, array<string, mixed>>, audience: string} The form.
	 *
	 * @throws FormSubmitRefusedException 404.
	 */
	private function resolveForm(string $formId): array {
		try {
			return $this->resolver->resolve(formId: $formId);
		} catch (FormSubmitRefusedException $refused) {
			try {
				$this->throttler->registerAttempt(ThrottledSurfaces::FORM_SUBMIT, $this->request->getRemoteAddress());
			} catch (Throwable $throttlerFailure) {
				$this->logger->warning(
					message: '[FormsController] registerAttempt failed',
					context: ['exception' => $throttlerFailure->getMessage()]
				);
			}

			throw $refused;
		}
	}//end resolveForm()

	/**
	 * The schema definition of a property on one of the form's destinations.
	 *
	 * @param array<int, array<string, mixed>> $writes   The form's writes.
	 * @param string                           $property The property.
	 *
	 * @return array<string, mixed> The property definition.
	 *
	 * @throws FormSubmitRefusedException 422 when no destination has the property.
	 */
	private function ruleFor(array $writes, string $property): array {
		foreach ($writes as $write) {
			try {
				$properties = $this->schemas->find((string)($write['schema'] ?? ''), _multitenancy: false)->getProperties();
			} catch (Throwable) {
				continue;
			}

			$rule = ($properties[$property] ?? null);
			if ($property !== '' && is_array($rule) === true) {
				return $rule;
			}
		}

		$message = $this->l10n->t('This form has no question that takes a file under that name.');
		throw new FormSubmitRefusedException(
			message: $message,
			status: 422,
			findings: [['property' => $property, 'code' => 'property-unknown', 'message' => $message]]
		);
	}//end ruleFor()

	/**
	 * The submitted answers: the request body minus the route id and `_` control keys.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payload(): array {
		$payload = [];
		foreach ($this->request->getParams() as $name => $value) {
			$name = (string)$name;
			if ($name === 'formId' || str_starts_with($name, '_') === true) {
				continue;
			}

			$payload[$name] = $value;
		}

		return $payload;
	}//end payload()
}//end class

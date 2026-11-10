<?php

/**
 * FormLinksController
 *
 * REST controller for the Tier-2 forms integration leaf — manages
 * link rows in `openregister_form_links` between OR objects and
 * NC Forms forms / submissions.
 *
 * Endpoints:
 *   GET    /api/objects/{r}/{s}/{id}/forms                                 — index
 *   POST   /api/objects/{r}/{s}/{id}/forms (body: formId | submissionId)   — link
 *   POST   /api/objects/{r}/{s}/{id}/forms/new                              — create + link
 *   DELETE /api/objects/{r}/{s}/{id}/forms/{formId}                         — unlink form
 *   DELETE /api/objects/{r}/{s}/{id}/forms/submissions/{submissionId}       — unlink submission
 *   GET    /api/integrations/forms/available                                — picker source
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\FormLinksControllerServices;
use OCA\OpenRegister\Controller\Trait\ResolvesLinkedObjectTrait;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormSubmissionRef;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\FormLinkScope;
use OCA\OpenRegister\Service\FormLinkServiceInterface;
use OCA\OpenRegister\Service\NewForm;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * FormLinksController — REST routes for Tier-2 forms link CRUD.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FormLinksController extends Controller {
	use ResolvesLinkedObjectTrait;


	/**
	 * Form-link service.
	 *
	 * @var FormLinkServiceInterface
	 */
	private readonly FormLinkServiceInterface $formLinkService;

	/**
	 * Object service — used to resolve register/schema/id into an
	 * entity so we get the canonical UUID + register id for the link.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param FormLinksControllerServices $services The controller's collaborators.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		FormLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->formLinkService = $services->formLinkService;
		$this->objectService = $services->objectService;
		$this->l10n = $services->l10n;
		$this->logger = $services->logger;

	}//end __construct()

	/**
	 * List linked forms for an object.
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->listForms(ref: new ObjectRef(register: $register, schema: $schema, id: $id));

	}//end index()

	/**
	 * Link an existing NC Forms form (or submission) to an object.
	 *
	 * Body must carry one of:
	 *   - `formId`        — link the form
	 *   - `submissionId` + `formId` — link a specific submission
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function link(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->linking(ref: $ref, action: fn (ObjectEntity $object): JSONResponse => $this->linkedForm(object: $object));

	}//end link()

	/**
	 * A body field as an int, or 0 when the body does not carry it.
	 *
	 * @param array  $data The request body.
	 * @param string $key  The field.
	 *
	 * @return int The value, or 0.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function intParam(array $data, string $key): int {
		if (isset($data[$key]) === true) {
			return (int)$data[$key];
		}

		return 0;
	}//end intParam()

	/**
	 * Link the form, or the one submission when the body names it, to the object.
	 *
	 * @param ObjectEntity                             $object The object.
	 * @param array{formId: int, submissionId: int} $ids    The form and, when positive, the submission.
	 *
	 * @return FormLink The link written.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function linkToObject(ObjectEntity $object, array $ids): FormLink {
		$scope = (new FormLinkScope(objectUuid: $object->getUuid(), registerId: (int)$object->getRegister()))
			->inSchema(schemaId: $this->linkSchemaId(object: $object));

		if ($ids['submissionId'] > 0) {
			$submission = new FormSubmissionRef(formId: $ids['formId'], submissionId: $ids['submissionId']);
			return $this->formLinkService->linkSubmissionTo(scope: $scope, submission: $submission);
		}

		return $this->formLinkService->linkFormTo(scope: $scope, formId: $ids['formId']);
	}//end linkToObject()

	/**
	 * The schema id a link records: the object's own, or null when it has none above zero.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return int|null The schema id, or null.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function linkSchemaId(ObjectEntity $object): int|null {
		$rawSchema = (int)$object->getSchema();
		if ($rawSchema > 0) {
			return $rawSchema;
		}

		return null;
	}//end linkSchemaId()

	/**
	 * Answer a failed link write with the exception's code, or 400 when it has none.
	 *
	 * @param Exception $exception The failure.
	 *
	 * @return JSONResponse The error answer.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function failure(Exception $exception): JSONResponse {
		$code = (int)$exception->getCode();
		if ($code === 0) {
			$code = 400;
		}

		return new JSONResponse(['error' => $exception->getMessage()], $code);
	}//end failure()

	/**
	 * Create a new NC Forms form and link it to the object in one shot.
	 *
	 * Body:
	 *   - `title`       (required)
	 *   - `description` (optional)
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function create(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->linking(ref: $ref, action: fn (ObjectEntity $object): JSONResponse => $this->createdForm(object: $object));

	}//end create()

	/**
	 * Unlink a form (and all its submission-level links) from the object.
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 * @param string $formId Form id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroyForm(string $register, string $schema, string $id, string $formId): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->unlinking(ref: $ref, action: fn (ObjectEntity $object): JSONResponse => $this->removedForm(object: $object, formId: (int)$formId));

	}//end destroyForm()

	/**
	 * Unlink a single submission row from the object.
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 * @param string $formId Parent form id.
	 * @param string $submissionId Submission id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroySubmission(string $register, string $schema, string $id, string $formId, string $submissionId): JSONResponse {
		$submission = new FormSubmissionRef(formId: (int)$formId, submissionId: (int)$submissionId);
		$action     = fn (ObjectEntity $object): JSONResponse => $this->removedSubmission(object: $object, submission: $submission);
		return $this->unlinking(ref: new ObjectRef(register: $register, schema: $schema, id: $id), action: $action);
	}//end destroySubmission()

	/**
	 * The forms linked to an object: 404 when it does not exist; any other
	 * failure is logged and answered 500 with its message.
	 *
	 * @param ObjectRef $ref The register, schema and object id.
	 *
	 * @return JSONResponse The forms and their total.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function listForms(ObjectRef $ref): JSONResponse {
		try {
			return $this->onObject(
				ref: $ref,
				action: function (ObjectEntity $object): JSONResponse {
					$results = $this->formLinkService->getLinkedForms($object->getUuid());

					return new JSONResponse(['results' => $results, 'total' => count($results)]);
				}
			);
		} catch (DoesNotExistException $e) {
			return $this->objectNotFound();
		} catch (Exception $e) {
			$this->logger->error('Failed to list linked forms: ' . $e->getMessage());
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end listForms()

	/**
	 * Run a link write on an object: 404 when it does not exist; any other
	 * failure gets its own code, or 400 without one.
	 *
	 * @param ObjectRef $ref    The register, schema and object id.
	 * @param callable  $action The write, given the object.
	 *
	 * @return JSONResponse The write's response, or the error's.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function linking(ObjectRef $ref, callable $action): JSONResponse {
		try {
			return $this->onObject(ref: $ref, action: $action);
		} catch (DoesNotExistException $e) {
			return $this->objectNotFound();
		} catch (Exception $e) {
			return $this->failure(exception: $e);
		}
	}//end linking()

	/**
	 * Run an unlink on an object: 404 when it does not exist; any other
	 * failure is a 400 with its message.
	 *
	 * @param ObjectRef $ref    The register, schema and object id.
	 * @param callable  $action The unlink, given the object.
	 *
	 * @return JSONResponse The unlink's response, or the error's.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function unlinking(ObjectRef $ref, callable $action): JSONResponse {
		try {
			return $this->onObject(ref: $ref, action: $action);
		} catch (DoesNotExistException $e) {
			return $this->objectNotFound();
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}//end unlinking()

	/**
	 * Run an action on the object of a route: 404 when it resolves to nothing.
	 *
	 * @param ObjectRef $ref    The register, schema and object id.
	 * @param callable  $action The action, given the object.
	 *
	 * @return JSONResponse The action's response, or the 404.
	 *
	 * @throws DoesNotExistException When no such object exists.
	 * @throws Exception             When loading the object or the action fails.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function onObject(ObjectRef $ref, callable $action): JSONResponse {
		$object = $this->validateObject(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id);
		if ($object === null) {
			return $this->objectNotFound();
		}

		return $action($object);
	}//end onObject()

	/**
	 * The 404 for an object that does not exist.
	 *
	 * @return JSONResponse The 404.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function objectNotFound(): JSONResponse {
		return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
	}//end objectNotFound()

	/**
	 * Link the form, or the one submission, the request names to the object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The link, 201, or 400 without a formId.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function linkedForm(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();
		$ids = [
			'formId' => $this->intParam(data: $data, key: 'formId'),
			'submissionId' => $this->intParam(data: $data, key: 'submissionId'),
		];
		if ($ids['formId'] <= 0) {
			return new JSONResponse(['error' => $this->l10n->t('formId is required')], 400);
		}

		return new JSONResponse($this->linkToObject(object: $object, ids: $ids), 201);
	}//end linkedForm()

	/**
	 * Create the form the request describes and link it to the object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The link, 201, or 400 without a title.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function createdForm(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();
		$title = trim((string)($data['title'] ?? ''));
		if ($title === '') {
			return new JSONResponse(['error' => $this->l10n->t('title is required')], 400);
		}

		$scope = (new FormLinkScope(objectUuid: $object->getUuid(), registerId: (int)$object->getRegister()))
			->inSchema(schemaId: $this->linkSchemaId(object: $object));
		$link  = $this->formLinkService->createAndLinkForm(
			scope: $scope,
			form: new NewForm(title: $title, description: (string)($data['description'] ?? ''))
		);

		return new JSONResponse($link, 201);
	}//end createdForm()

	/**
	 * Unlink a form and its submission-level links from the object.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int          $formId The form id.
	 *
	 * @return JSONResponse Success and the number of links removed.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function removedForm(ObjectEntity $object, int $formId): JSONResponse {
		$removed = $this->formLinkService->unlinkForm(objectUuid: $object->getUuid(), formId: $formId);

		return new JSONResponse(['success' => true, 'removed' => $removed]);
	}//end removedForm()

	/**
	 * Unlink one submission from the object.
	 *
	 * @param ObjectEntity      $object     The object.
	 * @param FormSubmissionRef $submission The parent form and the submission.
	 *
	 * @return JSONResponse Whether the submission was unlinked.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function removedSubmission(ObjectEntity $object, FormSubmissionRef $submission): JSONResponse {
		$removed = $this->formLinkService->unlinkSubmission(objectUuid: $object->getUuid(), submission: $submission);

		return new JSONResponse(['success' => $removed]);
	}//end removedSubmission()

	/**
	 * Return the user's NC Forms forms for the picker UI.
	 *
	 * Optional query param `objectUuid` is forwarded to the service so
	 * each row carries a `linked` boolean indicating whether the form
	 * is already linked to that object.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: returns the current user's own Nextcloud Forms; no caller-supplied object id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function available(): JSONResponse {
		try {
			$rawObjectUuid = $this->request->getParam('objectUuid');
			$objectUuid = null;
			if ($rawObjectUuid !== null && $rawObjectUuid !== '') {
				$objectUuid = (string)$rawObjectUuid;
			}

			$results = $this->formLinkService->getAvailableForms(objectUuid: $objectUuid);

			return new JSONResponse(['results' => $results, 'total' => count($results)]);
		} catch (Exception $e) {
			$this->logger->error('Failed to list available forms: ' . $e->getMessage());
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}

	}//end available()
}//end class

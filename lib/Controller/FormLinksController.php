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
use OCA\OpenRegister\Controller\Support\FormLinksControllerServices;
use OCA\OpenRegister\Controller\Trait\ResolvesLinkedObjectTrait;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FormLinkServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
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
	 * @param string $appName App name.
	 * @param IRequest $request HTTP request.
	 * @param FormLinksControllerServices $services The controller's collaborators.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		FormLinksControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
			}

			$results = $this->formLinkService->getLinkedForms($object->getUuid());

			return new JSONResponse(['results' => $results, 'total' => count($results)]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
		} catch (Exception $e) {
			$this->logger->error('Failed to list linked forms: ' . $e->getMessage());
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}

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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
			}

			$data = $this->request->getParams();
			$ids = [
				'formId' => $this->intParam(data: $data, key: 'formId'),
				'submissionId' => $this->intParam(data: $data, key: 'submissionId'),
			];

			if ($ids['formId'] <= 0) {
				return new JSONResponse(
					['error' => $this->l10n->t('formId is required')],
					400
				);
			}

			return new JSONResponse($this->linkToObject(object: $object, ids: $ids), 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
		} catch (Exception $e) {
			return $this->failure(exception: $e);
		}//end try

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
		$registerId = (int)$object->getRegister();
		$schemaId = $this->linkSchemaId(object: $object);

		if ($ids['submissionId'] > 0) {
			return $this->formLinkService->linkFormSubmission(
				objectUuid: $object->getUuid(),
				registerId: $registerId,
				formId: $ids['formId'],
				submissionId: $ids['submissionId'],
				schemaId: $schemaId
			);
		}

		return $this->formLinkService->linkForm(
			objectUuid: $object->getUuid(),
			registerId: $registerId,
			formId: $ids['formId'],
			schemaId: $schemaId
		);
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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
			}

			$data = $this->request->getParams();
			$title = trim((string)($data['title'] ?? ''));
			$description = (string)($data['description'] ?? '');

			if ($title === '') {
				return new JSONResponse(
					['error' => $this->l10n->t('title is required')],
					400
				);
			}

			$link = $this->formLinkService->createAndLinkForm(
				objectUuid: $object->getUuid(),
				registerId: (int)$object->getRegister(),
				title: $title,
				description: $description,
				schemaId: $this->linkSchemaId(object: $object)
			);

			return new JSONResponse($link, 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
		} catch (Exception $e) {
			return $this->failure(exception: $e);
		}//end try

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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
			}

			$removed = $this->formLinkService->unlinkForm(
				objectUuid: $object->getUuid(),
				formId: (int)$formId
			);

			return new JSONResponse(['success' => true, 'removed' => $removed]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}

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
	public function destroySubmission(
		string $register,
		string $schema,
		string $id,
		string $formId,
		string $submissionId,
	): JSONResponse {
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
			}

			$removed = $this->formLinkService->unlinkSubmission(
				objectUuid: $object->getUuid(),
				formId: (int)$formId,
				submissionId: (int)$submissionId
			);

			return new JSONResponse(['success' => $removed]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}

	}//end destroySubmission()

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

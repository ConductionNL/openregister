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
use OCA\OpenRegister\Service\FormLinkService;
use OCA\OpenRegister\Service\ObjectService;
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
class FormLinksController extends Controller
{

    /**
     * Form-link service.
     *
     * @var FormLinkService
     */
    private readonly FormLinkService $formLinkService;

    /**
     * Object service — used to resolve register/schema/id into an
     * entity so we get the canonical UUID + register id for the link.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

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
     * @param string          $appName         App name.
     * @param IRequest        $request         HTTP request.
     * @param FormLinkService $formLinkService Form-link service.
     * @param ObjectService   $objectService   Object service.
     * @param IL10N           $l10n            Localisation.
     * @param LoggerInterface $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        FormLinkService $formLinkService,
        ObjectService $objectService,
        IL10N $l10n,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->formLinkService = $formLinkService;
        $this->objectService   = $objectService;
        $this->l10n            = $l10n;
        $this->logger          = $logger;

    }//end __construct()

    /**
     * List linked forms for an object.
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
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
            $this->logger->error('Failed to list linked forms: '.$e->getMessage());
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
     * @param string $schema   Schema slug.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function link(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
            }

            $data         = $this->request->getParams();
            $formId       = 0;
            $submissionId = 0;
            if (isset($data['formId']) === true) {
                $formId = (int) $data['formId'];
            }

            if (isset($data['submissionId']) === true) {
                $submissionId = (int) $data['submissionId'];
            }

            if ($formId <= 0) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('formId is required')],
                    400
                );
            }

            $registerId = (int) $object->getRegister();
            $rawSchema  = (int) $object->getSchema();
            $schemaId   = null;
            if ($rawSchema > 0) {
                $schemaId = $rawSchema;
            }

            if ($submissionId > 0) {
                $link = $this->formLinkService->linkFormSubmission(
                    objectUuid: $object->getUuid(),
                    registerId: $registerId,
                    formId: $formId,
                    submissionId: $submissionId,
                    schemaId: $schemaId
                );
                return new JSONResponse($link, 201);
            }

            $link = $this->formLinkService->linkForm(
                objectUuid: $object->getUuid(),
                registerId: $registerId,
                formId: $formId,
                schemaId: $schemaId
            );

            return new JSONResponse($link, 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
        } catch (Exception $e) {
            $code = (int) $e->getCode();
            if ($code === 0) {
                $code = 400;
            }

            return new JSONResponse(['error' => $e->getMessage()], $code);
        }//end try

    }//end link()

    /**
     * Create a new NC Forms form and link it to the object in one shot.
     *
     * Body:
     *   - `title`       (required)
     *   - `description` (optional)
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function create(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
            }

            $data        = $this->request->getParams();
            $title       = trim((string) ($data['title'] ?? ''));
            $description = (string) ($data['description'] ?? '');

            if ($title === '') {
                return new JSONResponse(
                    ['error' => $this->l10n->t('title is required')],
                    400
                );
            }

            $registerId = (int) $object->getRegister();
            $rawSchema  = (int) $object->getSchema();
            $schemaId   = null;
            if ($rawSchema > 0) {
                $schemaId = $rawSchema;
            }

            $link = $this->formLinkService->createAndLinkForm(
                objectUuid: $object->getUuid(),
                registerId: $registerId,
                title: $title,
                description: $description,
                schemaId: $schemaId
            );

            return new JSONResponse($link, 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
        } catch (Exception $e) {
            $code = (int) $e->getCode();
            if ($code === 0) {
                $code = 400;
            }

            return new JSONResponse(['error' => $e->getMessage()], $code);
        }//end try

    }//end create()

    /**
     * Unlink a form (and all its submission-level links) from the object.
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object id.
     * @param string $formId   Form id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function destroyForm(string $register, string $schema, string $id, string $formId): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
            }

            $removed = $this->formLinkService->unlinkForm(
                objectUuid: $object->getUuid(),
                formId: (int) $formId
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
     * @param string $register     Register slug.
     * @param string $schema       Schema slug.
     * @param string $id           Object id.
     * @param string $formId       Parent form id.
     * @param string $submissionId Submission id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function destroySubmission(
        string $register,
        string $schema,
        string $id,
        string $formId,
        string $submissionId
    ): JSONResponse {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => $this->l10n->t('Object not found')], 404);
            }

            $removed = $this->formLinkService->unlinkSubmission(
                objectUuid: $object->getUuid(),
                formId: (int) $formId,
                submissionId: (int) $submissionId
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
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function available(): JSONResponse
    {
        try {
            $rawObjectUuid = $this->request->getParam('objectUuid');
            $objectUuid    = null;
            if ($rawObjectUuid !== null && $rawObjectUuid !== '') {
                $objectUuid = (string) $rawObjectUuid;
            }

            $results = $this->formLinkService->getAvailableForms(objectUuid: $objectUuid);

            return new JSONResponse(['results' => $results, 'total' => count($results)]);
        } catch (Exception $e) {
            $this->logger->error('Failed to list available forms: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end available()

    /**
     * Validate the (register, schema, id) tuple resolves to a real object.
     *
     * Mirrors the helper in ContactsController so the surface stays consistent.
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object id.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null
     */
    private function validateObject(
        string $register,
        string $schema,
        string $id
    ): ?\OCA\OpenRegister\Db\ObjectEntity {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();

    }//end validateObject()
}//end class

<?php

/**
 * PollLinksController — Tier-2 REST controller for Polls poll links.
 *
 * Adds explicit link/create endpoints and an available-polls picker
 * source so the picker UX can drive its modal without leaking Polls
 * internals.
 *
 * Endpoints:
 *   - GET    /api/objects/{register}/{schema}/{id}/polls          — list
 *   - POST   /api/objects/{register}/{schema}/{id}/polls          — link existing poll
 *   - POST   /api/objects/{register}/{schema}/{id}/polls/new      — create + link
 *   - DELETE /api/objects/{register}/{schema}/{id}/polls/{pollId} — unlink
 *   - GET    /api/integrations/polls/available                    — picker source
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PollLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Tier-2 Poll links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class PollLinksController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName         App id.
     * @param IRequest        $request         HTTP request.
     * @param PollLinkService $pollLinkService Backing service.
     * @param ObjectService   $objectService   OR object resolver.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly PollLinkService $pollLinkService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List linked polls for an object.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->pollLinkService->isPollsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Polls app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $results = $this->pollLinkService->getLinkedPolls($object->getUuid());

            return new JSONResponse(['results' => $results, 'total' => count($results)]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end index()

    /**
     * Link an existing poll.
     *
     * Body: `{ pollId: int }`.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function link(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->pollLinkService->isPollsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Polls app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $pollId = (int) $this->request->getParam('pollId', 0);
            if ($pollId === 0) {
                return new JSONResponse(['error' => 'pollId is required'], 400);
            }

            $link = $this->pollLinkService->linkPoll(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $pollId
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end link()

    /**
     * Create a new poll and link it.
     *
     * Body:
     *   `{ title, description?, type?, options: [string], deadline?: ISO-8601 }`
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function createNew(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->pollLinkService->isPollsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Polls app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $title       = (string) $this->request->getParam('title', '');
            $description = (string) $this->request->getParam('description', '');
            $type        = (string) $this->request->getParam('type', 'textPoll');
            $rawOptions  = $this->request->getParam('options', []);
            $deadline    = $this->request->getParam('deadline');

            if (trim($title) === '') {
                return new JSONResponse(['error' => 'title is required'], 400);
            }

            $options = [];
            if (is_array($rawOptions) === true) {
                foreach ($rawOptions as $option) {
                    if (is_string($option) === true) {
                        $options[] = $option;
                    } else if (is_array($option) === true) {
                        $options[] = (string) ($option['text'] ?? $option['label'] ?? '');
                    }
                }
            }

            $deadlineObj = null;
            if ($deadline !== null && $deadline !== '') {
                try {
                    $deadlineObj = new DateTime((string) $deadline);
                } catch (Throwable $e) {
                    $deadlineObj = null;
                }
            }

            $link = $this->pollLinkService->createAndLinkPoll(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $title,
                $description,
                $type,
                $options,
                $deadlineObj
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end createNew()

    /**
     * Unlink a poll.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     * @param string $pollId   Polls poll id (numeric).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function destroy(string $register, string $schema, string $id, string $pollId): JSONResponse
    {
        if ($this->pollLinkService->isPollsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Polls app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->pollLinkService->unlinkPoll($object->getUuid(), (int) $pollId);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end destroy()

    /**
     * List polls visible to the current user (picker source).
     *
     * Query param: `search` — optional title substring.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt Session-scoped list: returns polls visible to the current user; no caller-supplied object id.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function available(): JSONResponse
    {
        if ($this->pollLinkService->isPollsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Polls app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        $search = $this->request->getParam('search');
        if ($search !== null) {
            $search = (string) $search;
        }

        $polls = $this->pollLinkService->getAvailablePolls($search);
        return new JSONResponse(['results' => $polls, 'total' => count($polls)]);
    }//end available()

    /**
     * Resolve an OR object from register/schema/id.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return ObjectEntity|null
     *
     * @spec exclude Private helper: resolves an object from register/schema/id; the link REST contract is
     *              owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
     */
    private function validateObject(string $register, string $schema, string $id): ?ObjectEntity
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();
    }//end validateObject()

    /**
     * Map a service-layer Exception to a JSONResponse.
     *
     * Exception codes carry HTTP intent:
     *   - 409 → conflict
     *   - 404 → not found
     *   - 503 → service unavailable
     *   - 400 → bad request
     *   - everything else → 400 bad request
     *
     * @param Exception $exception Source exception.
     *
     * @return JSONResponse
     *
     * @spec exclude Private helper: maps a service exception code to an HTTP status; the link REST contract
     *              is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
     */
    private function mapException(Exception $exception): JSONResponse
    {
        $code = $exception->getCode();
        if ($code === 409) {
            return new JSONResponse(['error' => $exception->getMessage()], 409);
        }

        if ($code === 404) {
            return new JSONResponse(['error' => $exception->getMessage()], 404);
        }

        if ($code === 503) {
            return new JSONResponse(['error' => $exception->getMessage()], 503);
        }

        if ($code === 400) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        }

        return new JSONResponse(['error' => $exception->getMessage()], 400);
    }//end mapException()
}//end class

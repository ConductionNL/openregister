<?php

/**
 * EmailLinksController — Tier-2 REST controller for Mail message links.
 *
 * Augments the legacy {@see EmailsController} with explicit picker
 * routes (accounts, mailboxes, messages) and an idempotent link/unlink
 * surface so the multi-step modal in `CnEmailPicker` can drive the
 * full flow without leaking Mail internals.
 *
 * Endpoints:
 *   - GET    /api/objects/{register}/{schema}/{id}/emails               — list (paginated)
 *   - POST   /api/objects/{register}/{schema}/{id}/emails               — link
 *   - DELETE /api/objects/{register}/{schema}/{id}/emails/{linkId}      — unlink
 *   - GET    /api/integrations/email/accounts                           — picker step 1
 *   - GET    /api/integrations/email/accounts/{accountId}/mailboxes     — step 2
 *   - GET    /api/integrations/email/accounts/{accountId}/messages      — step 3 (?mailbox=…)
 *
 * The Tier-1 `EmailsController` is kept intact for now to honour the
 * existing `_mail`/search-by-sender contract; the routes file maps the
 * Tier-2 picker URLs onto this controller while leaving the legacy
 * routes untouched.
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Email links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class EmailLinksController extends Controller
{
    /**
     * Constructor.
     *
     * @param string           $appName          App id.
     * @param IRequest         $request          HTTP request.
     * @param EmailLinkService $emailLinkService Backing service.
     * @param ObjectService    $objectService    OR object resolver.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly EmailLinkService $emailLinkService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List linked Mail messages for an object — paginated.
     *
     * Query string: `cursor` (numeric link-id-derived offset, optional)
     *               and `limit` (clamped server-side).
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
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $cursor = $this->request->getParam('cursor');
            $limit  = (int) $this->request->getParam('limit', 50);

            $cursorStr = null;
            if ($cursor !== null) {
                $cursorStr = (string) $cursor;
            }

            $result = $this->emailLinkService->getLinkedEmails(
                $object->getUuid(),
                $cursorStr,
                $limit
            );

            return new JSONResponse($result);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end index()

    /**
     * Link a Mail message to an object.
     *
     * Body: `{ mailAccountId, messageId, messageUid }`.
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
        if ($this->emailLinkService->isMailAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $mailAccountId = (int) $this->request->getParam('mailAccountId', 0);
            $messageId     = (string) $this->request->getParam('messageId', '');
            $messageUid    = (string) $this->request->getParam('messageUid', '');

            if ($mailAccountId === 0 || $messageId === '') {
                return new JSONResponse(
                    ['error' => 'mailAccountId and messageId are required'],
                    400
                );
            }

            $link = $this->emailLinkService->linkEmail(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $mailAccountId,
                $messageId,
                $messageUid
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end link()

    /**
     * Unlink a Mail message from an object.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     * @param string $linkId   Link primary key (numeric).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function destroy(string $register, string $schema, string $id, string $linkId): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->emailLinkService->unlinkEmail($object->getUuid(), (int) $linkId);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end destroy()

    /**
     * List Mail accounts owned by the current user (picker step 1).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt Session-scoped list: EmailLinkService::getAvailableAccounts filters oc_mail_accounts by user_id = current UID;
     *   no caller-supplied object id.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function accounts(): JSONResponse
    {
        if ($this->emailLinkService->isMailAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        $accounts = $this->emailLinkService->getAvailableAccounts();
        return new JSONResponse(['results' => $accounts, 'total' => count($accounts)]);
    }//end accounts()

    /**
     * List mailboxes for a Mail account (picker step 2).
     *
     * @param string $accountId Mail account id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function mailboxes(string $accountId): JSONResponse
    {
        if ($this->emailLinkService->isMailAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $mailboxes = $this->emailLinkService->getMailboxesForAccount((int) $accountId);
            return new JSONResponse(['results' => $mailboxes, 'total' => count($mailboxes)]);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }
    }//end mailboxes()

    /**
     * List messages in a mailbox (picker step 3).
     *
     * Query string: `mailbox` (required), `cursor` (optional), `limit` (optional).
     *
     * @param string $accountId Mail account id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function messages(string $accountId): JSONResponse
    {
        if ($this->emailLinkService->isMailAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $mailbox = (string) $this->request->getParam('mailbox', '');
            if ($mailbox === '') {
                return new JSONResponse(['error' => 'mailbox query parameter is required'], 400);
            }

            $cursor = $this->request->getParam('cursor');
            $limit  = (int) $this->request->getParam('limit', 50);

            $cursorStr = null;
            if ($cursor !== null) {
                $cursorStr = (string) $cursor;
            }

            $result = $this->emailLinkService->getMessagesForMailbox(
                (int) $accountId,
                $mailbox,
                $cursorStr,
                $limit
            );

            return new JSONResponse($result);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end messages()

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
     *
     * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
     *         than caught: every call site already wraps this helper and translates it to a 404.
     *         Swallowing it here would collapse "no such object" into the same null this method
     *         returns for other reasons, which the caller could no longer tell apart.
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
     *   - 401 → unauthorized
     *   - 403 → forbidden
     *   - 404 → not found
     *   - 409 → conflict
     *   - 503 → service unavailable
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
        if (in_array($code, [401, 403, 404, 409, 503], true) === true) {
            return new JSONResponse(['error' => $exception->getMessage()], $code);
        }

        return new JSONResponse(['error' => $exception->getMessage()], 400);
    }//end mapException()
}//end class

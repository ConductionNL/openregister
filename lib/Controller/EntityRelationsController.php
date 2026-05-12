<?php

/**
 * Controller for the entity-relations decision-metadata PATCH endpoint.
 *
 * Exposes `PATCH /api/entity-relations/{id}` for operators to set the
 * two decision-metadata fields on an `EntityRelation` row:
 *
 *   - `bases`              (?array)  — legal grondslagen for redaction
 *   - `skipAnonymization`  (bool)    — opt-out from the next anonymise pass
 *
 * Per the `entity-relation-grondslagen` change, the post-hoc system fields
 * `anonymized` and `anonymizedValue` are intentionally NOT in the whitelist;
 * those are written by `EntityRelationMapper::markAsAnonymized` during the
 * redaction code path.
 *
 * The endpoint is a thin wrapper over `EntityRelationMapper::updateDecisionMetadata`
 * — whitelist enforcement, shape validation, diff awareness, and audit-trail
 * emission all live in the mapper. This controller resolves the acting
 * `IUser`, runs the authorization check (write-access to the relation's
 * parent file or object), and maps thrown exceptions to HTTP responses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/entity-relation-grondslagen/specs/entity-relation-grondslagen/spec.md
 *       "A PATCH /api/entity-relations/{id} endpoint MUST exist with a decision-only field whitelist"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles the PATCH endpoint for entity-relation decision metadata.
 */
class EntityRelationsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string               $appName              Nextcloud app name.
     * @param IRequest             $request              Current request.
     * @param EntityRelationMapper $entityRelationMapper Relation mapper.
     * @param IUserSession         $userSession          Session user accessor.
     * @param IRootFolder          $rootFolder           Nextcloud root folder (for file lookups).
     * @param LoggerInterface      $logger               Structured log sink.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly IUserSession $userSession,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * PATCH /api/entity-relations/{id}.
     *
     * Body: { bases?: ?array<string>, skipAnonymization?: bool } — any other
     * key triggers HTTP 400.
     *
     * @param int $id Relation row id.
     *
     * @return JSONResponse Updated relation (200) or a structured error.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'unauthenticated'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $relation = $this->entityRelationMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'not_found', 'id' => $id],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->actorCanWriteRelationSubject(relation: $relation, userId: $user->getUID()) === false) {
            $this->logger->info(
                message: '[EntityRelationsController] PATCH denied — no write-access to relation subject',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'relation_id' => $id,
                    'actor'       => $user->getUID(),
                ]
            );
            return new JSONResponse(
                data: ['error' => 'forbidden', 'reason' => 'no_write_access_to_relation_subject'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $body = $this->request->getParams();
        // Strip framework-injected keys; only the JSON body fields remain candidates.
        unset($body['id'], $body['_route']);

        try {
            $relation = $this->entityRelationMapper->updateDecisionMetadata(
                relation: $relation,
                fields: $body,
                actingUser: $user
            );
        } catch (CustomValidationException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage(), 'details' => $e->getErrors()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[EntityRelationsController] PATCH failed: '.$e->getMessage(),
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'relation_id' => $id,
                    'error'       => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: ['error' => 'internal_error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return new JSONResponse(
            data: $relation->jsonSerialize(),
            statusCode: Http::STATUS_OK
        );
    }//end update()

    /**
     * Check that the acting user can write the relation's parent file or object.
     *
     * Resolution order:
     *   1. If the relation has `fileId` — the file MUST be present in the
     *      acting user's user-folder and `isUpdateable()` MUST return true.
     *   2. Else if the relation has `objectId` — defer to the existing
     *      ObjectEntity authorization model (not yet implemented in this
     *      change; for now, accept and emit a warning log so we don't
     *      silently block legitimate object-bound flows). Tracked as
     *      a follow-up.
     *   3. Else if the relation has `emailId` — same deferral; tracked.
     *   4. Otherwise — deny.
     *
     * @param EntityRelation $relation Relation row.
     * @param string         $userId   Acting user UID.
     *
     * @return bool True when the acting user may write.
     */
    private function actorCanWriteRelationSubject(EntityRelation $relation, string $userId): bool
    {
        $fileId = $relation->getFileId();
        if ($fileId !== null) {
            try {
                $userFolder = $this->rootFolder->getUserFolder($userId);
                $nodes      = $userFolder->getById($fileId);
                if (empty($nodes) === true) {
                    return false;
                }

                $node = $nodes[0];
                if (($node instanceof File) === false) {
                    return false;
                }

                return $node->isUpdateable();
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[EntityRelationsController] File-bound auth check raised exception, denying',
                    context: [
                        'file'    => __FILE__,
                        'line'    => __LINE__,
                        'file_id' => $fileId,
                        'error'   => $e->getMessage(),
                    ]
                );
                return false;
            }//end try
        }//end if

        $objectId = $relation->getObjectId();
        $emailId  = $relation->getEmailId();
        if ($objectId !== null || $emailId !== null) {
            // Object- and email-bound authorization is deferred to a
            // follow-up change — the existing OR ObjectEntity authz
            // model is not yet wired through to per-relation PATCH.
            // For v1, accept the write but log so operators are visible.
            $this->logger->warning(
                message: '[EntityRelationsController] Object/email-bound PATCH accepted without strict authz (follow-up)',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'object_id' => $objectId,
                    'email_id'  => $emailId,
                    'actor'     => $userId,
                ]
            );
            return true;
        }

        return false;
    }//end actorCanWriteRelationSubject()
}//end class

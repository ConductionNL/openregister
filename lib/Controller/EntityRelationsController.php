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

use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
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
     * @param PermissionHandler    $permissionHandler    RBAC verdict for object-bound relations.
     * @param MagicMapper          $magicMapper          ObjectEntity loader (for object-bound authz).
     * @param SchemaMapper         $schemaMapper         Schema loader (for hasPermission inputs).
     * @param RegisterMapper       $registerMapper       Register loader (for object lookup disambiguation).
     * @param EmailLinkMapper      $emailLinkMapper      EmailLink loader (for email-bound authz indirection).
     * @param LoggerInterface      $logger               Structured log sink.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly IUserSession $userSession,
        private readonly IRootFolder $rootFolder,
        private readonly PermissionHandler $permissionHandler,
        private readonly MagicMapper $magicMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly EmailLinkMapper $emailLinkMapper,
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

        // Require explicit application/json — anything else and Nextcloud's
        // body parser may silently drop unknown content-types or coerce
        // form-encoded array values inconsistently. Decision-time
        // mutations on legal grondslagen must not depend on content-type
        // heuristics; clients send JSON or get 415.
        $contentType = (string) $this->request->getHeader('Content-Type');
        // Strip any trailing parameters (charset, boundary, etc.).
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType !== 'application/json' && $mediaType !== '') {
            return new JSONResponse(
                data: [
                    'error'  => 'unsupported_media_type',
                    'reason' => 'PATCH /api/entity-relations/{id} requires Content-Type: application/json',
                ],
                statusCode: Http::STATUS_UNSUPPORTED_MEDIA_TYPE
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
     * Resolution order (per `entity-relation-grondslagen` design.md §authz):
     *   1. If the relation has `fileId` — the file MUST be present in the
     *      acting user's user-folder and `isUpdateable()` MUST return true.
     *   2. Else if the relation has `objectId` — the caller MUST hold
     *      `update` on the underlying ObjectEntity per the same
     *      PermissionHandler verdict the rest of OR uses for object writes.
     *   3. Else if the relation has `emailId` — the email's parent
     *      ObjectEntity is looked up via EmailLink, then the same
     *      object-update verdict applies. Email links without a parent
     *      object cannot satisfy an authz check and are denied.
     *   4. Otherwise — deny.
     *
     * Every denial path is logged at `info` (expected operator denials)
     * or `warning` (lookup failures) so unexpected drift is visible.
     *
     * @param EntityRelation $relation Relation row.
     * @param string         $userId   Acting user UID.
     *
     * @return bool True when the acting user may write.
     */
    private function actorCanWriteRelationSubject(EntityRelation $relation, string $userId): bool
    {
        $fileId = $relation->getFileId();
        if ($fileId !== null && $fileId > 0) {
            return $this->canWriteFile(fileId: $fileId, userId: $userId);
        }

        $objectId = $relation->getObjectId();
        if ($objectId !== null && $objectId > 0) {
            return $this->canUpdateObject(
                objectId: $objectId,
                registerId: $relation->getRegisterId(),
                schemaId: $relation->getSchemaId(),
                userId: $userId
            );
        }

        $emailId = $relation->getEmailId();
        if ($emailId !== null && $emailId > 0) {
            return $this->canUpdateEmailParentObject(emailId: $emailId, userId: $userId);
        }

        return false;
    }//end actorCanWriteRelationSubject()

    /**
     * File-bound authz: the file MUST be reachable in the acting user's
     * user-folder and writable (isUpdateable). Mirrors how the rest of
     * OR's per-file authz reads.
     *
     * @param int    $fileId Nextcloud file node id.
     * @param string $userId Acting user UID.
     *
     * @return bool
     */
    private function canWriteFile(int $fileId, string $userId): bool
    {
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
    }//end canWriteFile()

    /**
     * Object-bound authz: caller MUST hold `update` permission on the
     * specific ObjectEntity. Reuses `PermissionHandler::hasPermission`
     * — the same verdict the rest of OR runs for object writes, so this
     * PATCH cannot grant access the saveObject path would have denied.
     *
     * Lookup uses register + schema disambiguation when both are
     * available (the multi-table magic schema means object_id is not
     * globally unique). When either is missing on the relation, falls
     * back to the magic-mapper's cross-table find, which yields
     * `DoesNotExistException` if no match — denied.
     *
     * @param int         $objectId   Object row id on its magic table.
     * @param string|null $registerId Register identifier (uuid or slug).
     * @param string|null $schemaId   Schema identifier (uuid or slug).
     * @param string      $userId     Acting user UID.
     *
     * @return bool
     */
    private function canUpdateObject(
        int $objectId,
        ?string $registerId,
        ?string $schemaId,
        string $userId
    ): bool {
        try {
            $register = $registerId !== null ? $this->registerMapper->find($registerId) : null;
            $schema   = $schemaId !== null ? $this->schemaMapper->find($schemaId) : null;
            $object   = $this->magicMapper->find(
                identifier: $objectId,
                register: $register,
                schema: $schema
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[EntityRelationsController] Object-bound auth check could not resolve object, denying',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'object_id'   => $objectId,
                    'register_id' => $registerId,
                    'schema_id'   => $schemaId,
                    'error'       => $e->getMessage(),
                ]
            );
            return false;
        }//end try

        $resolvedSchemaId = $schemaId !== null ? $schemaId : (string) $object->getSchema();
        try {
            $resolvedSchema = $this->schemaMapper->find($resolvedSchemaId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[EntityRelationsController] Object-bound auth check could not load schema, denying',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'object_id' => $objectId,
                    'schema_id' => $resolvedSchemaId,
                    'error'     => $e->getMessage(),
                ]
            );
            return false;
        }

        return $this->permissionHandler->hasPermission(
            schema: $resolvedSchema,
            action: 'update',
            userId: $userId,
            objectOwner: $object->getOwner(),
            object: $object
        );
    }//end canUpdateObject()

    /**
     * Email-bound authz: emails are linked to an ObjectEntity via
     * EmailLink, so the operator must hold `update` on the parent
     * object. Email links without a parent object cannot satisfy
     * authz and are denied (orphan/system-generated links).
     *
     * @param int    $emailId EmailLink row id.
     * @param string $userId  Acting user UID.
     *
     * @return bool
     */
    private function canUpdateEmailParentObject(int $emailId, string $userId): bool
    {
        try {
            $emailLink = $this->emailLinkMapper->find($emailId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[EntityRelationsController] Email-bound auth check could not load EmailLink, denying',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'email_id' => $emailId,
                    'error'    => $e->getMessage(),
                ]
            );
            return false;
        }

        $parentObjectUuid = $emailLink->getObjectUuid();
        $parentRegisterId = $emailLink->getRegisterId();
        // EmailLink's `@method string getObjectUuid()` claims non-nullable,
        // but the underlying column is `?string`. Guard via empty() so we
        // catch both null and empty-string parents.
        if (empty($parentObjectUuid) === true) {
            $this->logger->info(
                message: '[EntityRelationsController] Email-bound PATCH denied — EmailLink has no parent object',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'email_id' => $emailId,
                    'actor'    => $userId,
                ]
            );
            return false;
        }

        try {
            // EmailLink's `@method int getRegisterId()` is non-nullable in
            // the docblock; defensively coerce via empty() in case the
            // column is unexpectedly null at runtime.
            $register = empty($parentRegisterId) === false ? $this->registerMapper->find((string) $parentRegisterId) : null;
            $object   = $this->magicMapper->find(
                identifier: $parentObjectUuid,
                register: $register
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[EntityRelationsController] Email-bound auth check could not resolve parent object, denying',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'email_id'    => $emailId,
                    'object_uuid' => $parentObjectUuid,
                    'error'       => $e->getMessage(),
                ]
            );
            return false;
        }//end try

        try {
            $schema = $this->schemaMapper->find((string) $object->getSchema());
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[EntityRelationsController] Email-bound auth check could not load parent schema, denying',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'email_id'  => $emailId,
                    'schema_id' => $object->getSchema(),
                    'error'     => $e->getMessage(),
                ]
            );
            return false;
        }

        return $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'update',
            userId: $userId,
            objectOwner: $object->getOwner(),
            object: $object
        );
    }//end canUpdateEmailParentObject()
}//end class

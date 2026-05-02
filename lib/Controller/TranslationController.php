<?php

/**
 * OpenRegister Translation Controller
 *
 * REST endpoints for the translations sidecar:
 *
 *   GET  /api/translations/search             — full-text + filters
 *   GET  /api/translations/object/{uuid}      — list slots + completeness
 *   POST /api/translations/object/{uuid}/{property}/{language}/status
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\BulkTranslationService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class TranslationController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TranslationStatusService $statusService,
        private readonly TranslationMapper $translationMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly BulkTranslationService $bulkService,
        private readonly MagicMapper $objectMapper
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Search the translations sidecar.
     *
     * @NoCSRFRequired
     */
    public function search(
        ?string $query=null,
        ?string $language=null,
        ?string $status=null,
        ?string $objectUuid=null,
        ?int $limit=100
    ): JSONResponse {
        $rows = $this->statusService->search(
            query: $query,
            language: $language,
            status: $status,
            objectUuid: $objectUuid,
            limit: max(1, min(1000, (int) ($limit ?? 100)))
        );

        return new JSONResponse(
                [
                    'results' => $rows,
                    'count'   => count($rows),
                ]
                );
    }//end search()

    /**
     * List every translation slot for one object + completeness summary.
     *
     * Query parameter `schema` (id/uuid/slug) is required to compute
     * completeness against the schema's translatable-property total.
     *
     * @NoCSRFRequired
     */
    public function showByObject(string $uuid, ?string $schema=null): JSONResponse
    {
        $rows       = $this->translationMapper->findByObject($uuid);
        $serialised = array_map(fn(Translation $t) => $t->jsonSerialize(), $rows);

        $completeness = [];
        if ($schema !== null && $schema !== '') {
            $resolvedSchema = $this->resolveSchema($schema);
            if ($resolvedSchema !== null) {
                $completeness = $this->statusService->completenessForObject($uuid, $resolvedSchema);
            }
        }

        return new JSONResponse(
                [
                    'translations' => $serialised,
                    'completeness' => $completeness,
                ]
                );
    }//end showByObject()

    /**
     * Promote / change the workflow status of one translation slot.
     *
     * Body: `{status: "human_reviewed"}`.
     *
     * @NoCSRFRequired
     */
    public function setStatus(string $uuid, string $property, string $language, ?string $status=null): JSONResponse
    {
        if ($status === null || $status === '') {
            return new JSONResponse(
                ['error' => 'status is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $row = $this->statusService->setStatus($uuid, $property, $language, $status);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($row->jsonSerialize());
    }//end setStatus()

    /**
     * Bulk-translate one object's translatable properties from `from`
     * to `to` using the configured TranslationProvider.
     *
     * Body: `{from: "nl", to: "en", properties?: ["title", "body"]}`.
     * Returns `{translated: {prop: value}, skipped: {prop: reason}}`.
     * Caller must persist the returned `translated` map onto the
     * object to make the JSONB authoritative; the sidecar is updated
     * in-place by the service so search sees the new translations
     * before persistence.
     *
     * @NoCSRFRequired
     */
    public function bulkTranslate(
        string $uuid,
        ?string $from=null,
        ?string $to=null,
        ?array $properties=null
    ): JSONResponse {
        if ($from === null || $from === '' || $to === null || $to === '') {
            return new JSONResponse(['error' => 'from and to are required'], Http::STATUS_BAD_REQUEST);
        }

        $object = $this->loadObject($uuid);
        if ($object === null) {
            return new JSONResponse(['error' => 'object not found', 'uuid' => $uuid], Http::STATUS_NOT_FOUND);
        }

        $result = $this->bulkService->translateObject(
            object: $object,
            fromLang: $from,
            toLang: $to,
            properties: $properties
        );

        return new JSONResponse(
                [
                    'uuid'       => $uuid,
                    'from'       => $from,
                    'to'         => $to,
                    'translated' => $result['translated'],
                    'skipped'    => $result['skipped'],
                ]
                );
    }//end bulkTranslate()

    private function loadObject(string $uuid): ?ObjectEntity
    {
        try {
            return $this->objectMapper->find($uuid);
        } catch (DoesNotExistException $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }//end loadObject()

    private function resolveSchema(string $ref): ?Schema
    {
        try {
            return $this->schemaMapper->find($ref, _rbac: false, _multitenancy: false);
        } catch (DoesNotExistException $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }//end resolveSchema()
}//end class

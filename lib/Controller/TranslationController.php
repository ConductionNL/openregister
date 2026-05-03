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
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
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
    /**
     * Constructor.
     *
     * @param string                   $appName           The application name.
     * @param IRequest                 $request           The current request.
     * @param TranslationStatusService $statusService     The translation status service.
     * @param TranslationMapper        $translationMapper The translation mapper.
     * @param SchemaMapper             $schemaMapper      The schema mapper.
     * @param BulkTranslationService   $bulkService       The bulk translation service.
     * @param MagicMapper              $objectMapper      The object mapper.
     */
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
     * @param string|null $query      Optional full-text query.
     * @param string|null $language   Optional language filter.
     * @param string|null $status     Optional status filter.
     * @param string|null $objectUuid Optional object UUID filter.
     * @param int|null    $limit      Maximum number of results.
     *
     * @return JSONResponse JSON response with results and count.
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
     * @param string      $uuid   The object UUID.
     * @param string|null $schema Optional schema ref (id/uuid/slug).
     *
     * @return JSONResponse JSON response with translations and completeness.
     *
     * @NoCSRFRequired
     */
    public function showByObject(string $uuid, ?string $schema=null): JSONResponse
    {
        $rows       = $this->translationMapper->findByObject($uuid);
        $serialised = array_map(fn(Translation $t) => $t->jsonSerialize(), $rows);

        $completeness = [];
        if ($schema !== null && $schema !== '') {
            $resolvedSchema = $this->resolveSchema(ref: $schema);
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
     * @param string      $uuid     The object UUID.
     * @param string      $property The property name.
     * @param string      $language The language code.
     * @param string|null $status   The new workflow status.
     *
     * @return JSONResponse JSON response with the updated row.
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
     * Bulk-translate one object's translatable properties.
     *
     * Translates from `from` to `to` using the configured TranslationProvider.
     *
     * Body: `{from: "nl", to: "en", properties?: ["title", "body"]}`.
     * Returns `{translated: {prop: value}, skipped: {prop: reason}}`.
     * Caller must persist the returned `translated` map onto the
     * object to make the JSONB authoritative; the sidecar is updated
     * in-place by the service so search sees the new translations
     * before persistence.
     *
     * @param string        $uuid       The object UUID.
     * @param string|null   $from       Source language code.
     * @param string|null   $to         Target language code.
     * @param string[]|null $properties Optional whitelist of property names.
     *
     * @return JSONResponse JSON response with translation result.
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

        $object = $this->loadObject(uuid: $uuid);
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

    /**
     * Load an object by UUID; returns null when not found.
     *
     * @param string $uuid The object UUID.
     *
     * @return ObjectEntity|null The object entity or null.
     */
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

    /**
     * Resolve a schema by id/uuid/slug; returns null when not found.
     *
     * @param string $ref The schema reference (id/uuid/slug).
     *
     * @return Schema|null The schema entity or null.
     */
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

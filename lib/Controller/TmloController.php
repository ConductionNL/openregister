<?php

/**
 * TmloController
 *
 * Controller for TMLO (Toepassingsprofiel Metadatastandaard Lokale Overheden)
 * metadata operations including MDTO XML export and archival status summary.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TmloService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for TMLO metadata export and query operations
 *
 * @package OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TmloController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName        The app name
     * @param IRequest        $request        The request object
     * @param TmloService     $tmloService    TMLO metadata service
     * @param ObjectService   $objectService  Object service for querying objects
     * @param RegisterMapper  $registerMapper Register mapper
     * @param SchemaMapper    $schemaMapper   Schema mapper
     * @param LoggerInterface $logger         Logger interface
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TmloService $tmloService,
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Export a single object as MDTO-compliant XML.
     *
     * @param string $register The register ID or slug
     * @param string $schema   The schema ID or slug
     * @param string $id       The object UUID
     *
     * @return Response The MDTO XML response
     *
     * @NoAdminRequired
     */
    public function exportSingle(string $register, string $schema, string $id): Response
    {
        try {
            $registerEntity = $this->registerMapper->find($register);
            $schemaEntity   = $this->schemaMapper->find($schema);

            $object = $this->objectService->find(
                identifier: $id,
                register: $registerEntity,
                schema: $schemaEntity,
            );

            $xml = $this->tmloService->generateMdtoXml($object);

            $response = new DataResponse($xml, Http::STATUS_OK);
            $response->addHeader('Content-Type', 'application/xml; charset=UTF-8');
            return $response;
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (Exception $e) {
            $this->logger->error('MDTO export failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                ['error' => 'MDTO export failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end exportSingle()

    /**
     * Export multiple objects as MDTO-compliant XML.
     *
     * @param string $register The register ID or slug
     * @param string $schema   The schema ID or slug
     *
     * @return Response The MDTO XML response
     *
     * @NoAdminRequired
     */
    public function exportBatch(string $register, string $schema): Response
    {
        try {
            $registerEntity = $this->registerMapper->find($register);
            $schemaEntity   = $this->schemaMapper->find($schema);

            // Get all query parameters for filtering.
            $params  = $this->request->getParams();
            $filters = [];
            foreach ($params as $key => $value) {
                if (str_starts_with($key, 'tmlo.') === true || str_starts_with($key, '_') === true) {
                    $filters[$key] = $value;
                }
            }

            $result = $this->objectService->findAll(
                register: $registerEntity,
                schema: $schemaEntity,
                filters: $filters,
            );

            $objects = ($result['results'] ?? $result);

            $xml = $this->tmloService->generateBatchMdtoXml($objects);

            $response = new DataResponse($xml, Http::STATUS_OK);
            $response->addHeader('Content-Type', 'application/xml; charset=UTF-8');
            return $response;
        } catch (Exception $e) {
            $this->logger->error('MDTO batch export failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                ['error' => 'MDTO batch export failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end exportBatch()

    /**
     * Get archival status summary for a register/schema combination.
     *
     * Returns counts of objects per archiefstatus.
     *
     * @param string $register The register ID or slug
     * @param string $schema   The schema ID or slug
     *
     * @return JSONResponse The summary response
     *
     * @NoAdminRequired
     */
    public function summary(string $register, string $schema): JSONResponse
    {
        try {
            $registerEntity = $this->registerMapper->find($register);

            if ($this->tmloService->isTmloEnabled($registerEntity) === false) {
                return new JSONResponse(
                    ['error' => 'TMLO is not enabled on this register'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $schemaEntity = $this->schemaMapper->find($schema);

            // Initialize counts.
            $counts = [
                TmloService::ARCHIEFSTATUS_ACTIEF        => 0,
                TmloService::ARCHIEFSTATUS_SEMI_STATISCH => 0,
                TmloService::ARCHIEFSTATUS_OVERGEBRACHT  => 0,
                TmloService::ARCHIEFSTATUS_VERNIETIGD    => 0,
            ];

            // Query objects for each status.
            foreach ($counts as $status => $count) {
                $result          = $this->objectService->findAll(
                    register: $registerEntity,
                    schema: $schemaEntity,
                    filters: ['tmlo.archiefstatus' => $status, '_limit' => 0],
                );
                $counts[$status] = ($result['total'] ?? 0);
            }

            return new JSONResponse($counts, Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error('TMLO summary failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                ['error' => 'TMLO summary failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end summary()
}//end class

<?php

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;

/**
 * Controller for dashboard related operations
 * 
 * Handles dashboard statistics and page rendering
 */
class DashboardController extends Controller {
    /** @var RegisterMapper */
    private $registerMapper;
    
    /** @var SchemaMapper */
    private $schemaMapper;
    
    /** @var ObjectEntityMapper */
    private $objectMapper;
    
    /** @var AuditTrailMapper */
    private $auditTrailMapper;

    /**
     * Constructor for DashboardController
     * 
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param RegisterMapper $registerMapper Mapper for register operations
     * @param SchemaMapper $schemaMapper Mapper for schema operations
     * @param ObjectEntityMapper $objectMapper Mapper for object operations
     * @param AuditTrailMapper $auditTrailMapper Mapper for audit trail operations
     */
    public function __construct(
        $appName,
        IRequest $request,
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        ObjectEntityMapper $objectMapper,
        AuditTrailMapper $auditTrailMapper
    ) {
        parent::__construct($appName, $request);
        $this->registerMapper = $registerMapper;
        $this->schemaMapper = $schemaMapper;
        $this->objectMapper = $objectMapper;
        $this->auditTrailMapper = $auditTrailMapper;
    }

    /**
     * Get basic statistics about registers, schemas, objects and audit logs
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return JSONResponse Statistics data or error message
     */
    public function stats(): JSONResponse {
        try {
            $stats = [
                'registers' => $this->registerMapper->count(),
                'schemas' => $this->schemaMapper->count(),
                'objects' => $this->objectMapper->countAll(),
                'auditLogs' => $this->auditTrailMapper->count(),
            ];
            return new JSONResponse($stats);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get audit statistics for a given time period
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string|null $from Start date (defaults to 7 days ago)
     * @param string|null $to End date (defaults to today)
     * @return JSONResponse Audit statistics or error message
     */
    public function auditStats(?string $from = null, ?string $to = null): JSONResponse {
        try {
            $fromDate = $from ? new \DateTime($from) : new \DateTime('-7 days');
            $toDate = $to ? new \DateTime($to) : new \DateTime();

            $stats = $this->auditTrailMapper->getDailyStats($fromDate, $toDate);
            return new JSONResponse($stats);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Render the dashboard page
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string|null $getParameter Optional GET parameter
     * @return TemplateResponse The rendered page
     */
    public function page(?string $getParameter = null): TemplateResponse
    {
        try {
            $response = new TemplateResponse(
                $this->appName,
                'index',
                []
            );
            
            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            return new TemplateResponse(
                $this->appName,
                'error',
                ['error' => $e->getMessage()],
                '500'
            );
        }
    }

    /**
     * Get growth statistics for registers and schemas
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string|null $from Start date
     * @param string|null $to End date
     * @param int|null $schemaId Optional schema ID filter
     * @param int|null $registerId Optional register ID filter
     * @return JSONResponse Growth statistics or error message
     */
    public function growthStats(
        ?string $from = null,
        ?string $to = null,
        ?int $schemaId = null,
        ?int $registerId = null
    ): JSONResponse {
        try {
            $fromDate = $from ? new \DateTime($from) : new \DateTime('-7 days');
            $toDate = $to ? new \DateTime($to) : new \DateTime();

            $registerGrowth = $this->objectMapper->getRegisterGrowth($fromDate, $toDate, $registerId);
            $schemaDistribution = $this->objectMapper->getSchemaDistribution($fromDate, $toDate, $schemaId);

            return new JSONResponse([
                'registerGrowth' => $registerGrowth,
                'schemaDistribution' => $schemaDistribution,
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get data quality statistics
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string|null $from Start date
     * @param string|null $to End date
     * @param int|null $schemaId Optional schema ID filter
     * @param int|null $registerId Optional register ID filter
     * @return JSONResponse Data quality statistics
     */
    public function qualityStats(
        ?string $from = null,
        ?string $to = null,
        ?int $schemaId = null,
        ?int $registerId = null
    ): JSONResponse {
        try {
            $fromDate = $from ? new \DateTime($from) : new \DateTime('-7 days');
            $toDate = $to ? new \DateTime($to) : new \DateTime();

            $validationErrors = $this->objectMapper->getValidationStats($fromDate, $toDate, $schemaId, $registerId);
            $completeness = $this->objectMapper->getCompletenessStats($fromDate, $toDate, $schemaId, $registerId);
            $revisions = $this->objectMapper->getRevisionStats($fromDate, $toDate, $schemaId, $registerId);

            return new JSONResponse([
                'validationErrors' => $validationErrors,
                'completeness' => $completeness,
                'revisions' => $revisions,
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get schema analysis statistics
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string|null $from Start date
     * @param string|null $to End date
     * @param int|null $schemaId Optional schema ID filter
     * @param int|null $registerId Optional register ID filter
     * @return JSONResponse Schema analysis statistics
     */
    public function schemaStats(
        ?string $from = null,
        ?string $to = null,
        ?int $schemaId = null,
        ?int $registerId = null
    ): JSONResponse {
        try {
            $fromDate = $from ? new \DateTime($from) : new \DateTime('-7 days');
            $toDate = $to ? new \DateTime($to) : new \DateTime();

            $fieldTypes = $this->schemaMapper->getFieldTypeStats($fromDate, $toDate, $schemaId);
            $fieldUsage = $this->schemaMapper->getFieldUsageStats($fromDate, $toDate, $schemaId);
            $schemaComplexity = $this->schemaMapper->getComplexityStats($fromDate, $toDate, $schemaId);
            $versionDistribution = $this->schemaMapper->getVersionDistribution($fromDate, $toDate, $schemaId);

            return new JSONResponse([
                'fieldTypes' => $fieldTypes,
                'fieldUsage' => $fieldUsage,
                'complexity' => $schemaComplexity,
                'versions' => $versionDistribution,
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get access statistics
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string|null $from Start date
     * @param string|null $to End date
     * @return JSONResponse Access statistics
     */
    public function accessStats(?string $from = null, ?string $to = null): JSONResponse {
        try {
            $fromDate = $from ? new \DateTime($from) : new \DateTime('-7 days');
            $toDate = $to ? new \DateTime($to) : new \DateTime();

            $stats = $this->auditTrailMapper->getAccessStats($fromDate, $toDate);
            return new JSONResponse($stats);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}

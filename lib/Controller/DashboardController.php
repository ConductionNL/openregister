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
use OCA\OpenRegister\Db\ObjectAuditLogMapper;

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
    
    /** @var ObjectAuditLogMapper */
    private $auditLogMapper;

    /**
     * Constructor for DashboardController
     * 
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param RegisterMapper $registerMapper Mapper for register operations
     * @param SchemaMapper $schemaMapper Mapper for schema operations
     * @param ObjectEntityMapper $objectMapper Mapper for object operations
     * @param ObjectAuditLogMapper $auditLogMapper Mapper for audit log operations
     */
    public function __construct(
        $appName,
        IRequest $request,
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        ObjectEntityMapper $objectMapper,
        ObjectAuditLogMapper $auditLogMapper
    ) {
        parent::__construct($appName, $request);
        $this->registerMapper = $registerMapper;
        $this->schemaMapper = $schemaMapper;
        $this->objectMapper = $objectMapper;
        $this->auditLogMapper = $auditLogMapper;
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
                'auditLogs' => $this->auditLogMapper->count(),
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

            $stats = $this->auditLogMapper->getDailyStats($fromDate, $toDate);
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
}

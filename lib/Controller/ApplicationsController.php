<?php
/**
 * OpenRegister Applications Controller
 *
 * This file contains the controller for managing applications.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ApplicationService;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * ApplicationsController
 *
 * REST API controller for managing applications.
 *
 * @package OCA\OpenRegister\Controller
 */
class ApplicationsController extends Controller
{

    /**
     * Application service for business logic
     *
     * @var ApplicationService
     */
    private ApplicationService $applicationService;

    /**
     * Application mapper for direct database operations
     *
     * @var ApplicationMapper
     */
    private ApplicationMapper $applicationMapper;

    /**
     * Logger for debugging and error tracking
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * ApplicationsController constructor
     *
     * @param string             $appName            Application name
     * @param IRequest           $request            HTTP request
     * @param ApplicationService $applicationService Application service
     * @param ApplicationMapper  $applicationMapper  Application mapper
     * @param LoggerInterface    $logger             Logger service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ApplicationService $applicationService,
        ApplicationMapper $applicationMapper,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->applicationService = $applicationService;
        $this->applicationMapper  = $applicationMapper;
        $this->logger = $logger;

    }//end __construct()


    /**
     * This returns the template of the main app's page
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'openregister',
            'index',
            []
        );

    }//end page()


    /**
     * Get all applications
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of applications
     */
    public function index(): JSONResponse
    {
        try {
            $params = $this->request->getParams();
            
            // Extract pagination and search parameters
            $limit  = isset($params['_limit']) ? (int) $params['_limit'] : null;
            $offset = isset($params['_offset']) ? (int) $params['_offset'] : null;
            $page   = isset($params['_page']) ? (int) $params['_page'] : null;
            $search = $params['_search'] ?? '';
            
            // Convert page to offset if provided
            if ($page !== null && $limit !== null) {
                $offset = ($page - 1) * $limit;
            }
            
            // Remove special query params from filters
            $filters = $params;
            unset($filters['_limit'], $filters['_offset'], $filters['_page'], $filters['_search'], $filters['_route']);

            $applications = $this->applicationMapper->findAll(
                $limit,
                $offset,
                $filters
            );

            return new JSONResponse(['results' => $applications], Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get applications',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to retrieve applications'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

    }//end index()


    /**
     * Get a single application
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id Application ID
     *
     * @return JSONResponse Application details
     */
    public function show(int $id): JSONResponse
    {
        try {
            $application = $this->applicationService->find($id);

            return new JSONResponse($application, Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get application',
                [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Application not found'],
                Http::STATUS_NOT_FOUND
            );
        }

    }//end show()


    /**
     * Create a new application
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Created application
     */
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            unset($data['_route']);

            $application = $this->applicationService->create($data);

            return new JSONResponse($application, Http::STATUS_CREATED);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to create application',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to create application: ' . $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }

    }//end create()


    /**
     * Update an existing application
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id Application ID
     *
     * @return JSONResponse Updated application
     */
    public function update(int $id): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            
            // Remove internal parameters and immutable fields
            unset($data['_route']);
            unset($data['id']);
            unset($data['organisation']);
            unset($data['owner']);
            unset($data['created']);

            $application = $this->applicationService->update($id, $data);

            return new JSONResponse($application, Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to update application',
                [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to update application: ' . $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }

    }//end update()


    /**
     * Patch (partially update) an application
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the application to patch
     *
     * @return JSONResponse The updated application data
     */
    public function patch(int $id): JSONResponse
    {
        return $this->update($id);

    }//end patch()


    /**
     * Delete an application
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id Application ID
     *
     * @return JSONResponse Success message
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $this->applicationService->delete($id);

            return new JSONResponse(['message' => 'Application deleted successfully'], Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to delete application',
                [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to delete application'],
                Http::STATUS_BAD_REQUEST
            );
        }

    }//end destroy()


}//end class


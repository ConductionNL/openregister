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
        parent::__construct(appName: $appName, request: $request);
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
                appName: 'openregister',
                templateName: 'index',
                params: []
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

            // Extract pagination and search parameters.
            $limit  = $this->extractLimit($params);
            $offset = $this->extractOffset($params);
            $page   = $this->extractPage($params);
            $search = $params['_search'] ?? '';

            // Convert page to offset if provided.
            if ($page !== null && $limit !== null) {
                $offset = ($page - 1) * $limit;
            }

            // Remove special query params from filters.
            $filters = $params;
            unset($filters['_limit'], renderAs: $filters['_offset'], $filters['_page'], $filters['_search'], $filters['_route']);

            $applications = $this->applicationMapper->findAll(
                $limit,
                $offset,
                $filters
            );

            return new JSONResponse(data: ['results' => $applications], statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to get applications',
                context: [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to retrieve applications'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end index()


    /**
     * Get a single application
     *
     * @param int $id Application ID
     *
     * @return JSONResponse Application details
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(int $id): JSONResponse
    {
        try {
            $application = $this->applicationService->find($id);

            return new JSONResponse(data: $application, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to get application',
                context: [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Application not found'], statusCode: Http::STATUS_NOT_FOUND);
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

            return new JSONResponse(data: $application, statusCode: Http::STATUS_CREATED);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to create application',
                context: [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to create application: '.$e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try

    }//end create()


    /**
     * Update an existing application
     *
     * @param int $id Application ID
     *
     * @return JSONResponse Updated application
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(int $id): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Remove internal parameters and immutable fields.
            unset($data['_route']);
            unset($data['id']);
            unset($data['organisation']);
            unset($data['owner']);
            unset($data['created']);

            $application = $this->applicationService->update($id, $data);

            return new JSONResponse(data: $application, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to update application',
                context: [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to update application: '.$e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try

    }//end update()


    /**
     * Patch (partially update) an application
     *
     * @param int $id The ID of the application to patch
     *
     * @return JSONResponse The updated application data
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function patch(int $id): JSONResponse
    {
        return $this->update($id);

    }//end patch()


    /**
     * Delete an application
     *
     * @param int $id Application ID
     *
     * @return JSONResponse Success message
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $this->applicationService->delete($id);

            return new JSONResponse(data: ['message' => 'Application deleted successfully'], statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to delete application',
                context: [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to delete application'], statusCode: Http::STATUS_BAD_REQUEST);
        }

    }//end destroy()


    /**
     * Extract limit parameter from request params.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return int|null Limit value or null
     */
    private function extractLimit(array $params): ?int
    {
        if (isset($params['_limit']) === true) {
            return (int) $params['_limit'];
        }

        return null;

    }//end extractLimit()


    /**
     * Extract offset parameter from request params.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return int|null Offset value or null
     */
    private function extractOffset(array $params): ?int
    {
        if (isset($params['_offset']) === true) {
            return (int) $params['_offset'];
        }

        return null;

    }//end extractOffset()


    /**
     * Extract page parameter from request params.
     *
     * @param array<string, mixed> $params Request parameters
     *
     * @return int|null Page value or null
     */
    private function extractPage(array $params): ?int
    {
        if (isset($params['_page']) === true) {
            return (int) $params['_page'];
        }

        return null;

    }//end extractPage()


}//end class

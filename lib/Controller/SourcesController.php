<?php
/**
 * Class SourcesController
 *
 * Controller for managing source operations in the OpenRegister app.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\AppInfo
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Class SourcesController
 */
class SourcesController extends Controller
{


    /**
     * Constructor for the SourcesController
     *
     * @param string       $appName      The name of the app
     * @param IRequest     $request      The request object
     * @param IAppConfig   $config       The app configuration object
     * @param SourceMapper $sourceMapper The source mapper
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SourceMapper $sourceMapper
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Returns the template of the main app's page
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The rendered template response
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'openconnector',
            'index',
            []
        );

    }//end page()


    /**
     * Retrieves a list of all sources
     *
     * This method returns a JSON response containing an array of all sources in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param ObjectService $objectService The object service
     * @param SearchService $searchService The search service
     *
     * @return JSONResponse A JSON response containing the list of sources
     */
    public function index(
        ObjectService $objectService,
        SearchService $searchService
    ): JSONResponse {
        // Get request parameters for filtering and searching
        $filters        = $this->request->getParams();
        $fieldsToSearch = ['title', 'description'];

        // Create search parameters and conditions for filtering
        $searchParams     = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(
            filters: $filters,
            fieldsToSearch: $fieldsToSearch
        );
        $filters          = $searchService->unsetSpecialQueryParams(filters: $filters);

        // Return all sources that match the search conditions
        return new JSONResponse(
            [
                'results' => $this->sourceMapper->findAll(
                    limit: null,
                    offset: null,
                    filters: $filters,
                    searchConditions: $searchConditions,
                    searchParams: $searchParams
                ),
            ]
        );

    }//end index()


    /**
     * Retrieves a single source by its ID
     *
     * This method returns a JSON response containing the details of a specific source.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the source to retrieve
     *
     * @return JSONResponse A JSON response containing the source details
     */
    public function show(string $id): JSONResponse
    {
        try {
            // Try to find the source by ID
            return new JSONResponse($this->sourceMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            // Return a 404 error if the source doesn't exist
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        }

    }//end show()


    /**
     * Creates a new source
     *
     * This method creates a new source based on POST data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the created source
     */
    public function create(): JSONResponse
    {
        // Get request parameters
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_')
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to ensure a new record is created
        if (isset($data['id'])) {
            unset($data['id']);
        }

        // Create a new source from the data
        return new JSONResponse($this->sourceMapper->createFromArray(object: $data));

    }//end create()


    /**
     * Updates an existing source
     *
     * This method updates an existing source based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the source to update
     *
     * @return JSONResponse A JSON response containing the updated source details
     */
    public function update(int $id): JSONResponse
    {
        // Get request parameters
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_')
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to prevent conflicts
        if (isset($data['id'])) {
            unset($data['id']);
        }

        // Update the source with the provided data
        return new JSONResponse($this->sourceMapper->updateFromArray(id: (int) $id, object: $data));

    }//end update()


    /**
     * Deletes a source
     *
     * This method deletes a source based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the source to delete
     *
     * @return JSONResponse An empty JSON response
     * @throws Exception If there is an error deleting the source
     */
    public function destroy(int $id): JSONResponse
    {
        // Find the source by ID and delete it
        $this->sourceMapper->delete($this->sourceMapper->find((int) $id));

        // Return an empty response
        return new JSONResponse([]);

    }//end destroy()


}//end class

<?php
/**
 * Class SourcesController
 *
 * Controller for managing source operations in the OpenRegister app.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
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
     * Retrieves a list of all sources
     *
     * This method returns a JSON response containing an array of all sources in the system.
     *
     * @return JSONResponse A JSON response containing the list of sources
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, array{results: array<Source>}, array<never, never>>
     */
    public function index(): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $params = $this->request->getParams();

        // Extract pagination and search parameters.
        $limit  = $this->getIntParam($params, '_limit');
        $offset = $this->getIntParam($params, '_offset');
        $page   = $this->getIntParam($params, '_page');
        $search = $params['_search'] ?? '';

        // Convert page to offset if provided.
        if ($page !== null && $limit !== null) {
            $offset = ($page - 1) * $limit;
        }

        // Remove special query params from filters.
        $filters = $params;
        unset($filters['_limit'], $filters['_offset'], $filters['_page'], $filters['_search'], $filters['_route']);

        // Return all sources that match the filters.
        return new JSONResponse(
            [
                'results' => $this->sourceMapper->findAll(
                    limit: $limit,
                    offset: $offset,
                    filters: $filters
                ),
            ]
        );

    }//end index()


    /**
     * Retrieves a single source by its ID
     *
     * This method returns a JSON response containing the details of a specific source.
     *
     * @param string $id The ID of the source to retrieve
     *
     * @return JSONResponse A JSON response containing the source details
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, Source, array<never, never>>|JSONResponse<404, array{error: 'Not Found'}, array<never, never>>
     */
    public function show(string $id): JSONResponse
    {
        try {
            // Try to find the source by ID.
            return new JSONResponse($this->sourceMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            // Return a 404 error if the source doesn't exist.
            return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
        }

    }//end show()


    /**
     * Creates a new source
     *
     * This method creates a new source based on POST data.
     *
     * @return JSONResponse A JSON response containing the created source
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, Source, array<never, never>>
     */
    public function create(): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to ensure a new record is created.
        if (isset($data['id']) === true) {
            unset($data['id']);
        }

        // Create a new source from the data.
        return new JSONResponse($this->sourceMapper->createFromArray(object: $data));

    }//end create()


    /**
     * Updates an existing source
     *
     * This method updates an existing source based on its ID.
     *
     * @param int $id The ID of the source to update
     *
     * @return JSONResponse A JSON response containing the updated source details
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, Source, array<never, never>>
     */
    public function update(int $id): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove immutable fields to prevent tampering.
        unset($data['id']);
        unset($data['organisation']);
        unset($data['owner']);
        unset($data['created']);

        // Update the source with the provided data.
        return new JSONResponse($this->sourceMapper->updateFromArray(id: (int) $id, object: $data));

    }//end update()


    /**
     * Patch (partially update) a source
     *
     * @param int $id The ID of the source to patch
     *
     * @return JSONResponse The updated source data
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function patch(int $id): JSONResponse
    {
        return $this->update($id);

    }//end patch()


    /**
     * Deletes a source
     *
     * This method deletes a source based on its ID.
     *
     * @param int $id The ID of the source to delete
     *
     * @return JSONResponse An empty JSON response
     *
     * @throws Exception If there is an error deleting the source
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, array<never, never>, array<never, never>>
     */
    public function destroy(int $id): JSONResponse
    {
        // Find the source by ID and delete it.
        $this->sourceMapper->delete($this->sourceMapper->find((int) $id));

        // Return an empty response.
        return new JSONResponse([]);

    }//end destroy()


    /**
     * Get integer parameter from params array or return null
     *
     * @param array<string, mixed> $params Parameters array
     * @param string               $key    Parameter key
     *
     * @return int|null Integer value or null
     */
    private function getIntParam(array $params, string $key): ?int
    {
        if (isset($params[$key]) === true) {
            return (int) $params[$key];
        }

        return null;

    }//end getIntParam()


}//end class

<?php
/**
 * Class SearchController
 *
 * Controller for handling search operations in the OpenRegister app.
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

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISearch;
use OCP\Search\Result;

/**
 * Class SearchController
 *
 * Controller for handling search operations in the application.
 * Provides functionality to search across the application using the Nextcloud search service.
 */
class SearchController extends Controller
{

    /**
     * The search service instance.
     *
     * @var \OCP\ISearch The Nextcloud search service
     */
    private readonly ISearch $searchService;


    /**
     * Constructor for the SearchController
     *
     * @param string   $appName       The name of the app
     * @param IRequest $request       The request object
     * @param ISearch  $searchService The search service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ISearch $searchService
    ) {
        parent::__construct($appName, $request);
        $this->searchService = $searchService;

    }//end __construct()


    /**
     * Handles search requests and forwards them to the Nextcloud search service
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the search results
     */
    public function search(): JSONResponse
    {
        // Get the search query from the request parameters.
        $query = $this->request->getParam('query', '');

        // Perform the search using the search service.
        $results = $this->searchService->search($query);

        // Format the search results for the JSON response.
        $formattedResults = array_map(
            function (Result $result) {
                return [
                    'id'     => $result->getId(),
                    'name'   => $result->getName(),
                    'type'   => $result->getType(),
                    'url'    => $result->getUrl(),
                    'source' => $result->getSource(),
                ];
            },
            $results
        );

        return new JSONResponse($formattedResults);

    }//end search()


}//end class

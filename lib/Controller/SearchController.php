<?php

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISearch;
use OCP\Search\Result;

class SearchController extends Controller
{
    private ISearch $searchService;

    /**
     * Constructor for the SearchController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param ISearch $searchService The search service
     */
    public function __construct(
        $appName,
        IRequest $request,
        ISearch $searchService
    ) {
        parent::__construct($appName, $request);
        $this->searchService = $searchService;
    }

    /**
     * Handles search requests and forwards them to the Nextcloud search service
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the search results
     */
    public function search(): JSONResponse
    {
        $query = $this->request->getParam('query', '');
        $results = $this->searchService->search($query);

        $formattedResults = array_map(function (Result $result) {
            return [
                'id' => $result->getId(),
                'name' => $result->getName(),
                'type' => $result->getType(),
                'url' => $result->getUrl(),
                'source' => $result->getSource(),
            ];
        }, $results);

        return new JSONResponse($formattedResults);
    }
}

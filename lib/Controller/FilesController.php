<?php

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Opis\JsonSchema\Errors\ErrorFormatter;

class FilesController extends Controller
{


    /**
     * Constructor for the ObjectsController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration object
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly IAppManager $appManager,
        private readonly FileService $fileService,

    )
    {
        parent::__construct($appName, $request);
    }

	/**
	 * Search all files in a specific folder(/path) where file content contains the search term
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse A JSON response containing the object's files
	 */
    public function search(): JSONResponse
    {
		$data = $this->request->getParams();

		try {
			$results = $this->fileService->search(searchTerm: $data['_search'], folderPath: $data['_folderPath'] ?? null);

			return new JSONResponse(['results' => $results]);
		} catch (InvalidPathException|NotFoundException|NotPermittedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
    }
}

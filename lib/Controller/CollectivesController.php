<?php

/**
 * CollectivesController — REST endpoints for the Collectives integration.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-collectives/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\CollectivesPageService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * CollectivesController exposes REST endpoints under
 * /api/objects/{register}/{schema}/{id}/collectives.
 *
 * All endpoints are #[NoAdminRequired] and include per-object authorization via
 * the ObjectService ownership check before operating on links.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class CollectivesController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                 $appName                Application name
     * @param IRequest               $request                HTTP request
     * @param CollectivesPageService $collectivesPageService Collectives page service
     * @param ObjectService          $objectService          Object service
     * @param LoggerInterface        $logger                 Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CollectivesPageService $collectivesPageService,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List collectives accessible to the current user.
     *
     * GET /api/collectives
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/integration-collectives/tasks.md#task-3
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listCollectives(): JSONResponse
    {
        if ($this->collectivesPageService->isCollectivesAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $collectives = $this->collectivesPageService->listCollectives();
            return new JSONResponse(['results' => $collectives, 'total' => count($collectives)]);
        } catch (Exception $e) {
            $this->logger->error('CollectivesController: listCollectives error: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end listCollectives()

    /**
     * List pages within a collective.
     *
     * GET /api/collectives/{collective}/pages
     *
     * @param string $collective The collective name.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/integration-collectives/tasks.md#task-3
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listPages(string $collective): JSONResponse
    {
        if ($this->collectivesPageService->isCollectivesAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $pages = $this->collectivesPageService->listPages($collective);
            return new JSONResponse(['results' => $pages, 'total' => count($pages)]);
        } catch (Exception $e) {
            $this->logger->error('CollectivesController: listPages error: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end listPages()

    /**
     * List collective page links for an object.
     *
     * GET /api/objects/{register}/{schema}/{id}/collectives
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $id       The object ID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/integration-collectives/tasks.md#task-3
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->collectivesPageService->isCollectivesAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $result = $this->collectivesPageService->getLinksForObject($object->getUuid());
            return new JSONResponse($result);
        } catch (Exception $e) {
            $this->logger->error('CollectivesController: index error: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end index()

    /**
     * Link an existing Collectives page to an object.
     *
     * POST /api/objects/{register}/{schema}/{id}/collectives
     *
     * Request body: { collectiveName: string, pageId: int, pageTitle?: string }
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $id       The object ID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/integration-collectives/tasks.md#task-3
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->collectivesPageService->isCollectivesAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $body           = $this->request->getParams();
            $collectiveName = $body['collectiveName'] ?? '';
            $pageId         = isset($body['pageId']) === true ? (int) $body['pageId'] : 0;
            $pageTitle      = $body['pageTitle'] ?? '';

            if ($collectiveName === '' || $pageId === 0) {
                return new JSONResponse(['error' => 'collectiveName and pageId are required'], 400);
            }

            $link = $this->collectivesPageService->linkPage(
                objectUuid: $object->getUuid(),
                collectiveName: $collectiveName,
                pageId: $pageId,
                pageTitle: $pageTitle
            );

            return new JSONResponse($link, 201);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 409) {
                return new JSONResponse(['error' => $e->getMessage()], 409);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end create()

    /**
     * Unlink a Collectives page from an object.
     *
     * DELETE /api/objects/{register}/{schema}/{id}/collectives/{linkId}
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $id       The object ID.
     * @param string $linkId   The collective link row ID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/integration-collectives/tasks.md#task-3
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function destroy(string $register, string $schema, string $id, string $linkId): JSONResponse
    {
        if ($this->collectivesPageService->isCollectivesAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->collectivesPageService->unlinkPage((int) $linkId);
            return new JSONResponse(['success' => true]);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return new JSONResponse(['error' => $e->getMessage()], 404);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end destroy()

    /**
     * Fetch the markdown content of a linked page.
     *
     * GET /api/objects/{register}/{schema}/{id}/collectives/{linkId}/content
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $id       The object ID.
     * @param string $linkId   The collective link row ID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/integration-collectives/tasks.md#task-3
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function content(string $register, string $schema, string $id, string $linkId): JSONResponse
    {
        if ($this->collectivesPageService->isCollectivesAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Collectives app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            // Find the link to get collective/page coordinates.
            $links = $this->collectivesPageService->getLinksForObject($object->getUuid());
            $link  = null;
            foreach ($links['results'] as $l) {
                if ((int) $l['id'] === (int) $linkId) {
                    $link = $l;
                    break;
                }
            }

            if ($link === null) {
                return new JSONResponse(['error' => 'Link not found'], 404);
            }

            $markdownContent = $this->collectivesPageService->getPageContent(
                collectiveName: $link['collectiveName'],
                pageId: (int) $link['pageId']
            );

            if ($markdownContent === null) {
                return new JSONResponse(['error' => 'No access to this page', 'code' => 'ACCESS_DENIED'], 403);
            }

            return new JSONResponse(['content' => $markdownContent]);
        } catch (Exception $e) {
            $this->logger->error('CollectivesController: content error: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end content()

    /**
     * Resolve an object from register/schema/id route parameters.
     *
     * Returns null when the object does not exist or the user cannot access it.
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $id       The object ID.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null
     */
    private function resolveObject(
        string $register,
        string $schema,
        string $id
    ): ?\OCA\OpenRegister\Db\ObjectEntity {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();
    }//end resolveObject()
}//end class

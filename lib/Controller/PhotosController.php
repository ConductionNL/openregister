<?php

/**
 * PhotosController — REST controller for the Photos integration.
 *
 * Provides sub-resource endpoints under /api/objects/{register}/{schema}/{id}/photos.
 * Photos are a filtered view of Files (image MIME types only), enriched with
 * lazy-extracted EXIF metadata per the integration-photos spec.
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
 * @spec openspec/changes/integration-photos/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PhotoService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for photo sub-resource operations on objects.
 *
 * All endpoints live under /apps/openregister/api/objects/{register}/{schema}/{id}/photos.
 * Photo access inherits Nextcloud file permissions (requiresPermission() === null).
 *
 * @psalm-suppress UnusedClass
 */
class PhotosController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName       Application name.
     * @param IRequest        $request       HTTP request.
     * @param PhotoService    $photoService  Photo service.
     * @param ObjectService   $objectService Object service.
     * @param IUserSession    $userSession   Nextcloud user session.
     * @param LoggerInterface $logger        Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly PhotoService $photoService,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all photos attached to an object (images filtered from files).
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/integration-photos/tasks.md#task-3
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
            }

            $photos = $this->photoService->getPhotos(object: $object);

            $results = [];
            foreach ($photos as $photo) {
                $results[] = $this->photoService->formatPhoto(file: $photo);
            }

            return new JSONResponse(data: ['results' => $results, 'count' => count($results)]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logError(message: 'index', exception: $e);
            return new JSONResponse(data: ['error' => 'Operation failed'], statusCode: 500);
        }//end try
    }//end index()

    /**
     * Get a specific photo with EXIF metadata.
     *
     * Returns the photo file metadata enriched with lazily-extracted EXIF data
     * (AD-2: EXIF is extracted on first view per file and cached).
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object ID.
     * @param int    $fileId   Nextcloud file ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/integration-photos/tasks.md#task-3
     */
    public function show(string $register, string $schema, string $id, int $fileId): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
            }

            $photo = $this->photoService->getPhotoWithExif(object: $object, fileId: $fileId);

            if ($photo === null) {
                return new JSONResponse(data: ['error' => 'Photo not found'], statusCode: 404);
            }

            return new JSONResponse(data: $photo);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logError(message: 'show', exception: $e);
            return new JSONResponse(data: ['error' => 'Operation failed'], statusCode: 500);
        }//end try
    }//end show()

    /**
     * Log a controller error without exposing internals to the response.
     *
     * @param string    $message   Action name for the log entry.
     * @param Exception $exception The caught exception.
     *
     * @return void
     */
    private function logError(string $message, Exception $exception): void
    {
        $this->logger->error(
            message: 'PhotosController::'.$message,
            context: ['exception' => $exception->getMessage()]
        );
    }//end logError()
}//end class

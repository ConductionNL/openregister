<?php

/**
 * PhotosController — REST endpoints for the Photos integration.
 *
 * Sub-resource under objects: list, get (with EXIF), link, unlink.
 * Reuses the openregister_file_links table filtered to image/* MIME types.
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for photo sub-resource operations on objects.
 *
 * List/show are @PublicPage — inheriting NC file permissions (requiresPermission() === null).
 * Mutating endpoints (link/unlink) require authentication via OCSForbiddenException guard.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     * @param IUserSession    $userSession   Nextcloud user session for auth guards.
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
     * List all photos attached to an object (image/* MIME filter).
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object UUID.
     *
     * @return JSONResponse
     *
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

            $photos = $this->photoService->getPhotos(objectUuid: $object->getUuid());

            return new JSONResponse(data: array_map(static fn($p) => $p->jsonSerialize(), $photos));
        } catch (Exception $e) {
            $this->logger->error(
                message: 'PhotosController::index',
                context: ['exception' => $e->getMessage()]
            );
            return new JSONResponse(data: ['error' => 'Operation failed'], statusCode: 500);
        }//end try
    }//end index()

    /**
     * Get a specific photo with lazily-extracted EXIF metadata.
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object UUID.
     * @param int    $photoId  FileLink row ID.
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/integration-photos/tasks.md#task-3
     */
    public function show(string $register, string $schema, string $id, int $photoId): JSONResponse
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
            }

            $photo = $this->photoService->getPhoto(objectUuid: $object->getUuid(), linkId: $photoId);

            if ($photo === null) {
                return new JSONResponse(data: ['error' => 'Photo not found'], statusCode: 404);
            }

            return new JSONResponse(data: $photo->jsonSerialize());
        } catch (Exception $e) {
            $this->logger->error(
                message: 'PhotosController::show',
                context: ['exception' => $e->getMessage()]
            );
            return new JSONResponse(data: ['error' => 'Operation failed'], statusCode: 500);
        }//end try
    }//end show()

    /**
     * Link a Nextcloud file to an object as a photo.
     *
     * Expects JSON body: { "fileId": <int> }
     * Per-object guard: throws OCSForbiddenException when caller is unauthenticated.
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @throws OCSForbiddenException When the caller is not authenticated.
     */
    public function create(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
            }

            $data   = $this->request->getParams();
            $fileId = array_key_exists('fileId', $data) === true ? (int) $data['fileId'] : null;

            if ($fileId === null || $fileId <= 0) {
                return new JSONResponse(data: ['error' => 'fileId is required'], statusCode: 400);
            }

            $link = $this->photoService->linkPhoto(objectUuid: $object->getUuid(), fileId: $fileId);

            return new JSONResponse(data: $link->jsonSerialize(), statusCode: 201);
        } catch (OCSForbiddenException $e) {
            throw $e;
        } catch (Exception $e) {
            $status = str_contains($e->getMessage(), 'not an image') === true ? 400 : 500;

            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $status);
        }//end try
    }//end create()

    /**
     * Unlink a photo from an object (removes the link row, not the file).
     *
     * Per-object guard: throws OCSForbiddenException when caller is unauthenticated.
     *
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     * @param string $id       Object UUID.
     * @param int    $photoId  FileLink row ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @throws OCSForbiddenException When the caller is not authenticated.
     */
    public function delete(string $register, string $schema, string $id, int $photoId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);

        try {
            $this->objectService->setObject($id);
            $object = $this->objectService->getObject();

            if ($object === null) {
                return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
            }

            $success = $this->photoService->unlinkPhoto(
                objectUuid: $object->getUuid(),
                linkId: $photoId
            );

            if ($success === false) {
                return new JSONResponse(data: ['error' => 'Photo not found'], statusCode: 404);
            }

            return new JSONResponse(data: ['success' => true]);
        } catch (OCSForbiddenException $e) {
            throw $e;
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end delete()

    /**
     * Get/update GPS-strip admin setting.
     *
     * GET returns current value; PUT updates it.
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     */
    public function gpsStripSetting(): JSONResponse
    {
        try {
            if ($this->request->getMethod() === 'PUT') {
                $data    = $this->request->getParams();
                $enabled = filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $this->photoService->setGpsStripEnabled(enabled: $enabled);
            }

            return new JSONResponse(data: ['stripGps' => $this->photoService->isGpsStripEnabled()]);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end gpsStripSetting()
}//end class

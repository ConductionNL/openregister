<?php
/**
 * Class RevertController
 *
 * Controller for managing object reversion operations in the OpenRegister app.
 * Provides functionality to revert objects to previous states based on different criteria.
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

use OCA\OpenRegister\Service\RevertService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\LockedException;

/**
 * Class RevertController
 * Handles all object reversion operations
 */
class RevertController extends Controller
{


    /**
     * Constructor for RevertController
     *
     * @param string        $appName       The name of the app
     * @param IRequest      $request       The request object
     * @param RevertService $revertService The revert service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RevertService $revertService
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Revert an object to a previous state
     *
     * This endpoint allows reverting an object to a previous state based on different criteria:
     * 1. DateTime - Revert to the state at a specific point in time
     * 2. Audit Trail ID - Revert to the state after a specific audit trail entry
     * 3. Semantic Version - Revert to a specific version of the object
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     * @param string $id       The object ID
     *
     * @return JSONResponse A JSON response containing the reverted object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|403|404|423|500, array<array-key, mixed>, array<never, never>>
     */
    public function revert(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Parse the revert point.
            $until = null;
            if (isset($data['datetime']) === true) {
                $until = new \DateTime($data['datetime']);
            } else if (isset($data['auditTrailId']) === true) {
                $until = $data['auditTrailId'];
            } else if (isset($data['version']) === true) {
                $until = $data['version'];
            }

            if ($until === null) {
                return new JSONResponse(
                    data: ['error' => 'Must specify either datetime, auditTrailId, or version'],
                    statusCode: 400
                );
            }

            // Determine if we should overwrite the version.
            $overwriteVersion = $data['overwriteVersion'] ?? false;

            // Revert the object.
            $revertedObject = $this->revertService->revert(
                register: $register,
                schema: $schema,
                id: $id,
                until: $until,
                overwriteVersion: $overwriteVersion
            );

            return new JSONResponse(data: $revertedObject->jsonSerialize());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 403);
        } catch (LockedException $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 423);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try

    }//end revert()


}//end class

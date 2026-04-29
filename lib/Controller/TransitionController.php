<?php

/**
 * OpenRegister TransitionController
 *
 * Sugar HTTP entry point over TransitionEngine. Apps that adopt the
 * x-openregister-lifecycle annotation no longer need to write their
 * own action endpoint per schema.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

class TransitionController extends Controller
{

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TransitionEngine $engine
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Apply a named transition to an object.
     *
     * The action name is taken from the request body (`action` key), so
     * the same endpoint covers every transition declared on the schema —
     * apps don't need a route per action.
     *
     * @param string $id Object id/uuid/slug.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function transition(string $id): JSONResponse
    {
        $action = (string) ($this->request->getParam('action') ?? '');
        if ($action === '') {
            return new JSONResponse(
                ['error' => 'Missing required field "action".'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $object = $this->engine->transition(objectId: $id, action: $action);
        } catch (RuntimeException $e) {
            // Engine throws on missing object/schema/transition or
            // disallowed-from-current-state. The validator listener
            // surfaces guard denials by stopping propagation, which the
            // ObjectService will translate into its own exception.
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse($object->jsonSerialize());
    }//end transition()

    /**
     * List actions allowed from the object's current state.
     *
     * @param string $id Object id/uuid/slug.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function availableActions(string $id): JSONResponse
    {
        try {
            $actions = $this->engine->availableActions(objectId: $id);
        } catch (RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(['actions' => $actions]);
    }//end availableActions()

}//end class

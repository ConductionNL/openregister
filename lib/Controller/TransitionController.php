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

use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

class TransitionController extends Controller
{
    /**
     * Constructor.
     *
     * @param string           $appName The application name.
     * @param IRequest         $request The current request.
     * @param TransitionEngine $engine  The transition engine.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TransitionEngine $engine
    ) {
        parent::__construct(appName: $appName, request: $request);
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
     * @return JSONResponse JSON response with the transitioned object or an error.
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
        } catch (NotAuthorizedException $e) {
            // Caller lacks `update` permission on the object. Surface
            // as 403 so clients can distinguish "not allowed" from
            // "transition refused" (422) and "object missing" (404).
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_FORBIDDEN
            );
        } catch (HookStoppedException $e) {
            // The validator listener (or another listener) rejected the
            // save by calling stopPropagation(). Surface its message as a
            // structured 422 instead of a 500 stack trace.
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (RuntimeException $e) {
            // Engine throws on missing object/schema/transition or
            // disallowed-from-current-state.
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }//end try

        return new JSONResponse($object->jsonSerialize());
    }//end transition()

    /**
     * List actions allowed from the object's current state.
     *
     * @param string $id Object id/uuid/slug.
     *
     * @return JSONResponse JSON response with the list of allowed actions.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function availableActions(string $id): JSONResponse
    {
        try {
            $actions = $this->engine->availableActions(objectId: $id);
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_FORBIDDEN
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(['actions' => $actions]);
    }//end availableActions()
}//end class

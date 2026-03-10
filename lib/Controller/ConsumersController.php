<?php

/**
 * ConsumersController handles REST API endpoints for consumer management.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Provides REST API endpoints for managing API consumers.
 *
 * @package OCA\OpenRegister\Controller
 */
class ConsumersController extends Controller
{
    /**
     * Constructor.
     *
     * @param string         $appName        The application name
     * @param IRequest       $request        The request object
     * @param ConsumerMapper $consumerMapper The consumer database mapper
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ConsumerMapper $consumerMapper,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * List all consumers.
     *
     * @return JSONResponse The list of consumers
     *
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        $consumers = $this->consumerMapper->findAll();

        return new JSONResponse(
            [
                'results' => $consumers,
                'total'   => $this->consumerMapper->getTotalCallCount(),
            ]
        );

    }//end index()

    /**
     * Get a single consumer.
     *
     * @param int $id The consumer ID
     *
     * @return JSONResponse The consumer data or error
     *
     * @NoCSRFRequired
     */
    public function show(int $id): JSONResponse
    {
        try {
            $consumer = $this->consumerMapper->find($id);
            return new JSONResponse($consumer);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Consumer not found'], 404);
        }

    }//end show()

    /**
     * Create a new consumer.
     *
     * @return JSONResponse The created consumer
     *
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();

        // Remove framework-injected params.
        unset($data['_route']);

        $data['created'] = new DateTime();
        $data['updated'] = new DateTime();

        $consumer = $this->consumerMapper->createFromArray($data);

        return new JSONResponse($consumer, 201);

    }//end create()

    /**
     * Update a consumer.
     *
     * @param int $id The consumer ID
     *
     * @return JSONResponse The updated consumer or error
     *
     * @NoCSRFRequired
     */
    public function update(int $id): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            unset($data['_route'], $data['id']);

            $data['updated'] = new DateTime();

            $consumer = $this->consumerMapper->updateFromArray($id, $data);

            return new JSONResponse($consumer);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Consumer not found'], 404);
        }

    }//end update()

    /**
     * Delete a consumer.
     *
     * @param int $id The consumer ID
     *
     * @return JSONResponse Empty response or error
     *
     * @NoCSRFRequired
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $consumer = $this->consumerMapper->find($id);
            $this->consumerMapper->delete($consumer);

            return new JSONResponse([]);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Consumer not found'], 404);
        }

    }//end destroy()

    /**
     * Partially update a consumer.
     *
     * @param int $id The consumer ID
     *
     * @return JSONResponse The updated consumer or error
     *
     * @NoCSRFRequired
     */
    public function patch(int $id): JSONResponse
    {
        return $this->update(id: $id);

    }//end patch()
}//end class

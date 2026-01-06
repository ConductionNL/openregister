<?php

/**
 * MappingsController handles REST API endpoints for mapping management
 *
 * Controller for managing mapping operations in the OpenRegister app.
 * Provides endpoints for CRUD operations on mappings used for data transformation.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * MappingsController handles REST API endpoints for mapping management
 *
 * Provides REST API endpoints for managing mappings including CRUD operations
 * and testing mappings with sample data.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class MappingsController extends Controller
{
    /**
     * Constructor
     *
     * Initializes controller with required dependencies for mapping operations.
     *
     * @param string              $appName             Application name
     * @param IRequest            $request             HTTP request object
     * @param IAppConfig          $config              App configuration
     * @param MappingMapper       $mappingMapper       Mapping mapper for database operations
     * @param MappingService      $mappingService      Mapping service for executing mappings
     * @param OrganisationService $organisationService Organisation service for multi-tenancy
     * @param LoggerInterface     $logger              Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly MappingMapper $mappingMapper,
        private readonly MappingService $mappingService,
        private readonly OrganisationService $organisationService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Retrieves a list of all mappings
     *
     * Returns a JSON response containing an array of all mappings.
     * Supports pagination and filtering.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with array of mappings
     */
    public function index(): JSONResponse
    {
        // Get request parameters.
        $params = $this->request->getParams();

        // Extract pagination parameters.
        $limit = null;
        if (isset($params['_limit']) === true) {
            $limit = (int) $params['_limit'];
        }

        $offset = null;
        if (isset($params['_offset']) === true) {
            $offset = (int) $params['_offset'];
        }

        $page = null;
        if (isset($params['_page']) === true) {
            $page = (int) $params['_page'];
        }

        // Convert page to offset if provided.
        if ($page !== null && $limit !== null) {
            $offset = ($page - 1) * $limit;
        }

        // Retrieve mappings using mapper.
        $mappings = $this->mappingMapper->findAll(
            limit: $limit,
            offset: $offset
        );

        // Serialize mappings to arrays.
        $mappingsArr = array_map(
            function ($mapping) {
                return $mapping->jsonSerialize();
            },
            $mappings
        );

        return new JSONResponse(data: ['results' => $mappingsArr]);
    }//end index()

    /**
     * Retrieves a single mapping by ID
     *
     * @param int|string $id The ID, UUID, or slug of the mapping
     *
     * @return JSONResponse JSON response with mapping data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show(int|string $id): JSONResponse
    {
        try {
            $mapping = $this->mappingMapper->find(id: $id);
            return new JSONResponse(data: $mapping->jsonSerialize());
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => 'Mapping not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end show()

    /**
     * Creates a new mapping
     *
     * Creates a new mapping based on POST data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with created mapping
     */
    public function create(): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to ensure a new record is created.
        if (isset($data['id']) === true) {
            unset($data['id']);
        }

        try {
            // Create a new mapping from the data.
            $mapping = $this->mappingMapper->createFromArray(data: $data);

            return new JSONResponse(data: $mapping->jsonSerialize(), statusCode: 201);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Mapping creation failed',
                context: [
                    'error_message' => $e->getMessage(),
                    'error_code'    => $e->getCode(),
                ]
            );

            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end create()

    /**
     * Updates an existing mapping
     *
     * Updates a mapping based on its ID.
     *
     * @param int $id The ID of the mapping to update
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated mapping
     */
    public function update(int $id): JSONResponse
    {
        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove immutable fields.
        unset($data['id']);
        unset($data['organisation']);
        unset($data['created']);

        try {
            // Update the mapping with the provided data.
            $updatedMapping = $this->mappingMapper->updateFromArray(id: $id, data: $data);

            return new JSONResponse(data: $updatedMapping->jsonSerialize());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Mapping not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Mapping update failed',
                context: [
                    'mapping_id'    => $id,
                    'error_message' => $e->getMessage(),
                    'error_code'    => $e->getCode(),
                ]
            );

            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end update()

    /**
     * Deletes a mapping
     *
     * Deletes a mapping based on its ID.
     *
     * @param int $id The ID of the mapping to delete
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Empty JSON response on success
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $mapping = $this->mappingMapper->find(id: $id);
            $this->mappingMapper->delete(entity: $mapping);

            return new JSONResponse(data: []);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Mapping not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end destroy()

    /**
     * Tests a mapping with provided input data
     *
     * Tests a mapping configuration with sample input data to verify
     * the mapping produces expected output.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with test results
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function test(): JSONResponse
    {
        // Get all parameters from the request.
        $data = $this->request->getParams();

        // Validate required parameters.
        if (isset($data['inputObject']) === false || isset($data['mapping']) === false) {
            return new JSONResponse(
                data: ['error' => 'Both `inputObject` and `mapping` are required'],
                statusCode: 400
            );
        }

        // Get input object and mapping configuration.
        $inputObject   = $data['inputObject'];
        $mappingConfig = $data['mapping'];

        // Create a new Mapping object and hydrate it with the provided mapping.
        $mappingObject = new Mapping();
        $mappingObject->hydrate(object: $mappingConfig);

        try {
            // Perform the mapping operation.
            $resultObject = $this->mappingService->executeMapping(
                mapping: $mappingObject,
                input: $inputObject
            );

            // Return the result.
            return new JSONResponse(
                data: [
                    'resultObject' => $resultObject,
                    'success'      => true,
                ]
            );
        } catch (Exception $e) {
            // If mapping fails, return an error response.
            return new JSONResponse(
                data: [
                    'error'   => 'Mapping error',
                    'message' => $e->getMessage(),
                ],
                statusCode: 400
            );
        }//end try
    }//end test()
}//end class

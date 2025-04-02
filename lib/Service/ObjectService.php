<?php

/**
 * OpenRegister ObjectService.
 *
 * Service class for handling object operations in the OpenRegister application.
 *
 * This service provides methods for:
 * - CRUD operations on objects
 * - Schema resolution and validation
 * - Managing relations and linked data (extending objects with related sub-objects)
 * - Audit trails and data aggregation
 *
 * @category Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @see https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Adbar\Dot;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\File;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Formats\BsnFormat;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Opis\Uri\Uri;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class ObjectService
{
    /**
     * @var int The current register ID
     */
private int $register;

    /**
     * @var int The current schema ID
     */
private int $schema;

    /**
     * @var Environment Twig environment for template rendering
     */
private Environment $twig;

    /**
     * @var \OCP\EventDispatcher\IEventDispatcher Event dispatcher for sending events
     */
private \OCP\EventDispatcher\IEventDispatcher $eventDispatcher;

    /**
     * @var string Validation error message constant
     */
public const VALIDATION_ERROR_MESSAGE = 'Invalid object';


    /**
     * Constructor for ObjectService.
     *
     * Initializes the service with dependencies required for database and object operations.
     *
     * @param ObjectEntityMapper                    $objectEntityMapper object entity data mapper
     * @param RegisterMapper                        $registerMapper     register data mapper
     * @param SchemaMapper                          $schemaMapper       schema data mapper
     * @param AuditTrailMapper                      $auditTrailMapper   audit trail data mapper
     * @param ContainerInterface                    $container          dependency injection container
     * @param IURLGenerator                         $urlGenerator       URL generator service
     * @param FileService                           $fileService        file service for managing files
     * @param IAppManager                           $appManager         application manager service
     * @param IAppConfig                            $config             configuration manager
     * @param FileMapper                            $fileMapper         file mapper for database operations
     * @param IUserSession                          $userSession        user session service
     * @param ArrayLoader                           $loader             twig array loader for templates
     * @param \OCP\EventDispatcher\IEventDispatcher $eventDispatcher    event dispatcher service
     */
public function __construct(
    private readonly ObjectEntityMapper $objectEntityMapper,
    private readonly RegisterMapper $registerMapper,
    private readonly SchemaMapper $schemaMapper,
    private readonly AuditTrailMapper $auditTrailMapper,
    private readonly ContainerInterface $container,
    private readonly IURLGenerator $urlGenerator,
    private readonly FileService $fileService,
    private readonly IAppManager $appManager,
    private readonly IAppConfig $config,
    private readonly FileMapper $fileMapper,
    private readonly IUserSession $userSession,
    ArrayLoader $loader,
    \OCP\EventDispatcher\IEventDispatcher $eventDispatcher,
) {
    $this->twig            = new Environment($loader);
    $this->eventDispatcher = $eventDispatcher;

}//end __construct()


    /**
     * Retrieves the OpenConnector service from the container.
     *
     * @param string $filePath optional file path for the OpenConnector service
     *
     * @return mixed|null the OpenConnector service instance or null if not available
     *
     * @throws ContainerExceptionInterface if there is a container exception
     * @throws NotFoundExceptionInterface  if the service is not found
     */
public function getOpenConnector(string $filePath='\Service\ObjectService'): mixed
{
    if (in_array('openconnector', $this->appManager->getInstalledApps()) === true) {
        try {
            return $this->container->get("OCA\OpenConnector$filePath");
        } catch (\Exception $e) {
            return null;
        }
    }

    return null;

}//end getOpenConnector()


    /**
     * Resolves a schema from a given URI.
     *
     * @param Uri $uri the URI pointing to the schema
     *
     * @return string the schema content in JSON format
     *
     * @throws GuzzleException if there is an error during schema fetching
     */
public function resolveSchema(Uri $uri): string
{
    // Local schema resolution.
    if (
        $this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
        && str_contains($uri->path(), '/api/schemas')
    ) {
        $exploded = explode('/', $uri->path());
        $schema   = $this->schemaMapper->find(end($exploded));

        return json_encode($schema->getSchemaObject($this->urlGenerator));
    }

    // File schema resolution.
    if (
        $this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
        && str_contains($uri->path(), '/api/files/schema')
    ) {
        return File::getSchema($this->urlGenerator);
    }

    // External schema resolution.
    if ($this->config->getValueBool('openregister', 'allowExternalSchemas') === true) {
        $client = new Client();
        $result = $client->get(\GuzzleHttp\Psr7\Uri::fromParts($uri->components()));

        return $result->getBody()->getContents();
    }

    return '';

}//end resolveSchema()


    /**
     * Validates an object against a schema.
     *
     * @param array   $object       the object to validate
     * @param integer $schemaId     the schema ID to validate against
     * @param object  $schemaObject a custom schema object for validation
     *
     * @return ValidationResult the result of the validation
     */
public function validateObject(array $object, ?int $schemaId=null, object $schemaObject=new \stdClass()): ValidationResult
{
    if ($schemaObject === new \stdClass() || $schemaId !== null) {
        $schemaObject = $this->schemaMapper->find($schemaId)->getSchemaObject($this->urlGenerator);
    }

    // If there are no properties we don't have to validate.
    if (!isset($schemaObject->properties) || empty($schemaObject->properties) === true) {
        // Return a default ValidationResult indicating success.
        return new ValidationResult(null);
    }

    $validator = new Validator();
    $validator->setMaxErrors(100);
    $validator->parser()->getFormatResolver()->register('string', 'bsn', new BsnFormat());
    $validator->loader()->resolver()->registerProtocol('http', [$this, 'resolveSchema']);

    return $validator->validate(json_decode(json_encode($object)), $schemaObject);

}//end validateObject()


    /**
     * Finds an object by ID or UUID.
     *
     * @param integer|string $id     The object ID or UUID
     * @param array|null     $extend Properties to extend the object with
     * @param boolean        $files  Whether to include files in the response
     *
     * @return ObjectEntity|null The found object or null if not found
     *
     * @throws \Exception If the object is not found
     */
public function find(int | string $id, ?array $extend=[], bool $files=false): ?ObjectEntity
{
    return $this->getObject(
        $this->registerMapper->find($this->getRegister()),
        $this->schemaMapper->find($this->getSchema()),
        $id,
        $extend,
        files: $files
    );

}//end find()


    /**
     * Finds an object by UUID.
     *
     * @param string $uuid The object UUID
     *
     * @return ObjectEntity|null The found object or null if not found
     *
     * @throws \Exception If the object is not found
     */
public function findByUuid(string $uuid): ?ObjectEntity
{
    return $this->objectEntityMapper->findByUuidOnly(uuid: $uuid);

}//end findByUuid()


    /**
     * Creates a new object from provided data.
     *
     * @param array      $object The object data
     * @param array|null $extend Properties to extend the object with
     *
     * @return array The created object as an array
     *
     * @throws ValidationException       If validation fails
     * @throws CustomValidationException If custom validation fails
     * @throws GuzzleException           If there is an error during file upload
     *
     * @psalm-return array<string, mixed>
     *
     * @phpstan-return array<string, mixed>
     */
public function createFromArray(array $object, ?array $extend=[]): array
{
    $objectEntity = $this->saveObject(
        register: $this->getRegister(),
        schema: $this->getSchema(),
        object: $object
    );

    // Lets turn the whole thing into an array.
    $objectEntity = $objectEntity->jsonSerialize();

    // Extend object with properties if requested.
    if (empty($extend) === false) {
        $objectEntity = $this->renderEntity(entity: $objectEntity, extend: $extend);
    }

    return $objectEntity;

}//end createFromArray()


    /**
     * Updates an existing object with new data.
     *
     * @param string|integer $id            The object ID to update
     * @param array          $object        The new object data
     * @param boolean        $updateVersion Whether this is an update operation
     * @param boolean        $patch         Whether this is a patch operation
     * @param array|null     $extend        Properties to extend with related data
     *
     * @return array The updated object as an array
     *
     * @throws ValidationException       If validation fails
     * @throws CustomValidationException If custom validation fails
     * @throws GuzzleException           If there is an error during file upload
     * @throws \Exception                If the schema is not found
     *
     * @psalm-return array<string, mixed>
     *
     * @phpstan-return array<string, mixed>
     */
public function updateFromArray(string | int $id, array $object, bool $updateVersion, bool $patch=false, ?array $extend=[]): array
{
    $object['id'] = $id;

    $schema = $this->schemaMapper->find($this->getSchema());
    if ($schema === null) {
        throw new \Exception('Schema not found');
    }

    $properties = $schema->getProperties();

    $errors = [];
    foreach ($properties as $propertyName => $propertyConfig) {
        // Validate immutable.
        if (isset($propertyConfig['immutable']) === true && $propertyConfig['immutable'] === true && isset($object[$propertyName]) === true) {
            $errors[sprintf('/%s', $propertyName)][] = sprintf('%s is immutable and may not be overwritten', $propertyName);
        }
    }

    if (empty($errors) === false) {
        throw new CustomValidationException(message: $this::VALIDATION_ERROR_MESSAGE, errors: $errors);
    }

    if ($patch === true) {
        $oldObject = $this->getObject(
            $this->registerMapper->find($this->getRegister()),
            $this->schemaMapper->find($this->getSchema()),
            $id
        )->jsonSerialize();

        $object = array_merge($oldObject, $object);
    }

    $objectEntity = $this->saveObject(
        register: $this->getRegister(),
        schema: $this->getSchema(),
        object: $object,
    );

    // Lets turn the whole thing into an array.
    $objectEntity = $objectEntity->jsonSerialize();

    // Extend object with properties if requested.
    if (empty($extend) === false) {
        $objectEntity = $this->renderEntity(entity: $objectEntity, extend: $extend);
    }

    return $objectEntity;

}//end updateFromArray()


    /**
     * Deletes an object.
     *
     * @param array|\JsonSerializable $object The object to delete
     *
     * @return boolean True if deletion is successful, false otherwise
     *
     * @throws \Exception If deletion fails
     */
public function delete(array | \JsonSerializable $object): bool
{
    if ($object instanceof \JsonSerializable) {
        $object = $object->jsonSerialize();
    }

    return $this->deleteObject(
        register: $this->getRegister(),
        schema: $this->getSchema(),
        uuid: $object['id']
    );

}//end delete()


    /**
     * Retrieves all objects matching criteria.
     *
     * @param integer|null $limit   maximum number of results
     * @param integer|null $offset  starting offset for pagination
     * @param array        $filters criteria to filter the objects
     * @param array        $sort    sorting options
     * @param string|null  $search  search term
     * @param array|null   $extend  properties to extend the results with
     * @param boolean      $files   whether to include files in the response
     * @param string|null  $uses    value that must be present in relations
     *
     * @return array list of matching objects
     *
     * @throws \Exception if a database error occurs
     *
     * @psalm-return array<int, mixed>
     *
     * @phpstan-return array<int, mixed>
     */
public function findAll(
    ?int $limit=null,
    ?int $offset=null,
    array $filters=[],
    array $sort=[],
    ?string $search=null,
    ?array $extend=[],
    bool $files=false,
    ?string $uses=null,
): array {
    $objects = $this->getObjects(
        register: $this->getRegister(),
        schema: $this->getSchema(),
        limit: $limit,
        offset: $offset,
        filters: $filters,
        sort: $sort,
        search: $search,
        files: $files,
        uses: $uses
    );

    // If extend is provided, extend each object.
    if (empty($extend) === false) {
        $objects = array_map(
            function ($object) use ($extend) {
                // Convert object to array if needed.
                $objectArray = is_array($object) ? $object : $object->jsonSerialize();

                return $this->renderEntity(entity: $objectArray, extend: $extend);
            },
            $objects
        );
    }

    return $objects;

}//end findAll()


    /**
     * Counts the total number of objects matching criteria.
     *
     * @param array       $filters Criteria to filter the objects
     * @param string|null $search  Search term
     *
     * @return integer The total count of matching objects
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
public function count(array $filters=[], ?string $search=null): int
{
    // Add register and schema filters if set.
    if ($this->getSchema() !== null && $this->getRegister() !== null) {
        $filters['register'] = $this->getRegister();
        $filters['schema']   = $this->getSchema();
    }

    return $this->objectEntityMapper
        ->countAll(filters: $filters, search: $search);

}//end count()


    /**
     * Retrieves multiple objects by their IDs.
     *
     * @param array $ids List of object IDs to retrieve
     *
     * @return array List of retrieved objects
     *
     * @throws \Exception If an error occurs during retrieval
     *
     * @psalm-return array<int, ObjectEntity>
     *
     * @phpstan-return array<int, ObjectEntity>
     */
public function findMultiple(array $ids): array
{
    $result = [];
    foreach ($ids as $id) {
        if (is_string($id) === true || is_int($id) === true) {
            $result[] = $this->find($id);
        }
    }

    return $result;

}//end findMultiple()


    /**
     * Find subobjects for a certain property with given ids.
     *
     * @param array  $ids      The IDs to fetch the subobjects for
     * @param string $property the property in which the objects reside
     *
     * @return array the resulting subobjects
     */
public function findSubObjects(array $ids, string $property): array
{
    $schemaObject = $this->schemaMapper->find($this->schema);

    $properties = $schemaObject->getProperties();
    if (empty($properties) === true) {
        return [];
    }

    $property = $properties[$property];
    if ($property === null) {
        return [];
    }

    if (isset($property['items']) === true) {
        $ref = explode('/', $property['items']['$ref']);
    } else {
        $subSchema = explode('/', $property['$ref']);
    }

    $subSchema = end($ref);

    $subSchemaMapper = $this->getMapper(register: $this->getRegister(), schema: $subSchema);

    return $subSchemaMapper->findMultiple($ids);

}//end findSubObjects()


    /**
     * Get aggregations for objects matching filters.
     *
     * @param array       $filters Filter criteria
     * @param string|null $search  Search term
     *
     * @return array aggregated data results
     */
public function getAggregations(array $filters, ?string $search=null): array
{
    $mapper = $this->getMapper(objectType: 'objectEntity');

    $filters['register'] = $this->getRegister();
    $filters['schema']   = $this->getSchema();

    if ($mapper instanceof ObjectEntityMapper) {
        return $mapper->getFacets($filters, $search);
    }

    return [];

}//end getAggregations()


    /**
     * Extracts object data from an entity.
     *
     * @param mixed      $object the object entity
     * @param array|null $extend properties to extend the object data with
     *
     * @return mixed the extracted object data
     */
private function getDataFromObject(mixed $object, ?array $extend=[]): mixed
{
    return $object->getObject();

}//end getDataFromObject()


    /**
     * Find all objects conforming to the request parameters, surrounded with pagination data.
     *
     * @param array $requestParams the request parameters to search with
     *
     * @return array the result including pagination data
     */
public function findAllPaginated(array $requestParams): array
{
    // Extract specific parameters.
    $limit  = $requestParams['limit'] ?? $requestParams['_limit'] ?? null;
    $offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? null;
    $order  = $requestParams['order'] ?? $requestParams['_order'] ?? [];
    $extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
    $page   = $requestParams['page'] ?? $requestParams['_page'] ?? null;
    $search = $requestParams['_search'] ?? null;

    if ($page !== null && isset($limit)) {
        $page   = (int) $page;
        $offset = $limit * ($page - 1);
    }

    // Ensure order and extend are arrays.
    if (is_string($order) === true) {
        $order = array_map('trim', explode(',', $order));
    }

    if (is_string($extend) === true) {
        $extend = array_map('trim', explode(',', $extend));
    }

    // Remove unnecessary parameters from filters.
    $filters = $requestParams;
    unset($filters['_route']);
// TODO: Investigate why this is here and if it's needed.
    unset($filters['_extend'], $filters['_limit'], $filters['_offset'], $filters['_order'], $filters['_page'], $filters['_search']);

    unset($filters['extend'], $filters['limit'], $filters['offset'], $filters['order'], $filters['page']);

    $objects = $this->findAll(limit: $limit, offset: $offset, filters: $filters, sort: $order, search: $search, extend: $extend);
    $total   = $this->count($filters);
    $pages   = $limit !== null ? ceil($total / $limit) : 1;

    $facets = $this->getAggregations(
        filters: $filters,
        search: $search
    );

    return [
        'results' => $objects,
        'facets'  => $facets,
        'total'   => $total,
        'page'    => $page ?? 1,
        'pages'   => $pages,
    ];

}//end findAllPaginated()


    /**
     * Gets all objects of a specific type.
     *
     * @param string|null  $objectType The type of objects to retrieve. Defaults to 'objectEntity' if register and schema are provided.
     * @param integer|null $register   the ID of the register to filter objects by
     * @param integer|null $schema     the ID of the schema to filter objects by
     * @param integer|null $limit      The maximum number of objects to retrieve. Null for no limit.
     * @param integer|null $offset     The offset for pagination. Null for no offset.
     * @param array        $filters    additional filters for retrieving objects
     * @param array        $sort       sorting criteria for the retrieved objects
     * @param string|null  $search     search term for filtering objects
     *
     * @return array an array of objects matching the specified criteria
     *
     * @throws \InvalidArgumentException if an invalid object type is specified
     */
public function getObjects(
    ?string $objectType=null,
    ?int $register=null,
    ?int $schema=null,
    ?int $limit=null,
    ?int $offset=null,
    array $filters=[],
    array $sort=[],
    ?string $search=null,
    bool $files=true,
    ?string $uses=null,
) {
    // Set object type and filters if register and schema are provided.
    if ($objectType === null && $register !== null && $schema !== null) {
        $objectType          = 'objectEntity';
        $filters['register'] = $register;
        $filters['schema']   = $schema;
    }

    $mapper = $this->getMapper($objectType);

    // Use the mapper to find and return all objects of the specified type.
    $objects = $mapper->findAll(
        limit: $limit,
        offset: $offset,
        filters: $filters,
        sort: $sort,
        search: $search,
        uses: $uses
    );

    if ($files === false) {
        return $objects;
    }

    $objects = array_map(
        function ($object) {
            $files = $this->getFiles($object);

            return $this->hydrateFiles($object, $files);
        },
        $objects
    );

    return $objects;

}//end getObjects()


    /**
     * Validate custom rules on the object.
     *
     * @param array  $object The data of the object
     * @param Schema $schema The schema of the object
     *
     * @throws CustomValidationException If the object fails custom validation
     */
private function validateCustomRules(array $object, Schema $schema): void
{
    $properties = $schema->getProperties();
    if ($properties === null || empty($properties) === true) {
        return;
    }

    $errors = [];
    foreach ($properties as $propertyName => $propertyConfig) {
        // @todo do something for object properties because the validator will always expect an object instead of uri (string) or id.
    }

    if (empty($errors) === false) {
        throw new CustomValidationException(message: $this::VALIDATION_ERROR_MESSAGE, errors: $errors);
    }

}//end validateCustomRules()


    /**
     * Handles the exception and creates a JSONResponse for errors.
     *
     * @param ValidationException|CustomValidationException $exception The exception to handle
     *
     * @return JSONResponse A response with error messages
     */
public function handleValidationException(ValidationException | CustomValidationException $exception): JSONResponse
{
    $formatter = new ErrorFormatter();
    switch (get_class($exception)) {
        case 'OCA\OpenRegister\Exception\ValidationException':
            $validationErrors = $formatter->format($exception->getErrors());
            break;
        case 'OCA\OpenRegister\Exception\CustomValidationException':
        default:
            $validationErrors = $exception->getErrors();
            break;
    }

    return new JSONResponse(['message' => $exception->getMessage(), 'validationErrors' => $validationErrors], 400);

}//end handleValidationException()


    /**
     * Saves an object to the database.
     *
     * @param integer|string|Register $register The ID, UUID, or object of the register to save the object to
     * @param integer|string|Schema   $schema   The ID, UUID, or object of the schema to save the object to
     * @param array                   $object   The data of the object to save
     * @param integer|null            $depth    The maximum depth for processing nested objects
     *
     * @return ObjectEntity The saved object entity
     *
     * @throws ValidationException        If the object fails validation
     * @throws \Exception|GuzzleException If an error occurs during object saving or file handling
     * @throws CustomValidationException  If the object fails custom validation
     */
public function saveObject(int | string | Register $register, int | string | Schema $schema, array $object, ?int $depth=null): ObjectEntity
{
    // Remove system properties (starting with _).
    $object = array_filter(
        $object,
        function ($key) {
            return !str_starts_with($key, '_');
        },
        ARRAY_FILTER_USE_KEY
    );

    // Convert register to its respective object if it is a string or int.
    if (!$register instanceof Register) {
        $register = $this->registerMapper->find($register);
        if ($register === null) {
            throw new \Exception('Register not found');
        }
    }

    // Convert schema to its respective object if it is a string or int.
    if (!$schema instanceof Schema) {
        $schema = $this->schemaMapper->find($schema);
        if ($schema === null) {
            throw new \Exception('Schema not found');
        }
    }

    if ($depth === null) {
        $depth = $schema->getMaxDepth();
    }

    if (isset($object['id']) === true) {
        $objectEntity = $this->objectEntityMapper->find(
            $object['id']
        );
    } else {
        $objectEntity = new ObjectEntity();
        $objectEntity->setRegister($register->getId());
        $objectEntity->setSchema($schema->getId());
    }

    // Store old version for audit trail.
    $oldObject = clone $objectEntity;

    $this->validateCustomRules(object: $object, schema: $schema);

    $validationResult = $this->validateObject(object: $object, schemaId: $schema->getId());

    if ($validationResult->isValid() === false) {
        $objectEntity->setValidation($validationResult->error());
        // Store validation errors but don't throw exception
    }

    // Set the UUID if it is not set.
    if ($objectEntity->getUuid() === null) {
        $objectEntity->setUuid(Uuid::v4());
    }

    // Set the owner to the current user if logged in.
    if ($objectEntity->getOwner() === null && $this->userSession->isLoggedIn()) {
        $objectEntity->setOwner($this->userSession->getUser()->getUID());
    }

    // Set the application to 'openregister' since this is our app.
    if ($objectEntity->getApplication() === null) {
        $objectEntity->setApplication('openregister');
    }

    // Set or update the version.
    if ($objectEntity->getVersion() === null) {
        $objectEntity->setVersion('1.0.0');
    } else {
        $version    = explode('.', $objectEntity->getVersion());
        $version[2] = (int) $version[2] + 1;
        $objectEntity->setVersion(implode('.', $version));
    }

    // Set dateCreated and dateModified.
    $currentDateTime = new \DateTime();
    if ($objectEntity->getCreated() === null) {
        $objectEntity->setCreated($currentDateTime);
    }

    // We always set the updated time to the current date and time.
    $objectEntity->setUpdated($currentDateTime);

    // Create the uri for the object.
    if ($objectEntity->getUri() === null) {
        // @todo: this needs to be fixed.
        // $objectEntity->setUri($this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('openregister.Objects.show', ['id' => $objectEntity->getUuid(), 'register' => $register->getSlug(), 'schema' => $schema->getSlug()])));
    }

    // Make sure we create a folder in NC for this object if it doesn't already have one.
    if ($objectEntity->getFolder() === null) {
        $this->fileService->createObjectFolder($objectEntity);
    }

    // For backwards compatibility with the old url structure we need to check if the registers and schema have a slug and create one if not.
    if ($register->getSlug() === null) {
        $this->registerMapper->update($register);
    }

    if ($schema->getSlug() === null) {
        $this->schemaMapper->update($schema);
    }

    $objectEntity->setObject($object);

    // Handle object properties that are either nested objects or files.
    if ($schema->getProperties() !== null && is_array($schema->getProperties()) === true) {
        $objectEntity = $this->handleObjectRelations($objectEntity, $object, $schema->getProperties(), $register->getId(), $schema->getId(), depth: $depth);
// @todo: register and schema are not needed here we should refactor and remove them.
    }

    // Let's grab any links that we can.
    $objectEntity = $this->handleLinkRelations($objectEntity, $object);

    $this->setDefaults($objectEntity);

    if ($objectEntity->getId() && ($schema->getHardValidation() === false || $validationResult->isValid() === true)) {
        $objectEntity = $this->objectEntityMapper->update($objectEntity);
        // Create audit trail for update.
        $this->auditTrailMapper->createAuditTrail(new: $objectEntity, old: $oldObject);
    } else if ($schema->getHardValidation() === false || $validationResult->isValid() === true) {
        $objectEntity = $this->objectEntityMapper->insert($objectEntity);
        // Create audit trail for creation.
        $this->auditTrailMapper->createAuditTrail(new: $objectEntity);
    }

    return $objectEntity;

}//end saveObject()


    /**
     * Efficiently processes link relations within an object using JSON path traversal.
     *
     * Identifies and maps all URLs or UUIDs to their corresponding relations using dot notation paths
     * for nested properties, excluding self-references of the object entity.
     *
     * @param ObjectEntity $objectEntity The object entity to analyze and update relations for
     *
     * @return ObjectEntity The updated object entity with new relations mapped
     */
private function handleLinkRelations(ObjectEntity $objectEntity): ObjectEntity
{
    $relations = $objectEntity->getRelations() ?? [];

    // Get object's own identifiers to skip self-references.
    $selfIdentifiers = [
        $objectEntity->getUri(),
        $objectEntity->getUuid(),
        $objectEntity->getId(),
    ];

    // Find property names that are objects (and thus, relations).
    $validRelationProperties = [];
    $schema = $this->schemaMapper->find($objectEntity->getSchema());
    foreach ($schema->getProperties() as $propertyName => $property) {
        if (
            isset($property['type']) === true && (
            $property['type'] === 'object'
            )
        ) {
            /// @todo something went wrong here
            $validRelationProperties[] = $propertyName;
        }
        if (
            isset($property['type']) === true && (
            'object' === $property['type']
            || ('array' === $property['type']) && true === isset($property['items']['type']) && 'object' === $property['items']['type']
            )
        ) {
            $validRelationProperties[] = $propertyName;
        }
    }

    // Function to recursively find links/UUIDs and build dot notation paths.
    $findRelations = function ($data, $path='') use (&$findRelations, &$relations, $selfIdentifiers, $validRelationProperties) {
        foreach ($data as $key => $value) {
            if (false === in_array($key, $validRelationProperties)) {
                continue;
            }

            $currentPath = $path ? "$path.$key" : $key;

            if (true === is_array($value)) {
                if (true === isset($value['@self']['id'])) {
                    $relations[$currentPath] = $value['@self']['id'];
                }

                if (true === isset($value[0]['@self']['id'])) {
                    foreach ($value as $key2 => $subObject) {
                        if (true === isset($subObject['@self']['id'])) {
                            $relations["$currentPath.$key2"] = $subObject['@self']['id'];
                        }
                    }
                }
            } else if (true === is_string($value)) {
                // Check for URLs and UUIDs.
                if (
                    (false !== filter_var($value, FILTER_VALIDATE_URL)
                        || true === Uuid::isValid($value))
                    && false === in_array($value, $selfIdentifiers, true)
                ) {
                    $relations[$currentPath] = $value;
                }
            }//end if
        }//end foreach
    };

        // Process the entire object structure.
        $findRelations($objectEntity->getObject());

        $objectEntity->setRelations($relations);

        return $objectEntity;

}//end handleLinkRelations()


    /**
     * Extracts and validates a UUID from a given string or URI.
     *
     * @param string $item         the item to validate (UUID or URL containing a UUID)
     * @param string $propertyName the property name for error messages
     *
     * @return string the validated UUID
     *
     * @throws CustomValidationException if the item is not a valid UUID
     */
private function getIdFromString(string $item, string $propertyName): string
{
    // Check if item is a valid UUID.
    if (true === Uuid::isValid($item)) {
        return $item;
    }

    // If item is a string but not a UUID, check if it is a URI.
    $lastSlashPos = false;
    if (false !== filter_var($item, FILTER_VALIDATE_URL)) {
        $lastSlashPos = strrpos($item, '/');
    }

    // Extract the ID from the URI and validate it as a UUID.
    if (false !== $lastSlashPos) {
        $id = substr($item, $lastSlashPos + 1);
        if (Uuid::isValid($id)) {
            return $id;
        }
    }

    $error = [sprintf('/%s', $propertyName) => sprintf('%s not found with given id or uri', $propertyName)];
    throw new CustomValidationException(message: self::VALIDATION_ERROR_MESSAGE, errors: [$error]);

}//end getIdFromString()


    /**
     * Gets schema id from a reference in a property.
     *
     * @return string schemaId
     *
     * @throws \Exception If no reference found
     */
private function getSchemaFromPropertyReference(array $property, string $propertyName, int $schema): string
{
    $reference = $property['$ref'] ?? $property['items']['$ref'] ?? null;
    if (null === $reference) {
        throw new \Exception(sprintf('Could not find a $ref for schema $d property %s', $schema, $propertyName));
    }

    if (true === is_numeric($reference)) {
        return $reference;
    }

    if (false !== filter_var(value: $reference, filter: FILTER_VALIDATE_URL)) {
        $parsedUrl    = parse_url($reference);
        $explodedPath = explode(separator: '/', string: $parsedUrl['path']);
        $pathEnd      = end($explodedPath);
        if (true === is_numeric($pathEnd)) {
            return $pathEnd;
        }
    }

    throw new \Exception(sprintf('Could not get schema from $ref %s for schema %d property %s', $reference, $schema, $propertyName));

}//end getSchemaFromPropertyReference()


    /**
     * Adds a nested subobject based on schema and property details and incorporates it into the main object.
     *
     * Handles $ref resolution for schema subtypes, stores relations, and replaces nested subobject
     * data with its reference URI or UUID.
     *
     * @param array        $property     the property schema details for the nested object
     * @param string       $propertyName the name of the property in the parent object
     * @param array|string $item         the nested subobject data to process
     * @param ObjectEntity $objectEntity the parent object entity to associate the nested subobject with
     * @param integer      $register     the register associated with the schema
     * @param integer      $schema       the schema identifier for the subobject
     * @param integer|null $index        optional index of the subobject if it resides in an array
     * @param integer      $depth        the depth level for nested object processing
     *
     * @return string|array the UUID of the nested subobject or the full object data
     *
     * @throws ValidationException|CustomValidationException|\Exception when schema or object validation fails
     * @throws GuzzleException                                          when HTTP request fails
     */
private function addObject(
    array $property,
    string $propertyName,
    array | string $item,
    ObjectEntity $objectEntity,
    int $register,
    int $schema,
    ?int $index=null,
    int $depth=0,
): string | array {
    $itemIsID = false;
    if (true === is_string($item)) {
        $item     = $this->getIdFromString($item, $propertyName);
        $itemIsID = true;
    }

    $subSchema = $this->getSchemaFromPropertyReference(property: $property, propertyName: $propertyName, schema: $schema);

    // Handle nested object in array.
    if (true === $itemIsID) {
        $nestedObject = $this->getObject(
            register: $this->registerMapper->find((int) $register),
            schema: $this->schemaMapper->find((int) $subSchema),
            uuid: $item
        );
    } else {
        $nestedObject = $this->saveObject(
            register: $register,
            schema: (int) $subSchema,
            object: $item,
            depth: $depth - 1
        );
    }

    if (null === $index) {
        // Store relation and replace with reference.
        $relations = $objectEntity->getRelations() ?? [];
        $relations[$propertyName] = $nestedObject->getUri();
        $objectEntity->setRelations($relations);
    } else {
        $relations = $objectEntity->getRelations() ?? [];
        $relations[$propertyName.'.'.$index] = $nestedObject->getUri();
        $objectEntity->setRelations($relations);
    }

    if (0 !== $depth) {
        return $nestedObject->jsonSerialize();
    }

    return $nestedObject->getUuid();

}//end addObject()


    /**
     * Processes an object property by delegating it to a subobject handling mechanism.
     *
     * @param array        $property     the schema definition for the object property
     * @param string       $propertyName the name of the object property
     * @param array|string $item         the data corresponding to the property in the parent object
     * @param ObjectEntity $objectEntity the object entity to link the processed data to
     * @param integer      $register     the register associated with the schema
     * @param integer      $schema       the schema identifier for the property
     * @param integer      $depth        the depth level for nested object processing
     *
     * @return string|array the updated property data, typically a reference UUID
     *
     * @throws ValidationException|CustomValidationException when schema or object validation fails
     * @throws GuzzleException                               when HTTP request fails
     */
private function handleObjectProperty(
    array $property,
    string $propertyName,
    array | string $item,
    ObjectEntity $objectEntity,
    int $register,
    int $schema,
    int $depth=0,
): string | array {
    return $this->addObject(
        property: $property,
        propertyName: $propertyName,
        item: $item,
        objectEntity: $objectEntity,
        register: $register,
        schema: $schema,
        depth: $depth
    );

}//end handleObjectProperty()


    /**
     * Handles array-type properties by processing each element based on its schema type.
     *
     * Supports nested objects, files, or oneOf schema types, delegating to specific handlers
     * for each element in the array.
     *
     * @param array        $property     the schema definition for the array property
     * @param string       $propertyName the name of the array property
     * @param array        $items        the elements of the array to process
     * @param ObjectEntity $objectEntity the object entity the data belongs to
     * @param integer      $register     the register associated with the schema
     * @param integer      $schema       the schema identifier for the array elements
     * @param integer      $depth        the depth level for nested object processing
     *
     * @return array the processed array with updated references or data
     *
     * @throws GuzzleException                               when HTTP request fails
     * @throws ValidationException|CustomValidationException when schema validation or file handling fails
     */
private function handleArrayProperty(
    array $property,
    string $propertyName,
    array $items,
    ObjectEntity $objectEntity,
    int $register,
    int $schema,
    int $depth=0,
): array {
    if (false === isset($property['items'])) {
        return $items;
    }

    if (true === isset($property['items']['oneOf'])) {
        foreach ($items as $index => $item) {
            $items[$index] = $this->handleOneOfProperty(
                property: $property['items']['oneOf'],
                propertyName: $propertyName,
                item: $item,
                objectEntity: $objectEntity,
                register: $register,
                schema: $schema,
                index: $index,
                depth: $depth
            );
        }

        return $items;
    }

    if (
        true === isset($property['items']['type']) && 'object' !== $property['items']['type']
        && 'file' !== $property['items']['type']
    ) {
        return $items;
    }

    if (true === isset($property['items']['type']) && 'file' === $property['items']['type']) {
        foreach ($items as $index => $item) {
            $items[$index] = $this->handleFileProperty(
                objectEntity: $objectEntity,
                object: [$propertyName => [$index => $item]],
                propertyName: $propertyName.'.'.$index,
                format: $item['format'] ?? null
            )[$propertyName];
        }

        return $items;
    }

    foreach ($items as $index => $item) {
        $items[$index] = $this->addObject(
            property: $property['items'],
            propertyName: $propertyName,
            item: $item,
            objectEntity: $objectEntity,
            register: $register,
            schema: $schema,
            index: $index,
            depth: $depth
        );
    }

    return $items;

}//end handleArrayProperty()


    /**
     * Processes properties defined as oneOf, selecting the appropriate schema option for the data.
     *
     * Handles various types of schemas, including files and references, to correctly process
     * and replace the input data with the resolved references or processed results.
     *
     * @param array        $property     the oneOf schema definition
     * @param string       $propertyName the name of the property in the parent object
     * @param mixed        $item         the data to process, either as a scalar or a nested array
     * @param ObjectEntity $objectEntity the object entity the data belongs to
     * @param integer      $register     the register ID associated with the schema
     * @param integer      $schema       the schema ID associated with the property
     * @param integer|null $index        optional index for array-based oneOf properties
     * @param integer      $depth        the depth level for nested object processing
     *
     * @return mixed the processed data, resolved to a reference or updated structure
     *
     * @throws GuzzleException     when HTTP request fails
     * @throws ValidationException when schema validation or file handling fails
     */
private function handleOneOfProperty(
    array $property,
    string $propertyName,
    $item,
    ObjectEntity $objectEntity,
    int $register,
    int $schema,
    ?int $index=null,
    int $depth=0,
) {
if (false === array_is_list($property)) {
    return $item;


}

        if (
            true === in_array(needle: 'file', haystack: array_column(array: $property, column_key: 'type'))
            && true === is_array($item)
            && null !== $index
        ) {
            $fileIndex = array_search(needle: 'file', haystack: array_column(array: $property, column_key: 'type'));

            return $this->handleFileProperty(
                objectEntity: $objectEntity,
                object: [$propertyName => [$index => $item]],
                propertyName: $propertyName,
                format: $property[$fileIndex]['format'] ?? null
            );
            }
            if (
                true === in_array(needle: 'file', haystack: array_column(array: $property, column_key: 'type'))
                && true === is_array($item)
                && null === $index
            ) {
            $fileIndex = array_search(needle: 'file', haystack: array_column(array: $property, column_key: 'type'));

            return $this->handleFileProperty(
                objectEntity: $objectEntity,
                object: [$propertyName => $item],
                propertyName: $propertyName,
                format: $property[$fileIndex]['format'] ?? null
            );
            }

            if (
                [] === array_column(
                    array:
                    $property, column_key: '$ref'
                )
            )                           {
                return $item;
                }

        if (false === is_array($item)) {
            return $item;
        }

                $oneOf = array_filter(
                    array: $property,
                    callback: function (array $option) {
                    return true === isset($option['$ref']);
                    }
                )[0];

                return $this->addObject(
                    property: $oneOf,
                    propertyName: $propertyName,
                    item: $item,
                    objectEntity: $objectEntity,
                    register: $register,
                    schema: $schema,
                    index: $index,
                    depth: $depth
                );
            }//end if


            /**
             * Processes and rewrites properties within an object based on their schema definitions.
             *
             * Determines the type of each property (object, array, oneOf, or file) and delegates to the
             * corresponding handler. Updates the object data with references or processed results.
             *
             * @param array        $property     the schema definition of the property
             * @param string       $propertyName the name of the property in the object
             * @param integer      $register     the register ID associated with the schema
             * @param integer      $schema       the schema ID associated with the property
             * @param array        $object       the parent object data to update
             * @param ObjectEntity $objectEntity the object entity being processed
             * @param integer      $depth        the depth level for nested object processing
             *
             * @return array the updated object with processed properties
             *
             * @throws GuzzleException     when HTTP request fails
             * @throws ValidationException when schema validation or file handling fails
             */
            private function handleProperty(
                array $property,
                string $propertyName,
                int $register,
                int $schema,
                array $object,
                ObjectEntity $objectEntity,
                int $depth=0,
            ): array {
                if (false === isset($property['type'])) {
                    return $object;
                }

                switch ($property['type']) {
                    case 'object':
                        $object[$propertyName] = $this->handleObjectProperty(
                            property: $property,
                            propertyName: $propertyName,
                            item: $object[$propertyName],
                            objectEntity: $objectEntity,
                            register: $register,
                            schema: $schema,
                            depth: $depth
                        );
                        break;
                    case 'array':
                        $object[$propertyName] = $this->handleArrayProperty(
                            property: $property,
                            propertyName: $propertyName,
                            items: $object[$propertyName],
                            objectEntity: $objectEntity,
                            register: $register,
                            schema: $schema,
                            depth: $depth
                        );
                        break;
                    case 'oneOf':
                        $object[$propertyName] = $this->handleOneOfProperty(
                            property: $property['oneOf'],
                            propertyName: $propertyName,
                            item: $object[$propertyName],
                            objectEntity: $objectEntity,
                            register: $register,
                            schema: $schema,
                            depth: $depth
                        );
                        break;
                    case 'file':
                        $object[$propertyName] = $this->handleFileProperty(
                            objectEntity: $objectEntity,
                            object: $object,
                            propertyName: $propertyName,
                            format: $property['format'] ?? null
                        );
                        break;
                    default:
                        break;
                }//end switch

                return $object;

            }//end handleProperty()


    /**
     * Links object relations and handles file-based properties within an object schema.
     *
     * Iterates through schema-defined properties, processing and resolving nested relations,
     * array items, and file-based data. Updates the object entity with resolved references.
     *
     * @param ObjectEntity $objectEntity the object entity being processed
     * @param array        $object       the parent object data to analyze
     * @param array        $properties   the schema properties defining the object structure
     * @param integer      $register     the register ID associated with the schema
     * @param integer      $schema       the schema ID associated with the object
     * @param integer      $depth        the depth level for nested object processing
     *
     * @return ObjectEntity the updated object entity with resolved relations and file references
     *
     * @throws \Exception          when general processing error occurs
     * @throws ValidationException when schema validation fails
     * @throws GuzzleException     when HTTP request fails
     */
            private function handleObjectRelations(
                ObjectEntity $objectEntity,
                array $object,
                array $properties,
                int $register,
                int $schema,
                int $depth=0,
            ): ObjectEntity {
                // @todo: Multidimensional support should be added.
                foreach ($properties as $propertyName => $property) {
                    // Skip if property not in object.
                    if (false === isset($object[$propertyName])) {
                        continue;
                    }

                    $object = $this->handleProperty(
                        property: $property,
                        propertyName: $propertyName,
                        register: $register,
                        schema: $schema,
                        object: $object,
                        objectEntity: $objectEntity,
                        depth: $depth
                    );
                }

                $objectEntity->setObject($object);

                return $objectEntity;

            }//end handleObjectRelations()


    /**
     * Writes file content to storage and creates sharing links.
     *
     * Stores the file content in the NextCloud storage system, creating necessary
     * folder structure, generating share links, and updating file metadata.
     *
     * @param string       $fileContent  The content of the file to store
     * @param string       $propertyName The property name to associate with the file
     * @param ObjectEntity $objectEntity The object entity to associate the file with
     * @param File         $file         The file entity to update with storage information
     *
     * @return File The updated file entity with storage and sharing information
     *
     * @throws \Exception If file storage or sharing fails
     */
            private function writeFile(string $fileContent, string $propertyName, ObjectEntity $objectEntity, File $file): File
            {
                $fileName = $file->getFilename();

                try {
                    $folderNode = $this->fileService->createObjectFolder($objectEntity);
                    $folderPath = $folderNode->getPath();

                    $filePath = $file->getFilePath();

                    if (null === $filePath) {
                        $filePath = "$folderPath/$fileName";
                    }

                    $succes = $this->fileService->updateFile(
                        content: $fileContent,
                        filePath: $filePath,
                    );

                    if (false === $succes) {
                        throw new \Exception('Failed to upload this file: $filePath to NextCloud');
                    }

                    // Create or find ShareLink.
                    $share = $this->fileService->findShare(path: $filePath);
                    if (null !== $share) {
                        $shareLink    = $this->fileService->getShareLink($share);
                        $downloadLink = $shareLink.'/download';
                    } else {
                        $shareLink    = $this->fileService->createShareLink(path: $filePath);
                        $downloadLink = $shareLink.'/download';
                    }

                    $filesDot = new Dot($objectEntity->getFiles() ?? []);
                    $filesDot->set($propertyName, $shareLink);
                    $objectEntity->setFiles($filesDot->all());

                    // Preserve the original uri in the object 'json blob'.
                    $file->setDownloadUrl($downloadLink);
                    $file->setShareUrl($shareLink);
                    $file->setFilePath($filePath);
                } catch (\Exception $e) {
                    throw new \Exception('Failed to store file: '.$e->getMessage());
                }//end try

                return $file;

            }//end writeFile()


    /**
     * Extracts and sets the file extension from the access URL.
     *
     * Uses regular expressions to extract the file extension from the URL pattern
     * and sets it on the file entity if not already defined.
     *
     * @param File $file The file entity to update with extension information
     *
     * @return File The updated file entity with extension information
     */
            private function setExtension(File $file): File
            {
                // Regular expression to get the filename and extension from url.
                if (false === $file->getExtension() && preg_match("/\/([^\/]+)'\)\/\\\$value$/", $file->getAccessUrl(), $matches)) {
                    $fileNameFromUrl = $matches[1];
                    $file->setExtension(substr(strrchr($fileNameFromUrl, '.'), 1));
                }

                return $file;

            }//end setExtension()


    /**
     * Fetches file content from a remote source based on file metadata.
     *
     * Downloads file content from a URL stored in the file entity, with handling for
     * different sources including OpenConnector integration and direct downloads.
     *
     * @param File         $file         The file entity containing access URL and metadata
     * @param string       $propertyName The property name to store the file content under
     * @param ObjectEntity $objectEntity The object entity to associate the file with
     *
     * @return File The updated file entity with download information
     *
     * @throws ContainerExceptionInterface When container resolution fails
     * @throws GuzzleException             When HTTP request fails during download
     * @throws \Exception                  When file download or storage fails
     */
            private function fetchFile(File $file, string $propertyName, ObjectEntity $objectEntity): File
            {
                $fileContent = null;

                // Encode special characters in the URL.
                $encodedUrl = rawurlencode($file->getAccessUrl());

                // Decode valid path separators and reserved characters.
                $encodedUrl = str_replace(['%2F', '%3A', '%28', '%29'], ['/', ':', '(', ')'], $encodedUrl);

                if (filter_var($encodedUrl, FILTER_VALIDATE_URL)) {
                    $this->setExtension($file);
                    try {
                        if (null !== $file->getSource()) {
                            $sourceMapper = $this->getOpenConnector(filePath: '\Db\SourceMapper');
                            $source       = $sourceMapper->find($file->getSource());

                            $callService = $this->getOpenConnector(filePath: '\Service\CallService');
                            if (null === $callService) {
                                throw new \Exception('OpenConnector service not available');
                            }

                            $endpoint = str_replace($source->getLocation(), '', $encodedUrl);
                            $endpoint = urldecode($endpoint);
                            $response = $callService->call(source: $source, endpoint: $endpoint, method: 'GET')->getResponse();

                            $fileContent = $response['body'];

                            if ('base64' === $response['encoding']) {
                                $fileContent = base64_decode(string: $fileContent);
                            }
                        } else {
                            $client      = new Client();
                            $response    = $client->get($encodedUrl);
                            $fileContent = $response->getBody()->getContents();
                        }//end if
                    } catch (\Exception | NotFoundExceptionInterface $e) {
                        throw new \Exception('Failed to download file from URL: '.$e->getMessage());
                    }//end try
                }//end if

                if (null !== $fileContent) {
                    $this->writeFile(fileContent: $fileContent, propertyName: $propertyName, objectEntity: $objectEntity, file: $file);
                }

                return $file;

            }//end fetchFile()


    /**
     * Processes file properties within an object, storing and resolving file content to sharable URLs.
     *
     * Handles both base64-encoded and URL-based file sources, storing the resolved content and
     * updating the object data with the resulting file references.
     *
     * @param ObjectEntity $objectEntity the object entity containing the file property
     * @param array        $object       the parent object data containing the file reference
     * @param string       $propertyName the name of the file property
     * @param string|null  $format       optional format specification for the file
     *
     * @return string the updated object with resolved file references
     *
     * @throws ContainerExceptionInterface when container resolution fails
     * @throws GuzzleException             when HTTP request fails
     * @throws \OCP\DB\Exception           when database operation fails
     */
            private function handleFileProperty(ObjectEntity $objectEntity, array $object, string $propertyName, ?string $format=null): string
            {
                $fileName    = str_replace('.', '_', $propertyName);
                $objectDot   = new Dot($object);
                $fileContent = null;
                $fileEntity  = null;

                // Handle base64 encoded file.
                if (
                    true === is_string($objectDot->get("$propertyName.base64"))
                    && preg_match('/^data:([^;]*);base64,(.*)/', $objectDot->get("$propertyName.base64"), $matches)
                ) {
                    unset($object[$propertyName]['base64']);
                    $fileEntity = new File();
                    $fileEntity->hydrate($object[$propertyName]);
                    $fileEntity->setFilename($fileName);
                    $this->setExtension($fileEntity);
                    $this->fileMapper->insert($fileEntity);
                    $fileContent = base64_decode($matches[2], true);
                    if (false === $fileContent) {
                        throw new \Exception('Invalid base64 encoded file');
                    }

                    $fileEntity = $this->writeFile(fileContent: $fileContent, propertyName: $propertyName, objectEntity: $objectEntity, file: $fileEntity);
                } //end if

                else {
                    $fileEntities = $this->fileMapper->findAll(filters: ['accessUrl' => $objectDot->get("$propertyName.accessUrl")]);
                    if (count($fileEntities) > 0) {
                        $fileEntity = $fileEntities[0];
                    }

                    if (0 === count($fileEntities)) {
                        $fileEntity = $this->fileMapper->createFromArray($object[$propertyName]);
                    }

                    if (null === $fileEntity->getFilename()) {
                        $fileEntity->setFilename($fileName);
                    }

                    if (null === $fileEntity->getChecksum() || $fileEntity->getUpdated() > new \DateTime('-5 minutes')) {
                        $fileEntity = $this->fetchFile(file: $fileEntity, propertyName: $propertyName, objectEntity: $objectEntity);
                        $fileEntity->setUpdated(new \DateTime());
                    }
                }

                if (null !== $fileEntity) {
                    $fileEntity->setChecksum(md5(serialize($fileContent)));
                    $this->fileMapper->update($fileEntity);
                }

                switch ($format) {
                    case 'filename':
                        return null === $fileEntity ? '' : $fileEntity->getFileName();
                    case 'extension':
                        return null === $fileEntity ? '' : $fileEntity->getExtension();
                    case 'shareUrl':
                        return null === $fileEntity ? '' : $fileEntity->getShareUrl();
                    case 'accessUrl':
                        return null === $fileEntity ? '' : $fileEntity->getAccessUrl();
                    case 'downloadUrl':
                    default:
                        return null === $fileEntity ? '' : $fileEntity->getDownloadUrl();
                }

            }//end handleFileProperty()


    /**
     * Get files for object.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass
     *
     * @param ObjectEntity|string $object The object or object ID to fetch files for
     *
     * @return Node[] The files found
     *
     * @throws NotFoundException     If the folder is not found
     * @throws DoesNotExistException If the object ID is not found
     */
            public function getFiles(ObjectEntity | string $object): array
            {
                // If string ID provided, try to find the object entity.
                if (is_string($object)) {
                    $object = $this->objectEntityMapper->find($object);
                }

                $folder = $this->fileService->getObjectFolder(
                    objectEntity: $object,
                    register: $object->getRegister(),
                    schema: $object->getSchema()
                );

                $files = [];
                if (true === $folder instanceof Folder) {
                    $files = $folder->getDirectoryListing();
                }

                return $files;

            }//end getFiles()


    /**
     * Get a single file for an object by filepath.
     *
     * @param ObjectEntity|string $object   The object or object ID to fetch the file for
     * @param string              $filePath The path to the specific file
     *
     * @return Node|null The file if found, null otherwise
     *
     * @throws NotFoundException     If the folder or file is not found
     * @throws DoesNotExistException If the object ID is not found
     */
            public function getFile(ObjectEntity | string $object, string $filePath): ?Node
            {
                // If string ID provided, try to find the object entity.
                if (is_string($object)) {
                    $object = $this->objectEntityMapper->find($object);
                }

                $folder = $this->fileService->getObjectFolder(
                    objectEntity: $object,
                    register: $object->getRegister(),
                    schema: $object->getSchema()
                );

                if (true === $folder instanceof Folder) {
                    try {
                        return $folder->get($filePath);
                    } catch (NotFoundException $e) {
                        return null;
                    }
                }

                return null;

            }//end getFile()


    /**
     * Add a file to the object.
     *
     * @param ObjectEntity|string $object        The object to add the file to
     * @param string              $fileName      The name of the file to add
     * @param string              $base64Content The base64 encoded content of the file
     * @param boolean             $share         Whether to create a share link for the file
     * @param array               $tags          Optional array of tags to attach to the file
     *
     * @return \OCP\Files\File The added file
     *
     * @throws \Exception If file addition fails
     */
            public function addFile(ObjectEntity | string $object, string $fileName, string $base64Content, bool $share=false, array $tags=[]): \OCP\Files\File
            {
                // If string ID provided, try to find the object entity.
                if (is_string($object)) {
                    $object = $this->objectEntityMapper->find($object);
                }

                return $this->fileService->addFile(objectEntity: $object, fileName: $fileName, content: base64_decode($base64Content), share: $share, tags: $tags);

            }//end addFile()


    /**
     * Update an existing file for an object.
     *
     * @param ObjectEntity|string $object   The object or object ID
     * @param string              $filePath Path to the file to update
     * @param string|null         $content  Optional new file content
     * @param array               $tags     Optional tags to update
     *
     * @return \OCP\Files\File The updated file
     *
     * @throws \Exception If file update fails
     */
            public function updateFile(ObjectEntity | string $object, string $filePath, ?string $content=null, array $tags=[]): \OCP\Files\File
            {
                // If string ID provided, try to find the object entity.
                if (is_string($object)) {
                    $object = $this->objectEntityMapper->find($object);
                }

                return $this->fileService->updateFile(
                    filePath: $this->fileService->getObjectFilePath($object, $filePath),
                    content: $content,
                    tags: $tags
                );

            }//end updateFile()


    /**
     * Retrieves all available tags in the system.
     *
     * This method fetches all tags that are visible and assignable by users
     * from the system tag manager.
     *
     * @return array An array of tag names
     *
     * @throws \Exception If there's an error retrieving the tags
     *
     * @psalm-return array<int, string>
     *
     * @phpstan-return array<int, string>
     */
            public function getAllTags(): array
            {
                return $this->fileService->getAllTags();

            }//end getAllTags()


    /**
     * Delete a file from an object.
     *
     * @param ObjectEntity|string $object   The object or object ID
     * @param string              $filePath Path to the file to delete
     *
     * @return boolean True if successful
     *
     * @throws \Exception If file deletion fails
     */
            public function deleteFile(ObjectEntity | string $object, string $filePath): bool
            {
                // If string ID provided, try to find the object entity.
                if (is_string($object)) {
                    $object = $this->objectEntityMapper->find($object);
                }

                return $this->fileService->deleteFile(
                    filePath: $this->fileService->getObjectFilePath($object, $filePath)
                );

            }//end deleteFile()


    /**
     * Publish a file by creating a public share link.
     *
     * @todo Should be in file service.
     *
     * @param ObjectEntity|string $object   The object or object ID
     * @param string              $filePath Path to the file to publish
     *
     * @return \OCP\Files\File The published file
     *
     * @throws \Exception If file publishing fails
     */
            public function publishFile(ObjectEntity | string $object, string $filePath): \OCP\Files\File
            {
                // If string ID provided, try to find the object entity.
                if (is_string($object)) {
                    $object = $this->objectEntityMapper->find($object);
                }

                // Get the file node.
                $fullPath = $this->fileService->getObjectFilePath($object, $filePath);
                $file     = $this->fileService->getNode($fullPath);

                if (!$file instanceof \OCP\Files\File) {
                    throw new \Exception('File not found');
                }

                $shareLink = $this->fileService->createShareLink(path: $file->getPath());

                return $file;

            }//end publishFile()


    /**
     * Unpublish a file by removing its public share link.
     *
     * @todo Should be in file service.
     *
     * @param ObjectEntity|string $object   The object or object ID
     * @param string              $filePath Path to the file to unpublish
     *
     * @return \OCP\Files\File The unpublished file
     *
     * @throws \Exception If file unpublishing fails
     */
            public function unpublishFile(ObjectEntity | string $object, string $filePath): \OCP\Files\File
            {
                // If string ID provided, try to find the object entity.
                if (is_string($object)) {
                    $object = $this->objectEntityMapper->find($object);
                }

                // Get the file node.
                $fullPath = $this->fileService->getObjectFilePath($object, $filePath);
                $file     = $this->fileService->getNode($fullPath);

                if (!$file instanceof \OCP\Files\File) {
                    throw new \Exception('File not found');
                }

                $this->fileService->deleteShareLinks(file: $file);

                return $file;

            }//end unpublishFile()


    /**
     * Formats an array of Node files into an array of metadata arrays.
     * Uses FileService formatFiles function, this function is here to be used by OpenCatalog or OpenConnector!
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass
     *
     * @param Node[] $files         Array of Node files to format
     * @param array  $requestParams Optional request parameters
     *
     * @return array Array of formatted file metadata arrays
     *
     * @throws InvalidPathException
     * @throws NotFoundException
     */
            public function formatFiles(array $files, ?array $requestParams=[]): array
            {
                return $this->fileService->formatFiles($files, $requestParams);

            }//end formatFiles()


    /**
     * Formats a single Node file into a metadata array.
     * Uses FileService formatFile function, this function is here to be used by OpenCatalog or OpenConnector!
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass
     *
     * @param Node $file The Node file to format
     *
     * @return array The formatted file metadata array
     */
            public function formatFile(Node $file): array
            {
                return $this->fileService->formatFile($file);

            }//end formatFile()


    /**
     * Hydrate files array with metadata.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass
     *
     * @param ObjectEntity $object the object to hydrate the files array of
     * @param Node[]       $files  the files to hydrate the files array with
     *
     * @return ObjectEntity the object with hydrated files array
     */
            public function hydrateFiles(ObjectEntity $object, array $files): ObjectEntity
            {
                $formattedFiles = [];
                try {
                    $formattedFiles = $this->fileService->formatFiles($files);
                } catch (InvalidPathException | NotFoundException $e) {
                    // Empty catch block.
                }

                $object->setFiles($formattedFiles);

                return $object;

            }//end hydrateFiles()


    /**
     * Retrieves an object from a specified register and schema using its UUID.
     *
     * Supports only internal sources and raises an exception for unsupported source types.
     *
     * @param Register   $register the register from which the object is retrieved
     * @param Schema     $schema   the schema defining the object structure
     * @param string     $uuid     the unique identifier of the object to retrieve
     * @param array|null $extend   optional properties to include in the retrieved object
     * @param boolean    $files    whether to include files in the response
     *
     * @return ObjectEntity the retrieved object as an entity
     *
     * @throws \Exception if the source type is unsupported
     */
            public function getObject(Register $register, Schema $schema, string $uuid, ?array $extend=[], bool $files=false): ObjectEntity
            {
                // Handle internal source.
                if ('internal' === $register->getSource() || '' === $register->getSource()) {
                    $object = $this->objectEntityMapper->findByUuid($register, $schema, $uuid);

                    if (false === $files) {
                        return $object;
                    }

                    $files = $this->getFiles($object);

                    return $this->hydrateFiles($object, $files);
                }

                // @todo mongodb support.        throw new \Exception('Unsupported source type');
            }//end getObject()


    /**
     * Check if a string contains a dot and get the substring before the first dot.
     *
     * @param string $input the input string
     *
     * @return string the substring before the first dot, or the original string if no dot is found
     */
            private function getStringBeforeDot(string $input): string
            {
                // Find the position of the first dot.
                $dotPosition = strpos($input, '.');

                // Return the substring before the dot, or the original string if no dot is found.
                return false !== $dotPosition ? substr($input, 0, $dotPosition) : $input;

            }//end getStringBeforeDot()


    /**
     * Get the substring after the last slash in a string.
     *
     * @param string $input the input string
     *
     * @return string the substring after the last slash
     */
            public function getStringAfterLastSlash(string $input): string
            {
                // Find the position of the last slash.
                $lastSlashPos = strrpos($input, '/');

                // Return the substring after the last slash, or the original string if no slash is found.
                return false !== $lastSlashPos ? substr($input, $lastSlashPos + 1) : $input;

            }//end getStringAfterLastSlash()


    /**
     * Cascade delete related objects based on schema properties.
     *
     * This method identifies properties in the schema marked for cascade deletion and deletes
     * related objects associated with those properties in the given object.
     *
     * @param Register     $register         the register containing the objects
     * @param Schema       $schema           the schema defining the properties and relationships
     * @param ObjectEntity $object           the object entity whose related objects should be deleted
     * @param string       $originalObjectId the ID of the original object being deleted
     *
     * @throws \Exception if any errors occur during the deletion process
     */
            private function cascadeDeleteObjects(Register $register, Schema $schema, ObjectEntity $object, string $originalObjectId): void
            {
                $cascadeDeleteProperties = [];
                foreach ($schema->getProperties() as $propertyName => $property) {
                    if ((true === isset($property['cascadeDelete']) && true === $property['cascadeDelete']) || (true === isset($property['items']['cascadeDelete']) && true === $property['items']['cascadeDelete'])) {
                        $cascadeDeleteProperties[] = $propertyName;
                    }
                }

                foreach ($object->getRelations() as $relationName => $relation) {
                    $relationName    = $this->getStringBeforeDot(input: $relationName);
                    $relatedObjectId = $this->getStringAfterLastSlash(input: $relation);
                    // Check if this sub object has cascadeDelete = true and is not the original object that started this delete streak.
                    if (true === in_array(needle: $relationName, haystack: $cascadeDeleteProperties) && $relatedObjectId !== $originalObjectId) {
                        $this->deleteObject(register: $register->getId(), schema: $schema->getId(), uuid: $relatedObjectId, originalObjectId: $originalObjectId);
                    }
                }

            }//end cascadeDeleteObjects()


    /**
     * Delete an object.
     *
     * @param string|integer $register         The register to delete from
     * @param string|integer $schema           The schema of the object
     * @param string         $uuid             The UUID of the object to delete
     * @param string|null    $originalObjectId The UUID of the parent object so we dont delete the object we come from and cause a loop
     *
     * @return boolean True if deletion was successful
     *
     * @throws \Exception If source type is unsupported
     */
            public function deleteObject($register, $schema, string $uuid, ?string $originalObjectId=null): bool
            {
                $register = $this->registerMapper->find($register);
                $schema   = $this->schemaMapper->find($schema);

                // Handle internal source.
                if ('internal' === $register->getSource() || '' === $register->getSource()) {
                    $object = $this->objectEntityMapper->findByUuidOnly(uuid: $uuid);

                    if (null === $object) {
                        return false;
                    }

                    // If internal register and schema should be found from the object himself. Makes it possible to delete cascaded objects.
                    $register = $this->registerMapper->find($object->getRegister());
                    $schema   = $this->schemaMapper->find($object->getSchema());

                    if (null === $originalObjectId) {
                        $originalObjectId = $object->getUuid();
                    }

                    $this->cascadeDeleteObjects(register: $register, schema: $schema, object: $object, originalObjectId: $originalObjectId);

                    // Todo: delete files.
                    $this->objectEntityMapper->delete($object);
                    return true;
                }//end if

                // @todo mongodb support.
                throw new \Exception('Unsupported source type');
            }//end deleteObject()


    /**
     * Retrieves the appropriate mapper for a specific object type.
     *
     * Optionally sets the current register and schema when both are provided.
     *
     * @param string|null  $objectType the type of the object for which a mapper is needed
     * @param integer|null $register   optional register ID to set for the mapper
     * @param integer|null $schema     optional schema ID to set for the mapper
     *
     * @return mixed the mapper for the specified object type
     *
     * @throws \InvalidArgumentException if the object type is unknown
     */
            public function getMapper(?string $objectType=null, ?int $register=null, ?int $schema=null): mixed
            {
                // Return self if register and schema provided.
                if (null !== $register && null !== $schema) {
                    $this->setSchema($schema);
                    $this->setRegister($register);

                    return $this;
                }

                // Return appropriate mapper based on object type.
                switch ($objectType) {
                    case 'register':
                        return $this->registerMapper;
                    case 'schema':
                        return $this->schemaMapper;
                    case 'objectEntity':
                        return $this->objectEntityMapper;
                    default:
                        throw new \InvalidArgumentException("Unknown object type: $objectType");
                }

            }//end getMapper()


    /**
     * Retrieves multiple objects of a specified type using their identifiers.
     *
     * Processes and cleans input IDs to ensure compatibility with the mapper.
     *
     * @param string $objectType the type of objects to retrieve
     * @param array  $ids        the list of object IDs to retrieve
     *
     * @return array the retrieved objects
     *
     * @throws \InvalidArgumentException if the object type is unknown
     */
            public function getMultipleObjects(string $objectType, array $ids): array
            {
                // Process the ids to handle different formats.
                $processedIds = array_map(
                    function ($id) {
                        if (is_object($id) && method_exists($id, 'getId')) {
                            return $id->getId();
                        } else if (is_array($id) && isset($id['id'])) {
                            return $id['id'];
                        } else {
                            return $id;
                        }
                    },
                    $ids
                );

                // Clean up URIs to get just the ID portion.
                $cleanedIds = array_map(
                    function ($id) {
                        if (filter_var($id, FILTER_VALIDATE_URL)) {
                            $parts = explode('/', rtrim($id, '/'));

                            return end($parts);
                        }

                        return $id;
                    },
                    $processedIds
                );

                // Get mapper and find objects.
                $mapper = $this->getMapper($objectType);

                return $mapper->findMultiple($cleanedIds);

            }//end getMultipleObjects()


    /**
     * Renders an entity by replacing file and relation IDs with their respective objects.
     *
     * Expands files and relations within the entity based on the provided extend array.
     * Optionally filters out specified properties from the entity and returns only specified fields.
     *
     * @param array      $entity the serialized entity
     * @param array|null $extend optional properties to expand within the entity
     * @param integer    $depth  the depth to which relations should be expanded
     * @param array|null $filter optional array of property names to be excluded from the entity
     * @param array|null $fields optional array of property names to be included in the result
     *
     * @return array the rendered entity with expanded properties
     *
     * @throws \InvalidArgumentException if both filters and fields are used simultaneously or if extend and fields/filters conflict
     * @throws \Exception                if rendering or extending fails
     *
     * @phpstan-param array<string, mixed> $entity
     * @phpstan-param array<string>|null $extend
     * @phpstan-param array<string>|null $filter
     * @phpstan-param array<string>|null $fields
     */
            public function renderEntity(array $entity, ?array $extend=[], int $depth=0, ?array $filter=[], ?array $fields=[]): array
            {
                $dotEntity = new Dot($entity);

                // Check for simultaneous use of filters and fields.
                if (!empty($filter) && !empty($fields)) {
                    throw new \InvalidArgumentException('Cannot use both filters and fields simultaneously.');
                }

                // Check for conflicts between extend and fields/filters.
                if (false === empty($extend) && false === empty($fields)) {
                    $missingFields = array_diff($extend, $fields);
                    if (!empty($missingFields)) {
                        throw new \InvalidArgumentException('Properties in extend must also be in fields: '.implode(', ', $missingFields));
                    }
                } else if (false === empty($extend) && false === empty($filter)) {
                    $conflictingFilters = array_intersect($extend, $filter);
                    if (!empty($conflictingFilters)) {
                        throw new \InvalidArgumentException('Properties in extend must not be in filters: '.implode(', ', $conflictingFilters));
                    }
                }

                // Setup a placeholder for related objects to avoid refetching them.
                $relatedObjects = [];

                // Use the filter array to remove specified properties from the entity.
                $dotEntity->delete(keys: $filter);

                // If fields are specified, filter the entity to include only those fields.
                // @TODO: combining fields and extend causes issues with an id, probably that is caused here.
                if (false === empty($fields)) {
                    $dotEntity = new Dot(
                        array_filter(
                            $dotEntity->flatten(),
                            function ($key) use ($fields) {
                                return in_array($key, $fields);
                            },
                            ARRAY_FILTER_USE_KEY
                        ),
                        parse: true
                    );
                }

                // Get the schema for this entity.
                $schema = $this->schemaMapper->find($dotEntity->get('@self.schema'));

                // This needs to be done before we start extending the entity made operational.
                // Loop through the files and replace the file ids with the file objects.
                if ($dotEntity->has('@self.files') && false === empty($dotEntity->get('@self.files'))) {
                    // Loop through the files array where key is dot notation path and value is file id.
                    foreach ($dotEntity->get('@self.files')->all() as $path => $fileId) {
                        // Replace the value at the dot notation path with the file URL.
                        // @todo: does not work.
                        // $dotEntity->set($path, $filesById[$fileId]->getUrl());
                    }
                }

                /**
                 * Processes inverted relations for the entity.
                 *
                 * This core functionality handles properties marked as 'inverted' in the schema.
                 * It finds objects that reference this entity and adds them to the appropriate
                 * properties in the entity based on the inverted relation configuration.
                 *
                 * This function only sets the uuid of the referencing objects in the entity. (exendt is defined at another point)
                 */

                // Get all properties from the schema that have inverted=true.
                $invertedProperties = array_filter(
                    $schema->getProperties(),
                    fn ($property) => isset($property['inversedBy']) && false === empty($property['inversedBy'])
                );

                // Only process inverted relations if we have inverted properties.
                if (false === empty($invertedProperties)) {
                    // Get objects that reference this entity.            $usedByObjects = $this->objectEntityMapper->findAll(uses: $entity['uuid']);            // Loop through inverted properties and add referenced objects.
                    foreach ($invertedProperties as $key => $property) {
                        // Filter objects that reference this entity through the specified inverted property.
                        $referencingObjects = array_filter(
                            $usedByObjects,
                            fn ($obj) => isset($obj->getRelations()[$property['inversedBy']]) && $obj->getRelations()[$property['inversedBy']] === $entity['uuid']
                        );

                        // Extract only the UUIDs from the referencing objects instead of the entire objects.
                        $referencingUuids = array_map(
                            fn ($obj) => $obj->getUuid(),
                            $referencingObjects
                        );

                        // Set only the UUIDs in the entity property.
                        if ('array' !== $property['type']) {
                            $dotEntity[$key] = end($referencingUuids);
                        } else {
                            $dotEntity[$key] = $referencingUuids;
                        }
                    }

                    // Store the referenced objects in the related objects array for potential later extension.
                    $relatedObjects = array_merge($relatedObjects, $usedByObjects);
                    unset($usedByObjects);
                }//end if

                // If extending is asked for we can get the related objects and extend them.
                // Check if the entity has relations and is not empty.
                if ($dotEntity->has('@self.relations') && false === empty($dotEntity->get('@self.relations'))) {
                    // Extract all the related object IDs from the relations array.
                    $objectIds = array_values($dotEntity->get('@self.relations'));

                    // Use the getMany function from the mapper to retrieve all related objects.
                    $objects = $this->objectEntityMapper->findMultiple($objectIds);

                    // Serialize the related objects to cast them to arrays.
                    $objects = array_map(fn ($obj) => $obj->jsonSerialize(), $objects);

                    // Add them to the related objects array.
                    $relatedObjects = array_merge($relatedObjects, $objects);
                    unset($objects);
                }

                /*
                 * Extend the entity with related properties.
                 *
                 * This section checks if there are properties specified in the 'extend' array.
                 * For each property, it verifies if the property exists in the dotEntity.
                 * If the property exists, it attempts to find a related object from the relatedObjects array.
                 * If a related object is found, it sets the property in the dotEntity with the related object.
                 * If no related object is found, it logs an error for that property.
                 * Finally, if there are any errors, they are set in the dotEntity.
                 */
                // Check if there are properties specified in the 'extend' array.
                if (!empty($extend)) {
                    if ($extend === ['all']) {
                        $extend = array_keys($dotEntity->all());
                    }

                    $errors = [];
        // Initialize an array to store any errors encountered during the process.            // Iterate over each property in the 'extend' array.
                    foreach ($extend as $property) {
                        // Check if the current property exists in the dotEntity.
                        if (false === $dotEntity->has($property)) {
                            $errors[] = "Property '$property' could not be extended because it does not exist.";
                            continue;
                        }

                        // Retrieve the value of the property from the dotEntity.
                        $value = $dotEntity->get($property);

                        // Filter the relatedObjects array to find the object that matches the value.
                        $relatedObject = array_filter(
                            $relatedObjects,
                            function ($obj) use ($value) {
                            // Check if the object's id, uuid, or url matches the value.
                                return $obj['@self']['id'] === $value || $obj['@self']['uuid'] === $value || $obj['@self']['url'] === $value;
                            }
                        );

                        // Check if a related object was found.
                        if (true === empty($relatedObject)) {
                            // Add an error message if the related object could not be found.
                            $errors[] = "Property '$property' could not be extended.";
                            continue;
                        }

                        // Get the first related object from the filtered results.
                        $relatedObject = reset($relatedObject);

                        // Check if the property is a nested property (contains a dot).
                        if (false !== strpos($property, '.')) {
                            // Split the property into main and sub-properties.
                            $subProperties   = explode('.', $property);
                            $mainProperty    = array_shift($subProperties);
                            $subPropertyPath = implode('.', $subProperties);

                            // Render the related object with the specified extensions.
                            $relatedObject = $this->renderEntity(
                                $relatedObject,
                                $extend,
                                $depth + 1,
                                $filter,
                                $fields
                            );

                            // Set the rendered related object in the dotEntity under the main property.
                            $dotEntity->set($mainProperty, $relatedObject);
                        } else {
                            // Set the related object directly in the dotEntity for non-nested properties.
                            $dotEntity->set($property, $relatedObject);
                        }//end if
                    }//end foreach

                    // If there are any errors, set them in the dotEntity under '@self.errors'.
                    if (!empty($errors)) {
                        $dotEntity->set('@self.errors', $errors);
                    }
                }//end if

                // Update the entity with all modified values from the dotEntity.
                // Let's return the entity with the extended properties.
                return $dotEntity->all();

            }//end renderEntity()


    /**
     * Extends an entity with related objects based on the extend array.
     *
     * @deprecated Use renderEntity
     *
     * @param mixed        $entity The entity to extend
     * @param array        $extend Properties to extend with related data
     * @param integer|null $depth  Maximum recursion depth
     *
     * @return array The extended entity as an array
     *
     * @throws \Exception If property not found
     */
            public function extendEntity(array $entity, array $extend, ?int $depth=0): array
            {
            if ($depth > 3) {
                return $entity;


            }

        // Convert entity to array if needed.
            if (is_array($entity)) {
                $result = $entity;
            } else {
                $result = $entity->jsonSerialize();
            }

            if (in_array('all', $extend)) {
                $extendProperties = array_keys($result);
            }

        // Process each property to extend.
            foreach ($extendProperties as $property) {
                $singularProperty = rtrim($property, 's');

                // Check if property exists.
                if (
                    true === array_key_exists(
                        key: $property, array:
                        $result
                    )
                )                           {
                $value = $result[$property];
                if (empty($value)) {
                    continue;
                }

                } else if (
                    array_key_exists(
                        key: $singularProperty, array:
                        $result
                    )
                )                           {
                    $value = $result[$singularProperty];
                    } else {
                        throw new \Exception("Property '$property' or '$singularProperty' is not present in the entity.");
                    }

                    // Try to get mapper for property.
                    try {
                        $mapper         = $this->getMapper(objectType: $property);
                        $propertyObject = $singularProperty;

                        // Extend with related objects using specific mapper.
                        if (true === is_array($value)) {
                            $result[$property] = $this->getMultipleObjects(objectType: $propertyObject, ids: $value);
                        } else {
                            $objectId          = is_object(value: $value) ? $value->getId() : $value;
                            $result[$property] = $mapper->find($objectId);
                        }
                    } catch (\Exception $e) {
                        // If no specific mapper found, try to look up values in default database.
                        try {
                            if (is_array($value)) {
                                // Handle array of values.
                                $extendedValues = [];
                                foreach ($value as $val) {
                                    try {
                                        $found = $this->objectEntityMapper->find($val);
                                        if ($found) {
                                            $extendedFound    = $this->renderEntity($found->jsonSerialize(), $extend, $depth + 1);
                                            $extendedValues[] = $extendedFound;
                                        }
                                    } catch (\Exception $e) {
                                        continue;
                                    }
                                }

                                if (!empty($extendedValues)) {
                                    $result[$property] = $extendedValues;
                                }
                            } else {
                                // Handle single value.
                                $found = $this->objectEntityMapper->find($value);
                                if ($found) {
                                    // Serialize and recursively extend the found object (apply depth tracking here).
                                    $result[$property] = $this->renderEntity($found->jsonSerialize(), $extend, $depth + 1);
// Start with depth 1.
                                }
                            }//end if
                        } catch (\Exception $e2) {
                            // If lookup fails, keep original value.
                            continue;
                        }//end try
                    }//end try
                }

                return $result;
                }//end foreach


            /**
             * Get all registers extended with their schemas.
             *
             * @return array The registers with schema data
             *
             * @throws \Exception If extension fails
             */
                public function getRegisters(): array
                {
                    // Get all registers.
                    $registers = $this->registerMapper->findAll();

                    // Convert to arrays and extend schemas.
                    $registers = array_map(
                        function ($register) {
                            $registerArray = is_array($register) ? $register : $register->jsonSerialize();

                        // Replace schema IDs with actual schema objects if schemas property exists.
                            if (isset($registerArray['schemas']) && is_array($registerArray['schemas'])) {
                                $registerArray['schemas'] = array_map(
                                    function ($schemaId) {
                                        try {
                                            return $this->schemaMapper->find($schemaId)->jsonSerialize();
                                        } catch (\Exception $e) {
                                            // If schema can't be found, return the ID.
                                            return $schemaId;
                                        }
                                    },
                                    $registerArray['schemas']
                                );
                            }

                            return $registerArray;
                        },
                        $registers
                    );

                    return $registers;
                }//end getRegisters()


        /**
         * Retrieves the current register ID.
         *
         * @return integer the current register ID
         */
                public function getRegister(): int
                {
                    return $this->register;
                }//end getRegister()


        /**
         * Sets the current register ID.
         *
         * @param integer $register the register ID to set
         */
                public function setRegister(int $register): void
                {
                    $this->register = $register;
                }//end setRegister()


        /**
         * Retrieves the current schema ID.
         *
         * @return integer the current schema ID
         */
                public function getSchema(): int
                {
                    return $this->schema;
                }//end getSchema()


        /**
         * Sets the current schema ID.
         *
         * @param integer $schema the schema ID to set
         */
                public function setSchema(int $schema): void
                {
                    $this->schema = $schema;
                }//end setSchema()


        /**
         * Get the audit trail for a specific object.
         *
         * @param string       $id            The object ID
         * @param integer|null $register      Optional register ID to override current register
         * @param integer|null $schema        Optional schema ID to override current schema
         * @param array        $requestParams Optional request parameters
         *
         * @return array The audit trail entries
         */
                public function getPaginatedAuditTrail(string $id, ?int $register=null, ?int $schema=null, ?array $requestParams=[]): array
                {
                    // Extract specific parameters.
                    $limit  = $requestParams['limit'] ?? $requestParams['_limit'] ?? 20;
                    $offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? null;
                    $order  = $requestParams['order'] ?? $requestParams['_order'] ?? [];
                    $extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
                    $page   = $requestParams['page'] ?? $requestParams['_page'] ?? null;
                    $search = $requestParams['_search'] ?? null;

                    if (null !== $page && isset($limit)) {
                        $page   = (int) $page;
                        $offset = $limit * ($page - 1);
                    }

                    // Ensure order and extend are arrays.
                    if (true === is_string($order)) {
                        $order = array_map('trim', explode(',', $order));
                    }

                    if (true === is_string($extend)) {
                        $extend = array_map('trim', explode(',', $extend));
                    }

                    // Remove unnecessary parameters from filters.
                    $filters = $requestParams;
                    unset($filters['_route']);
            // TODO: Investigate why this is here and if it's needed.
                    unset($filters['_extend'], $filters['_limit'], $filters['_offset'], $filters['_order'], $filters['_page'], $filters['_search']);

                    unset($filters['extend'], $filters['limit'], $filters['offset'], $filters['order'], $filters['page']);

                    // Let's force the object id to be the object id of the object we are getting the audit trail for.
                    $object            = $this->objectEntityMapper->find($id)->getObjectArray();
                    $filters           = [];
                    $filters['object'] = $object['id'];

                    // var_dump($filters);
                    // die;        // $filters['registerUuid'] = $object->getRegisterUuid();
                    // $filters['schemaUuid'] = $object->getSchemaUuid();

                    /**
             * @todo this is not working, it fails to find the logs.
*/
                    $auditTrails = $this->auditTrailMapper->findAll(limit: $limit, offset: $offset, filters: $filters, sort: $order, search: $search);

                    // Format the audit trails.
                    $total = count($auditTrails);
                    $pages = null !== $limit ? ceil($total / $limit) : 1;

                    return [
                        'results' => $auditTrails,
                        'total'   => $total,
                        'page'    => $page ?? 1,
                        'pages'   => $pages,
                    ];
                }//end getPaginatedAuditTrail()


        /**
         * Get all relations for a specific object
         * Returns objects that link to this object (incoming references).
         *
         * @param string       $id            The object ID
         * @param integer|null $register      Optional register ID to override current register
         * @param integer|null $schema        Optional schema ID to override current schema
         * @param array        $requestParams Optional request parameters
         *
         * @return array The objects that reference this object
         */
                public function getRelations(string $id, ?int $register=null, ?int $schema=null, ?array $requestParams=[]): array
                {
                    $register = $register ?? $this->getRegister();
                    $schema   = $schema ?? $this->getSchema();

                    // Get the object to get its URI and UUID.
                    $object = $this->find($id);

                    // Find objects that reference this object's URI or UUID.
                    $referencingObjects = $this->objectEntityMapper->findByRelationUri(
                        search: $object->getUuid(),
                        partialMatch: true
                    );

                    // Filter out self-references if any.
                    $referencingObjects = array_filter(
                        $referencingObjects,
                        function ($referencingObject) use ($id) {
                            return $referencingObject->getUuid() !== $id;
                        }
                    );

                    return $referencingObjects;
                }//end getRelations()


        /**
         * Get paginated relations for a specific object
         * Returns a paginated list of objects that link to this object (incoming references).
         *
         * @param string       $id            The object ID
         * @param integer|null $register      Optional register ID to override current register
         * @param integer|null $schema        Optional schema ID to override current schema
         * @param array        $requestParams Optional request parameters for pagination, filtering and sorting
         *
         * @return array The paginated list of objects that reference this object, with metadata
         */
                public function getPaginatedRelations(string $id, ?int $register=null, ?int $schema=null, ?array $requestParams=[]): array
                {
                    // Get the object to get its URI and UUID.
                    $object = $this->objectEntityMapper->find($id);

                    // Extract specific parameters.
                    $limit  = $requestParams['limit'] ?? $requestParams['_limit'] ?? 20;
                    $offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? null;
                    $order  = $requestParams['order'] ?? $requestParams['_order'] ?? [];
                    $extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
                    $page   = $requestParams['page'] ?? $requestParams['_page'] ?? null;
                    $search = $requestParams['_search'] ?? null;

                    if (null !== $page && isset($limit)) {
                        $page   = (int) $page;
                        $offset = $limit * ($page - 1);
                    }

                    // Ensure order and extend are arrays.
                    if (true === is_string($order)) {
                        $order = array_map('trim', explode(',', $order));
                    }

                    if (true === is_string($extend)) {
                        $extend = array_map('trim', explode(',', $extend));
                    }

                    // Remove unnecessary parameters from filters.
                    $filters = $requestParams;
                    unset($filters['_route']);
            // TODO: Investigate why this is here and if it's needed.
                    unset($filters['_extend'], $filters['_limit'], $filters['_offset'], $filters['_order'], $filters['_page'], $filters['_search']);
                    unset($filters['extend'], $filters['limit'], $filters['offset'], $filters['order'], $filters['page']);

                    // Filter out self-references if any.
                    $objects = $this->objectEntityMapper->findAll(limit: $limit, offset: $offset, filters: $filters, sort: $order, search: $search, ids: $object->getRelations());

                    // Apply pagination.
                    $total = $this->objectEntityMapper->countAll(filters: $filters);
                    $pages = null !== $limit ? ceil($total / $limit) : 1;

                    return [
                        'results' => $objects,
                        'total'   => $total,
                        'page'    => $page ?? 1,
                        'pages'   => $pages,
                    ];
                }//end getPaginatedRelations()


        /**
         * Get all uses of a specific object
         * Returns objects that this object links to (outgoing references).
         *
         * @param string       $id            The object ID
         * @param integer|null $register      Optional register ID to override current register
         * @param integer|null $schema        Optional schema ID to override current schema
         * @param array        $requestParams Optional request parameters
         *
         * @return array The objects this object references
         */
                public function getPaginatedUses(string $id, ?int $register=null, ?int $schema=null, ?array $requestParams=[]): array
                {
                    // First get the object to access its relations.
                    // $object = $this->find($id);
                    // $relations = $object->getRelations() ?? [];        // Extract specific parameters.
                    $limit  = $requestParams['limit'] ?? $requestParams['_limit'] ?? 20;
                    $offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? null;
                    $order  = $requestParams['order'] ?? $requestParams['_order'] ?? [];
                    $extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
                    $page   = $requestParams['page'] ?? $requestParams['_page'] ?? null;
                    $search = $requestParams['_search'] ?? null;

                    if (null !== $page && isset($limit)) {
                        $page   = (int) $page;
                        $offset = $limit * ($page - 1);
                    }

                    // Ensure order and extend are arrays.
                    if (true === is_string($order)) {
                        $order = array_map('trim', explode(',', $order));
                    }

                    if (true === is_string($extend)) {
                        $extend = array_map('trim', explode(',', $extend));
                    }

                    // Remove unnecessary parameters from filters.
                    $filters = $requestParams;
                    unset($filters['_route']);
    // TODO: Investigate why this is here and if it's needed.
                    unset($filters['_extend'], $filters['_limit'], $filters['_offset'], $filters['_order'], $filters['_page'], $filters['_search']);

                    unset($filters['extend'], $filters['limit'], $filters['offset'], $filters['order'], $filters['page']);

            // Let's force the object id to be the object id of the object we are getting the audit trail for.
            // $filters['object'] = $object->getId();

            /**
             * @todo this is not working, it fails to find the logs.
             * $auditTrails = $this->auditTrailMapper->findAll(limit: $limit, offset: $offset, filters: $filters, sort: $order, search: $search, extend: $extend);
             */

            // Get all referenced objects.
            // $referencedObjects = [];
            // foreach ($relations as $path => $relationId) {
            // $referencedObjects[$path] = $this->objectEntityMapper->find($relationId);
            // if ($referencedObjects[$path] === null){
            // $referencedObjects[$path] = $relationId;
            // }
            // }
                    $objects = $this->objectEntityMapper->findAll(limit: $limit, offset: $offset, filters: $filters, sort: $order, search: $search, uses: $id);
                    $total   = $this->objectEntityMapper->countAll(filters: $filters);
                    $pages   = null !== $limit ? ceil($total / $limit) : 1;

            // $facets  = $this->getAggregations(
            // filters: $filters,
            // search: $search
            // );        return [
                    'results' => $objects,
            // 'facets' => $facets,
                    'total'   => $total,
                    'page'    => $page ?? 1,
                    'pages'   => $pages,
                    ];
                }//end getPaginatedUses()


        /**
         * Sets default values for an object based upon its schema.
         *
         * @param ObjectEntity $objectEntity the object to set default values in
         *
         * @return ObjectEntity the resulting objectEntity
         *
         * @throws \Twig\Error\LoaderError
         * @throws \Twig\Error\SyntaxError
         */
                public function setDefaults(ObjectEntity $objectEntity): ObjectEntity
                {
                    $data   = $objectEntity->jsonSerialize();
                    $schema = $this->schemaMapper->find($objectEntity->getSchema());

                    if (null === $schema->getProperties()) {
                        return $objectEntity;
                    }

                    foreach ($schema->getProperties() as $name => $property) {
                        // Check if the property doesn't exist in the data and has a default value defined
                        if (
                            false === isset($data[$name])
                            && true === isset($property['default'])
                        ) {
                            // Create a template from the default value and render it with the object's data.
                            $data[$name] = $this->twig->createTemplate(
                                $property['default'],
                                "{$schema->getTitle()}.$name"
                            )->render($objectEntity->getObjectArray());
                        }
                    }

                    $objectEntity->setObject($data);

                    return $objectEntity;
                }//end setDefaults()


        /**
         * Lock an object.
         *
         * @param string|integer $identifier Object ID, UUID, or URI
         * @param string|null    $process    Optional process identifier
         * @param integer|null   $duration   Lock duration in seconds (default: 1 hour)
         *
         * @return ObjectEntity The locked object
         *
         * @throws NotFoundException      If object not found
         * @throws NotAuthorizedException If user not authorized
         * @throws LockedException        If object already locked by another user
         */
                public function lockObject($identifier, ?string $process=null, ?int $duration=3600): ObjectEntity
                {
                    try {
                        return $this->objectEntityMapper->lockObject(
                            $identifier,
                            $process,
                            $duration
                        );
                    } catch (DoesNotExistException $e) {
                        throw new NotFoundException('Object not found');
                    } catch (\Exception $e) {
                        if (true === str_contains($e->getMessage(), 'Must be logged in')) {
                            throw new NotAuthorizedException($e->getMessage());
                        }

                        throw new LockedException($e->getMessage());
                    }
                }//end lockObject()


        /**
         * Unlock an object.
         *
         * @param string|integer $identifier Object ID, UUID, or URI
         *
         * @return ObjectEntity The unlocked object
         *
         * @throws NotFoundException      If object not found
         * @throws NotAuthorizedException If user not authorized
         * @throws LockedException        If object locked by another user
         */
                public function unlockObject($identifier): ObjectEntity
                {
                    try {
                        return $this->objectEntityMapper->unlockObject($identifier);
                    } catch (DoesNotExistException $e) {
                        throw new NotFoundException('Object not found');
                    } catch (\Exception $e) {
                        if (true === str_contains($e->getMessage(), 'Must be logged in')) {
                            throw new NotAuthorizedException($e->getMessage());
                        }

                        throw new LockedException($e->getMessage());
                    }
                }//end unlockObject()


        /**
         * Check if an object is locked.
         *
         * @param string|integer $identifier Object ID, UUID, or URI
         *
         * @return boolean True if object is locked, false otherwise
         *
         * @throws NotFoundException If object not found
         */
                public function isLocked($identifier): bool
                {
                    try {
                        return $this->objectEntityMapper->isObjectLocked($identifier);
                    } catch (DoesNotExistException $e) {
                        throw new NotFoundException('Object not found');
                    }
                }//end isLocked()


        /**
         * Revert an object to a previous state.
         *
         * @param string|integer        $identifier       Object ID, UUID, or URI
         * @param \DateTime|string|null $until            DateTime or AuditTrail ID to revert to
         * @param boolean               $overwriteVersion Whether to overwrite the version or increment it
         *
         * @return ObjectEntity The reverted object
         *
         * @throws NotFoundException      If object not found
         * @throws NotAuthorizedException If user not authorized
         * @throws \Exception             If revert fails
         */
                public function revertObject($identifier, $until=null, bool $overwriteVersion=false): ObjectEntity
                {
                    try {
                        // Get the reverted object (unsaved).
                        $revertedObject = $this->auditTrailMapper->revertObject(
                            $identifier,
                            $until,
                            $overwriteVersion
                        );

                        // Save the reverted object.
                        $revertedObject = $this->objectEntityMapper->update($revertedObject);

                        // Dispatch revert event.
                        $this->eventDispatcher->dispatch(
                            ObjectRevertedEvent::class,
                            new ObjectRevertedEvent($revertedObject, $until)
                        );

                        return $revertedObject;
                    } catch (DoesNotExistException $e) {
                        throw new NotFoundException('Object not found');
                    } catch (\Exception $e) {
                        if (true === str_contains($e->getMessage(), 'Must be logged in')) {
                            throw new NotAuthorizedException($e->getMessage());
                        }

                        throw $e;
                    }//end try
                }//end revertObject()


            }//end extendEntity()


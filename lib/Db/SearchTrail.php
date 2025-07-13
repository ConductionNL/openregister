<?php
/**
 * OpenRegister Search Trail
 *
 * This file contains the class for handling search trail related operations
 * in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity class representing a Search Trail entry
 *
 * Manages search trail data and operations for tracking search queries
 * and their results for analytics and optimization purposes.
 *
 * @package OCA\OpenRegister\Db
 */
class SearchTrail extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the search trail entry
     *
     * @var string|null Unique identifier for the search trail entry
     */
    protected ?string $uuid = null;

    /**
     * The search term used in the query
     *
     * @var string|null The search term used in the _search parameter
     */
    protected ?string $searchTerm = null;

    /**
     * The query parameters used in the search (excluding system parameters)
     *
     * @var array|null The query parameters used in the search, stored as JSON
     */
    protected ?array $queryParameters = null;

    /**
     * The number of results returned for this search
     *
     * @var integer|null The number of results returned for this search
     */
    protected ?int $resultCount = null;

    /**
     * The total number of matching results (before pagination)
     *
     * @var integer|null The total number of matching results
     */
    protected ?int $totalResults = null;

    /**
     * Register ID associated with the search
     *
     * @var integer|null Register ID associated with the search
     */
    protected ?int $register = null;

    /**
     * Schema ID associated with the search
     *
     * @var integer|null Schema ID associated with the search
     */
    protected ?int $schema = null;

    /**
     * UUID of the register associated with the search
     *
     * @var string|null UUID of the register associated with the search
     */
    protected ?string $registerUuid = null;

    /**
     * UUID of the schema associated with the search
     *
     * @var string|null UUID of the schema associated with the search
     */
    protected ?string $schemaUuid = null;

    /**
     * User ID associated with the search
     *
     * @var string|null User ID associated with the search
     */
    protected ?string $user = null;

    /**
     * Username associated with the search
     *
     * @var string|null Username associated with the search
     */
    protected ?string $userName = null;

    /**
     * Register name associated with the search
     *
     * @var string|null Register name associated with the search
     */
    protected ?string $registerName = null;

    /**
     * Schema name associated with the search
     *
     * @var string|null Schema name associated with the search
     */
    protected ?string $schemaName = null;

    /**
     * Session ID associated with the search
     *
     * @var string|null Session ID associated with the search
     */
    protected ?string $session = null;

    /**
     * IP address from which the search was performed
     *
     * @var string|null IP address from which the search was performed
     */
    protected ?string $ipAddress = null;

    /**
     * User agent string from the browser/client
     *
     * @var string|null User agent string from the browser/client
     */
    protected ?string $userAgent = null;

    /**
     * The request URI that was used for the search
     *
     * @var string|null The request URI that was used for the search
     */
    protected ?string $requestUri = null;

    /**
     * The HTTP method used for the search request
     *
     * @var string|null The HTTP method used for the search request
     */
    protected ?string $httpMethod = null;

    /**
     * Response time for the search in milliseconds
     *
     * @var integer|null Response time for the search in milliseconds
     */
    protected ?int $responseTime = null;

    /**
     * The page number requested (if pagination was used)
     *
     * @var integer|null The page number requested
     */
    protected ?int $page = null;

    /**
     * The limit parameter used for pagination
     *
     * @var integer|null The limit parameter used for pagination
     */
    protected ?int $limit = null;

    /**
     * The offset parameter used for pagination
     *
     * @var integer|null The offset parameter used for pagination
     */
    protected ?int $offset = null;

    /**
     * Whether facets were requested in the search
     *
     * @var boolean|null Whether facets were requested in the search
     */
    protected ?bool $facetsRequested = null;

    /**
     * Whether facetable field discovery was requested
     *
     * @var boolean|null Whether facetable field discovery was requested
     */
    protected ?bool $facetableRequested = null;

    /**
     * Search filters applied (excluding system parameters)
     *
     * @var array|null Search filters applied, stored as JSON
     */
    protected ?array $filters = null;

    /**
     * Sort parameters applied to the search
     *
     * @var array|null Sort parameters applied to the search, stored as JSON
     */
    protected ?array $sortParameters = null;

    /**
     * Whether the search was performed on published objects only
     *
     * @var boolean|null Whether the search was performed on published objects only
     */
    protected ?bool $publishedOnly = null;

    /**
     * Search execution type (sync or async)
     *
     * @var string|null Search execution type (sync or async)
     */
    protected ?string $executionType = null;

    /**
     * Creation timestamp of the search trail entry
     *
     * @var DateTime|null Creation timestamp of the search trail entry
     */
    protected ?DateTime $created = null;

    /**
     * The unique identifier of the organization processing personal data
     *
     * @var string|null The unique identifier of the organization processing personal data
     */
    protected ?string $organisationId = null;

    /**
     * The type of organization identifier used
     *
     * @var string|null The type of organization identifier used
     */
    protected ?string $organisationIdType = null;

    /**
     * The expiration timestamp for this search trail entry
     *
     * @var DateTime|null The expiration timestamp for this search trail entry
     */
    protected ?DateTime $expires = null;


    /**
     * Constructor for the SearchTrail class
     *
     * Sets up field types for all properties
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'searchTerm', type: 'string');
        $this->addType(fieldName: 'queryParameters', type: 'json');
        $this->addType(fieldName: 'resultCount', type: 'integer');
        $this->addType(fieldName: 'totalResults', type: 'integer');
        $this->addType(fieldName: 'register', type: 'integer');
        $this->addType(fieldName: 'schema', type: 'integer');
        $this->addType(fieldName: 'registerUuid', type: 'string');
        $this->addType(fieldName: 'schemaUuid', type: 'string');
        $this->addType(fieldName: 'user', type: 'string');
        $this->addType(fieldName: 'userName', type: 'string');
        $this->addType(fieldName: 'registerName', type: 'string');
        $this->addType(fieldName: 'schemaName', type: 'string');
        $this->addType(fieldName: 'session', type: 'string');
        $this->addType(fieldName: 'ipAddress', type: 'string');
        $this->addType(fieldName: 'userAgent', type: 'string');
        $this->addType(fieldName: 'requestUri', type: 'string');
        $this->addType(fieldName: 'httpMethod', type: 'string');
        $this->addType(fieldName: 'responseTime', type: 'integer');
        $this->addType(fieldName: 'page', type: 'integer');
        $this->addType(fieldName: 'limit', type: 'integer');
        $this->addType(fieldName: 'offset', type: 'integer');
        $this->addType(fieldName: 'facetsRequested', type: 'boolean');
        $this->addType(fieldName: 'facetableRequested', type: 'boolean');
        $this->addType(fieldName: 'filters', type: 'json');
        $this->addType(fieldName: 'sortParameters', type: 'json');
        $this->addType(fieldName: 'publishedOnly', type: 'boolean');
        $this->addType(fieldName: 'executionType', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'organisationId', type: 'string');
        $this->addType(fieldName: 'organisationIdType', type: 'string');
        $this->addType(fieldName: 'expires', type: 'datetime');

    }//end __construct()


    /**
     * Get the query parameters
     *
     * @return array The query parameters or empty array if null
     */
    public function getQueryParameters(): array
    {
        return ($this->queryParameters ?? []);

    }//end getQueryParameters()


    /**
     * Get the filters
     *
     * @return array The filters or empty array if null
     */
    public function getFilters(): array
    {
        return ($this->filters ?? []);

    }//end getFilters()


    /**
     * Get the sort parameters
     *
     * @return array The sort parameters or empty array if null
     */
    public function getSortParameters(): array
    {
        return ($this->sortParameters ?? []);

    }//end getSortParameters()


    /**
     * Get JSON fields from the entity
     *
     * Returns all fields that are of type 'json'
     *
     * @return array<string> List of JSON field names
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                $this->getFieldTypes(),
                function ($field) {
                    return $field === 'json';
                }
            )
        );

    }//end getJsonFields()


    /**
     * Hydrate the entity with data from an array
     *
     * Sets entity properties based on input array values
     *
     * @param array $object The data array to hydrate from
     *
     * @return self Returns $this for method chaining
     */
    public function hydrate(array $object): self
    {
        $jsonFields = $this->getJsonFields();

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = null;
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Silently ignore invalid properties.
            }
        }

        return $this;

    }//end hydrate()


    /**
     * Set the register name
     *
     * @param string|null $registerName The register name
     *
     * @return void
     */
    public function setRegisterName(?string $registerName): void
    {
        $this->registerName = $registerName;

    }//end setRegisterName()


    /**
     * Set the schema name
     *
     * @param string|null $schemaName The schema name
     *
     * @return void
     */
    public function setSchemaName(?string $schemaName): void
    {
        $this->schemaName = $schemaName;

    }//end setSchemaName()


    /**
     * Convert entity to JSON serializable array
     *
     * Prepares the entity data for JSON serialization
     *
     * @return array<string, mixed> Array of serializable entity data
     */
    public function jsonSerialize(): array
    {
        $created = null;
        if (isset($this->created) === true) {
            $created = $this->created->format('c');
        }

        $expires = null;
        if (isset($this->expires) === true) {
            $expires = $this->expires->format('c');
        }

        return [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'searchTerm'         => $this->searchTerm,
            'queryParameters'    => $this->queryParameters,
            'resultCount'        => $this->resultCount,
            'totalResults'       => $this->totalResults,
            'register'           => $this->register,
            'schema'             => $this->schema,
            'registerUuid'       => $this->registerUuid,
            'schemaUuid'         => $this->schemaUuid,
            'user'               => $this->user,
            'userName'           => $this->userName,
            'registerName'       => $this->registerName,
            'schemaName'         => $this->schemaName,
            'session'            => $this->session,
            'ipAddress'          => $this->ipAddress,
            'userAgent'          => $this->userAgent,
            'requestUri'         => $this->requestUri,
            'httpMethod'         => $this->httpMethod,
            'responseTime'       => $this->responseTime,
            'page'               => $this->page,
            'limit'              => $this->limit,
            'offset'             => $this->offset,
            'facetsRequested'    => $this->facetsRequested,
            'facetableRequested' => $this->facetableRequested,
            'filters'            => $this->filters,
            'sortParameters'     => $this->sortParameters,
            'publishedOnly'      => $this->publishedOnly,
            'executionType'      => $this->executionType,
            'created'            => $created,
            'organisationId'     => $this->organisationId,
            'organisationIdType' => $this->organisationIdType,
            'expires'            => $expires,
        ];

    }//end jsonSerialize()


}//end class
 
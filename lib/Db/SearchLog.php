<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class SearchLog
 * 
 * Entity for storing search log data including filters, terms, and results
 * 
 * @package OCA\OpenRegister\Db
 */
class SearchLog extends Entity implements JsonSerializable
{
    /** @var string|null UUID of the search log entry */
    protected ?string $uuid = null;
    
    /** @var int|null Schema ID that was searched */
    protected ?int $schema = null;
    
    /** @var int|null Register ID that was searched */
    protected ?int $register = null;
    
    /** @var array|null Applied filters in the search */
    protected ?array $filters = null;
    
    /** @var array|null Search terms used */
    protected ?array $terms = null;
    
    /** @var int|null Number of results returned */
    protected ?int $resultCount = null;
    
    /** @var string|null User who performed the search */
    protected ?string $user = null;
    
    /** @var string|null Display name of the user */
    protected ?string $userName = null;
    
    /** @var string|null Session ID */
    protected ?string $session = null;
    
    /** @var string|null Request ID */
    protected ?string $request = null;
    
    /** @var string|null IP address of the requester */
    protected ?string $ipAddress = null;
    
    /** @var DateTime|null When the search was performed */
    protected ?DateTime $created = null;

    /**
     * Constructor to set up field types
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('schema', 'integer');
        $this->addType('register', 'integer');
        $this->addType('filters', 'json');
        $this->addType('terms', 'json');
        $this->addType('resultCount', 'integer');
        $this->addType('user', 'string');
        $this->addType('userName', 'string');
        $this->addType('session', 'string');
        $this->addType('request', 'string');
        $this->addType('ipAddress', 'string');
        $this->addType('created', 'datetime');
    }

    /**
     * Convert the entity to JSON
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'schema' => $this->schema,
            'register' => $this->register,
            'filters' => $this->filters,
            'terms' => $this->terms,
            'resultCount' => $this->resultCount,
            'user' => $this->user,
            'userName' => $this->userName,
            'session' => $this->session,
            'request' => $this->request,
            'ipAddress' => $this->ipAddress,
            'created' => $this->created ? $this->created->format('c') : null
        ];
    }
} 
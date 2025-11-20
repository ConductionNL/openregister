<?php
/**
 * OpenRegister Agent Entity
 *
 * This file contains the Agent entity class for the OpenRegister application.
 * Agents represent AI agents that can perform automated tasks, chat interactions,
 * and intelligent data processing.
 *
 * @category Entity
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * Agent entity class
 *
 * Represents an AI agent within the system.
 * Agents can perform automated tasks, chat interactions, and intelligent data processing
 * using Large Language Models (LLMs).
 *
 * Uses Nextcloud's Entity magic getters/setters for all simple properties.
 * Only methods with custom logic are explicitly defined.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getProvider()
 * @method void setProvider(?string $provider)
 * @method string|null getModel()
 * @method void setModel(?string $model)
 * @method string|null getPrompt()
 * @method void setPrompt(?string $prompt)
 * @method float|null getTemperature()
 * @method void setTemperature(?float $temperature)
 * @method int|null getMaxTokens()
 * @method void setMaxTokens(?int $maxTokens)
 * @method array|null getConfiguration()
 * @method void setConfiguration(?array $configuration)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method bool getEnableRag()
 * @method void setEnableRag(bool $enableRag)
 * @method string|null getRagSearchMode()
 * @method void setRagSearchMode(?string $ragSearchMode)
 * @method int|null getRagNumSources()
 * @method void setRagNumSources(?int $ragNumSources)
 * @method bool getRagIncludeFiles()
 * @method void setRagIncludeFiles(bool $ragIncludeFiles)
 * @method bool getRagIncludeObjects()
 * @method void setRagIncludeObjects(bool $ragIncludeObjects)
 * @method int|null getRequestQuota()
 * @method void setRequestQuota(?int $requestQuota)
 * @method int|null getTokenQuota()
 * @method void setTokenQuota(?int $tokenQuota)
 * @method array|null getViews()
 * @method void setViews(?array $views)
 * @method bool|null getSearchFiles()
 * @method void setSearchFiles(?bool $searchFiles)
 * @method bool|null getSearchObjects()
 * @method void setSearchObjects(?bool $searchObjects)
 * @method bool|null getIsPrivate()
 * @method void setIsPrivate(?bool $isPrivate)
 * @method array|null getInvitedUsers()
 * @method void setInvitedUsers(?array $invitedUsers)
 * @method array|null getGroups()
 * @method void setGroups(?array $groups)
 * @method array|null getTools()
 * @method void setTools(?array $tools)
 * @method string|null getUser()
 * @method void setUser(?string $user)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @package OCA\OpenRegister\Db
 */
class Agent extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the agent
     *
     * @var string|null UUID of the agent
     */
    protected ?string $uuid = null;

    /**
     * Name of the agent
     *
     * @var string|null The agent name
     */
    protected ?string $name = null;

    /**
     * Description of the agent
     *
     * @var string|null The agent description
     */
    protected ?string $description = null;

    /**
     * Type of agent (e.g., 'chat', 'automation', 'analysis', 'assistant')
     *
     * @var string|null Agent type
     */
    protected ?string $type = null;

    /**
     * LLM provider (e.g., 'openai', 'ollama', 'fireworks', 'azure')
     *
     * @var string|null Provider name
     */
    protected ?string $provider = null;

    /**
     * Model identifier (e.g., 'gpt-4o-mini', 'llama3')
     *
     * @var string|null Model name
     */
    protected ?string $model = null;

    /**
     * System prompt for the agent
     *
     * @var string|null Instructions and context for the AI
     */
    protected ?string $prompt = null;

    /**
     * Temperature setting for response generation (0.0 - 2.0)
     *
     * @var float|null Controls randomness in responses
     */
    protected ?float $temperature = null;

    /**
     * Maximum tokens to generate in responses
     *
     * @var integer|null Token limit
     */
    protected ?int $maxTokens = null;

    /**
     * Additional configuration settings
     *
     * @var array|null JSON configuration for advanced settings
     */
    protected ?array $configuration = null;

    /**
     * Organisation UUID that owns this agent
     *
     * @var string|null Organisation UUID
     */
    protected ?string $organisation = null;

    /**
     * Configuration that manages this agent (transient, not stored in DB)
     *
     * @var Configuration|null
     */
    private ?Configuration $managedByConfiguration = null;

    /**
     * Owner user ID
     *
     * @var string|null User ID of the owner
     */
    protected ?string $owner = null;

    /**
     * Whether the agent is active
     *
     * @var boolean Active status
     */
    protected bool $active = true;

    /**
     * Enable RAG (Retrieval-Augmented Generation)
     *
     * @var boolean Whether to use RAG for context retrieval
     */
    protected bool $enableRag = false;

    /**
     * RAG search mode (hybrid, semantic, keyword)
     *
     * @var string|null Search mode for RAG
     */
    protected ?string $ragSearchMode = null;

    /**
     * Number of sources to retrieve for RAG
     *
     * @var integer|null Number of context sources
     */
    protected ?int $ragNumSources = null;

    /**
     * Include files in RAG search
     *
     * @var boolean Whether to search files
     */
    protected bool $ragIncludeFiles = false;

    /**
     * Include objects in RAG search
     *
     * @var boolean Whether to search objects
     */
    protected bool $ragIncludeObjects = false;

    /**
     * API request quota per day (0 = unlimited)
     *
     * @var integer|null Maximum requests per day
     */
    protected ?int $requestQuota = null;

    /**
     * Token quota per day (0 = unlimited)
     *
     * @var integer|null Maximum tokens per day
     */
    protected ?int $tokenQuota = null;

    /**
     * Array of view UUIDs that filter which data the agent can access
     *
     * @var array|null View UUIDs for filtering
     */
    protected ?array $views = null;

    /**
     * Whether to search in files (Nextcloud files)
     *
     * @var boolean|null Search in files flag
     */
    protected ?bool $searchFiles = null;

    /**
     * Whether to search in objects (OpenRegister objects)
     *
     * @var boolean|null Search in objects flag
     */
    protected ?bool $searchObjects = null;

    /**
     * Whether agent is private (not shared with organization)
     *
     * @var boolean|null Private flag
     */
    protected ?bool $isPrivate = null;

    /**
     * Array of user IDs with access to private agent
     *
     * Only relevant when isPrivate is true.
     *
     * @var array|null Array of user IDs
     */
    protected ?array $invitedUsers = null;

    /**
     * Array of Nextcloud group IDs with access to this agent
     *
     * @var array|null Group IDs
     */
    protected ?array $groups = null;

    /**
     * Array of enabled tool names for function calling
     *
     * Available tools: 'register', 'schema', 'objects'
     * Example: ['register', 'objects']
     *
     * @var array|null Tool names
     */
    protected ?array $tools = null;

    /**
     * User ID for running agent in cron/background scenarios
     *
     * When no session exists (e.g., cron jobs), this user's context
     * will be used for permissions and organization filtering.
     *
     * @var string|null User ID
     */
    protected ?string $user = null;

    /**
     * Date when the agent was created
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = null;

    /**
     * Date when the agent was last updated
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = null;


    /**
     * Agent constructor
     *
     * Sets up the entity type mappings for proper database handling.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('type', 'string');
        $this->addType('provider', 'string');
        $this->addType('model', 'string');
        $this->addType('prompt', 'string');
        $this->addType('temperature', 'float');
        $this->addType('maxTokens', 'integer');
        $this->addType('configuration', 'json');
        $this->addType('organisation', 'string');
        $this->addType('owner', 'string');
        $this->addType('active', 'boolean');
        $this->addType('enableRag', 'boolean');
        $this->addType('ragSearchMode', 'string');
        $this->addType('ragNumSources', 'integer');
        $this->addType('ragIncludeFiles', 'boolean');
        $this->addType('ragIncludeObjects', 'boolean');
        $this->addType('requestQuota', 'integer');
        $this->addType('tokenQuota', 'integer');
        $this->addType('views', 'json');
        $this->addType('searchFiles', 'boolean');
        $this->addType('searchObjects', 'boolean');
        $this->addType('isPrivate', 'boolean');
        $this->addType('invitedUsers', 'json');
        $this->addType('groups', 'json');
        $this->addType('tools', 'json');
        $this->addType('user', 'string');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');

    }//end __construct()


    /**
     * Validate UUID format
     *
     * @param string $uuid The UUID to validate
     *
     * @return bool True if UUID format is valid
     */
    public static function isValidUuid(string $uuid): bool
    {
        try {
            Uuid::fromString($uuid);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }

    }//end isValidUuid()


    /**
     * Check if a user is invited to access this private agent
     *
     * @param string $userId The user ID to check
     *
     * @return bool True if user is invited
     */
    public function hasInvitedUser(string $userId): bool
    {
        if ($this->invitedUsers === null) {
            return false;
        }

        return in_array($userId, $this->invitedUsers, true);

    }//end hasInvitedUser()


    /**
     * Add a user to the invited users list
     *
     * @param string $userId The user ID to add
     *
     * @return void
     */
    public function addInvitedUser(string $userId): void
    {
        if ($this->invitedUsers === null) {
            $this->invitedUsers = [];
        }

        if (in_array($userId, $this->invitedUsers, true) === false) {
            $this->invitedUsers[] = $userId;
            $this->markFieldUpdated('invitedUsers');
        }

    }//end addInvitedUser()


    /**
     * Remove a user from the invited users list
     *
     * @param string $userId The user ID to remove
     *
     * @return void
     */
    public function removeInvitedUser(string $userId): void
    {
        if ($this->invitedUsers === null) {
            return;
        }

        $key = array_search($userId, $this->invitedUsers, true);
        if ($key !== false) {
            unset($this->invitedUsers[$key]);
            $this->invitedUsers = array_values($this->invitedUsers);
            $this->markFieldUpdated('invitedUsers');
        }

    }//end removeInvitedUser()


    /**
     * Hydrate the entity from an array
     *
     * @param array<string, mixed> $object The data to hydrate from
     *
     * @return static The hydrated entity
     */
    public function hydrate(array $object): static
    {
        // Set UUID - generate if not provided.
        if (isset($object['uuid']) === true && empty($object['uuid']) === false) {
            $this->setUuid($object['uuid']);
        } else {
            // Generate new UUID if not provided.
            $this->setUuid(Uuid::v4()->toRfc4122());
        }

        $this->setName($object['name'] ?? null);
        $this->setDescription($object['description'] ?? null);
        $this->setType($object['type'] ?? null);
        $this->setProvider($object['provider'] ?? null);
        $this->setModel($object['model'] ?? null);
        $this->setPrompt($object['prompt'] ?? null);
        $this->setTemperature($object['temperature'] ?? null);
        $this->setMaxTokens($object['maxTokens'] ?? $object['max_tokens'] ?? null);
        $this->setConfiguration($object['configuration'] ?? null);
        $this->setOrganisation($object['organisation'] ?? null);
        $this->setOwner($object['owner'] ?? null);
        $this->setActive($object['active'] ?? true);
        $this->setEnableRag($object['enableRag'] ?? $object['enable_rag'] ?? false);
        $this->setRagSearchMode($object['ragSearchMode'] ?? $object['rag_search_mode'] ?? null);
        $this->setRagNumSources($object['ragNumSources'] ?? $object['rag_num_sources'] ?? null);
        $this->setRagIncludeFiles($object['ragIncludeFiles'] ?? $object['rag_include_files'] ?? false);
        $this->setRagIncludeObjects($object['ragIncludeObjects'] ?? $object['rag_include_objects'] ?? false);
        $this->setRequestQuota($object['requestQuota'] ?? $object['request_quota'] ?? null);
        $this->setTokenQuota($object['tokenQuota'] ?? $object['token_quota'] ?? null);
        $this->setViews($object['views'] ?? null);
        $this->setSearchFiles($object['searchFiles'] ?? $object['search_files'] ?? true);
        $this->setSearchObjects($object['searchObjects'] ?? $object['search_objects'] ?? true);
        $this->setIsPrivate($object['isPrivate'] ?? $object['is_private'] ?? true);
        $this->setInvitedUsers($object['invitedUsers'] ?? $object['invited_users'] ?? null);
        $this->setGroups($object['groups'] ?? null);
        $this->setTools($object['tools'] ?? null);
        $this->setUser($object['user'] ?? null);

        return $this;

    }//end hydrate()


    /**
     * Serialize the entity to JSON
     *
     * @return (array|null|scalar)[] The serialized data
     *
     * @psalm-return array{id: int, uuid: null|string, name: null|string, description: null|string, type: null|string, provider: null|string, model: null|string, prompt: null|string, temperature: float|null, maxTokens: int|null, configuration: array|null, organisation: null|string, owner: null|string, active: bool, enableRag: bool, ragSearchMode: null|string, ragNumSources: int|null, ragIncludeFiles: bool, ragIncludeObjects: bool, requestQuota: int|null, tokenQuota: int|null, views: array|null, searchFiles: bool|null, searchObjects: bool|null, isPrivate: bool|null, invitedUsers: array|null, groups: array|null, tools: array|null, user: null|string, created: null|string, updated: null|string, managedByConfiguration: array|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                     => $this->id,
            'uuid'                   => $this->uuid,
            'name'                   => $this->name,
            'description'            => $this->description,
            'type'                   => $this->type,
            'provider'               => $this->provider,
            'model'                  => $this->model,
            'prompt'                 => $this->prompt,
            'temperature'            => $this->temperature,
            'maxTokens'              => $this->maxTokens,
            'configuration'          => $this->configuration,
            'organisation'           => $this->organisation,
            'owner'                  => $this->owner,
            'active'                 => $this->active,
            'enableRag'              => $this->enableRag,
            'ragSearchMode'          => $this->ragSearchMode,
            'ragNumSources'          => $this->ragNumSources,
            'ragIncludeFiles'        => $this->ragIncludeFiles,
            'ragIncludeObjects'      => $this->ragIncludeObjects,
            'requestQuota'           => $this->requestQuota,
            'tokenQuota'             => $this->tokenQuota,
            'views'                  => $this->views,
            'searchFiles'            => $this->searchFiles,
            'searchObjects'          => $this->searchObjects,
            'isPrivate'              => $this->isPrivate,
            'invitedUsers'           => $this->invitedUsers,
            'groups'                 => $this->groups,
            'tools'                  => $this->tools,
            'user'                   => $this->user,
            'created'                => $this->created?->format('Y-m-d\TH:i:s\Z'),
            'updated'                => $this->updated?->format('Y-m-d\TH:i:s\Z'),
            'managedByConfiguration' => $this->getManagedByConfigurationData(),
        ];

    }//end jsonSerialize()


    /**
     * Get the configuration that manages this agent (transient property)
     *
     * @return Configuration|null The managing configuration or null
     */
    public function getManagedByConfigurationEntity(): ?Configuration
    {
        return $this->managedByConfiguration;

    }//end getManagedByConfigurationEntity()


    /**
     * Set the configuration that manages this agent (transient property)
     *
     * @param Configuration|null $configuration The managing configuration
     *
     * @return void
     */
    public function setManagedByConfigurationEntity(?Configuration $configuration): void
    {
        $this->managedByConfiguration = $configuration;

    }//end setManagedByConfigurationEntity()


    /**
     * Check if this agent is managed by a configuration
     *
     * Returns true if this agent's ID appears in any of the provided configurations' agents arrays.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return bool True if managed by a configuration, false otherwise
     *
     * @phpstan-param array<Configuration> $configurations
     * @psalm-param   array<Configuration> $configurations
     */
    public function isManagedByConfiguration(array $configurations): bool
    {
        if (empty($configurations) === true || $this->id === null) {
            return false;
        }

        foreach ($configurations as $configuration) {
            $agents = $configuration->getAgents();
            if (in_array($this->id, $agents, true) === true) {
                return true;
            }
        }

        return false;

    }//end isManagedByConfiguration()


    /**
     * Get the configuration that manages this agent
     *
     * Returns the first configuration that has this agent's ID in its agents array.
     * Returns null if the agent is not managed by any configuration.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return Configuration|null The configuration managing this agent, or null
     *
     * @phpstan-param array<Configuration> $configurations
     * @psalm-param   array<Configuration> $configurations
     */
    public function getManagedByConfiguration(array $configurations): ?Configuration
    {
        if (empty($configurations) === true || $this->id === null) {
            return null;
        }

        foreach ($configurations as $configuration) {
            $agents = $configuration->getAgents();
            if (in_array($this->id, $agents, true) === true) {
                return $configuration;
            }
        }

        return null;

    }//end getManagedByConfiguration()


    /**
     * Get managed by configuration data for JSON serialization
     *
     * @return (int|null|string)[]|null Configuration data or null
     *
     * @psalm-return array{id: int, uuid: null|string, title: null|string}|null
     */
    private function getManagedByConfigurationData(): array|null
    {
        if ($this->managedByConfiguration !== null) {
            return [
                'id'    => $this->managedByConfiguration->getId(),
                'uuid'  => $this->managedByConfiguration->getUuid(),
                'title' => $this->managedByConfiguration->getTitle(),
            ];
        }

        return null;

    }//end getManagedByConfigurationData()


}//end class

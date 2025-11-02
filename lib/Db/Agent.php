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
     * @var int|null Token limit
     */
    protected ?int $maxTokens = null;

    /**
     * Additional configuration settings
     *
     * @var array|null JSON configuration for advanced settings
     */
    protected ?array $configuration = null;

    /**
     * Organisation ID that owns this agent
     *
     * @var int|null Foreign key to organisation
     */
    protected ?int $organisation = null;

    /**
     * Owner user ID
     *
     * @var string|null User ID of the owner
     */
    protected ?string $owner = null;

    /**
     * Whether the agent is active
     *
     * @var bool Active status
     */
    protected bool $active = true;

    /**
     * Enable RAG (Retrieval-Augmented Generation)
     *
     * @var bool Whether to use RAG for context retrieval
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
     * @var int|null Number of context sources
     */
    protected ?int $ragNumSources = null;

    /**
     * Include files in RAG search
     *
     * @var bool Whether to search files
     */
    protected bool $ragIncludeFiles = false;

    /**
     * Include objects in RAG search
     *
     * @var bool Whether to search objects
     */
    protected bool $ragIncludeObjects = false;

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
        $this->addType('organisation', 'integer');
        $this->addType('owner', 'string');
        $this->addType('active', 'boolean');
        $this->addType('enableRag', 'boolean');
        $this->addType('ragSearchMode', 'string');
        $this->addType('ragNumSources', 'integer');
        $this->addType('ragIncludeFiles', 'boolean');
        $this->addType('ragIncludeObjects', 'boolean');
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
     * Get the UUID of the agent
     *
     * @return string|null The agent UUID
     */
    public function getUuid(): ?string
    {
        return $this->uuid;

    }//end getUuid()


    /**
     * Set the UUID of the agent
     *
     * @param string|null $uuid The agent UUID
     *
     * @return void
     */
    public function setUuid(?string $uuid): void
    {
        $this->uuid = $uuid;

    }//end setUuid()


    /**
     * Get the name of the agent
     *
     * @return string|null The agent name
     */
    public function getName(): ?string
    {
        return $this->name;

    }//end getName()


    /**
     * Set the name of the agent
     *
     * @param string|null $name The agent name
     *
     * @return void
     */
    public function setName(?string $name): void
    {
        $this->name = $name;

    }//end setName()


    /**
     * Get the description of the agent
     *
     * @return string|null The agent description
     */
    public function getDescription(): ?string
    {
        return $this->description;

    }//end getDescription()


    /**
     * Set the description of the agent
     *
     * @param string|null $description The agent description
     *
     * @return void
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description;

    }//end setDescription()


    /**
     * Get the type of the agent
     *
     * @return string|null The agent type
     */
    public function getType(): ?string
    {
        return $this->type;

    }//end getType()


    /**
     * Set the type of the agent
     *
     * @param string|null $type The agent type
     *
     * @return void
     */
    public function setType(?string $type): void
    {
        $this->type = $type;

    }//end setType()


    /**
     * Get the provider of the agent
     *
     * @return string|null The provider name
     */
    public function getProvider(): ?string
    {
        return $this->provider;

    }//end getProvider()


    /**
     * Set the provider of the agent
     *
     * @param string|null $provider The provider name
     *
     * @return void
     */
    public function setProvider(?string $provider): void
    {
        $this->provider = $provider;

    }//end setProvider()


    /**
     * Get the model of the agent
     *
     * @return string|null The model name
     */
    public function getModel(): ?string
    {
        return $this->model;

    }//end getModel()


    /**
     * Set the model of the agent
     *
     * @param string|null $model The model name
     *
     * @return void
     */
    public function setModel(?string $model): void
    {
        $this->model = $model;

    }//end setModel()


    /**
     * Get the prompt of the agent
     *
     * @return string|null The system prompt
     */
    public function getPrompt(): ?string
    {
        return $this->prompt;

    }//end getPrompt()


    /**
     * Set the prompt of the agent
     *
     * @param string|null $prompt The system prompt
     *
     * @return void
     */
    public function setPrompt(?string $prompt): void
    {
        $this->prompt = $prompt;

    }//end setPrompt()


    /**
     * Get the temperature setting
     *
     * @return float|null The temperature
     */
    public function getTemperature(): ?float
    {
        return $this->temperature;

    }//end getTemperature()


    /**
     * Set the temperature setting
     *
     * @param float|null $temperature The temperature
     *
     * @return void
     */
    public function setTemperature(?float $temperature): void
    {
        $this->temperature = $temperature;

    }//end setTemperature()


    /**
     * Get the max tokens setting
     *
     * @return int|null The max tokens
     */
    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;

    }//end getMaxTokens()


    /**
     * Set the max tokens setting
     *
     * @param int|null $maxTokens The max tokens
     *
     * @return void
     */
    public function setMaxTokens(?int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;

    }//end setMaxTokens()


    /**
     * Get the configuration
     *
     * @return array|null The configuration
     */
    public function getConfiguration(): ?array
    {
        return $this->configuration;

    }//end getConfiguration()


    /**
     * Set the configuration
     *
     * @param array|null $configuration The configuration
     *
     * @return void
     */
    public function setConfiguration(?array $configuration): void
    {
        $this->configuration = $configuration;

    }//end setConfiguration()


    /**
     * Get the organisation ID
     *
     * @return int|null The organisation ID
     */
    public function getOrganisation(): ?int
    {
        return $this->organisation;

    }//end getOrganisation()


    /**
     * Set the organisation ID
     *
     * @param int|null $organisation The organisation ID
     *
     * @return void
     */
    public function setOrganisation(?int $organisation): void
    {
        $this->organisation = $organisation;

    }//end setOrganisation()


    /**
     * Get the owner user ID
     *
     * @return string|null The owner user ID
     */
    public function getOwner(): ?string
    {
        return $this->owner;

    }//end getOwner()


    /**
     * Set the owner user ID
     *
     * @param string|null $owner The owner user ID
     *
     * @return void
     */
    public function setOwner(?string $owner): void
    {
        $this->owner = $owner;

    }//end setOwner()


    /**
     * Get the active status
     *
     * @return bool The active status
     */
    public function getActive(): bool
    {
        return $this->active;

    }//end getActive()


    /**
     * Set the active status
     *
     * @param bool $active The active status
     *
     * @return void
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;

    }//end setActive()


    /**
     * Get the enable RAG setting
     *
     * @return bool The enable RAG setting
     */
    public function getEnableRag(): bool
    {
        return $this->enableRag;

    }//end getEnableRag()


    /**
     * Set the enable RAG setting
     *
     * @param bool $enableRag The enable RAG setting
     *
     * @return void
     */
    public function setEnableRag(bool $enableRag): void
    {
        $this->enableRag = $enableRag;

    }//end setEnableRag()


    /**
     * Get the RAG search mode
     *
     * @return string|null The RAG search mode
     */
    public function getRagSearchMode(): ?string
    {
        return $this->ragSearchMode;

    }//end getRagSearchMode()


    /**
     * Set the RAG search mode
     *
     * @param string|null $ragSearchMode The RAG search mode
     *
     * @return void
     */
    public function setRagSearchMode(?string $ragSearchMode): void
    {
        $this->ragSearchMode = $ragSearchMode;

    }//end setRagSearchMode()


    /**
     * Get the RAG number of sources
     *
     * @return int|null The number of sources
     */
    public function getRagNumSources(): ?int
    {
        return $this->ragNumSources;

    }//end getRagNumSources()


    /**
     * Set the RAG number of sources
     *
     * @param int|null $ragNumSources The number of sources
     *
     * @return void
     */
    public function setRagNumSources(?int $ragNumSources): void
    {
        $this->ragNumSources = $ragNumSources;

    }//end setRagNumSources()


    /**
     * Get the RAG include files setting
     *
     * @return bool The include files setting
     */
    public function getRagIncludeFiles(): bool
    {
        return $this->ragIncludeFiles;

    }//end getRagIncludeFiles()


    /**
     * Set the RAG include files setting
     *
     * @param bool $ragIncludeFiles The include files setting
     *
     * @return void
     */
    public function setRagIncludeFiles(bool $ragIncludeFiles): void
    {
        $this->ragIncludeFiles = $ragIncludeFiles;

    }//end setRagIncludeFiles()


    /**
     * Get the RAG include objects setting
     *
     * @return bool The include objects setting
     */
    public function getRagIncludeObjects(): bool
    {
        return $this->ragIncludeObjects;

    }//end getRagIncludeObjects()


    /**
     * Set the RAG include objects setting
     *
     * @param bool $ragIncludeObjects The include objects setting
     *
     * @return void
     */
    public function setRagIncludeObjects(bool $ragIncludeObjects): void
    {
        $this->ragIncludeObjects = $ragIncludeObjects;

    }//end setRagIncludeObjects()


    /**
     * Get the creation date
     *
     * @return DateTime|null The creation date
     */
    public function getCreated(): ?DateTime
    {
        return $this->created;

    }//end getCreated()


    /**
     * Set the creation date
     *
     * @param DateTime|null $created The creation date
     *
     * @return void
     */
    public function setCreated(?DateTime $created): void
    {
        $this->created = $created;

    }//end setCreated()


    /**
     * Get the last update date
     *
     * @return DateTime|null The last update date
     */
    public function getUpdated(): ?DateTime
    {
        return $this->updated;

    }//end getUpdated()


    /**
     * Set the last update date
     *
     * @param DateTime|null $updated The last update date
     *
     * @return void
     */
    public function setUpdated(?DateTime $updated): void
    {
        $this->updated = $updated;

    }//end setUpdated()


    /**
     * Hydrate the entity from an array
     *
     * @param array $object The data array
     *
     * @return $this The hydrated entity
     */
    public function hydrate(array $object): self
    {
        $this->setUuid($object['uuid'] ?? null);
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

        return $this;

    }//end hydrate()


    /**
     * Serialize the entity to JSON
     *
     * @return array The JSON-serializable array
     */
    public function jsonSerialize(): array
    {
        $array = [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'name'               => $this->name,
            'description'        => $this->description,
            'type'               => $this->type,
            'provider'           => $this->provider,
            'model'              => $this->model,
            'prompt'             => $this->prompt,
            'temperature'        => $this->temperature,
            'maxTokens'          => $this->maxTokens,
            'configuration'      => $this->configuration,
            'organisation'       => $this->organisation,
            'owner'              => $this->owner,
            'active'             => $this->active,
            'enableRag'          => $this->enableRag,
            'ragSearchMode'      => $this->ragSearchMode,
            'ragNumSources'      => $this->ragNumSources,
            'ragIncludeFiles'    => $this->ragIncludeFiles,
            'ragIncludeObjects'  => $this->ragIncludeObjects,
            'created'            => isset($this->created) === true ? $this->created->format('c') : null,
            'updated'            => isset($this->updated) === true ? $this->updated->format('c') : null,
        ];

        return array_filter($array, function ($value) {
            return $value !== null;
        });

    }//end jsonSerialize()


}//end class


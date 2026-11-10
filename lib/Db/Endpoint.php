<?php

/**
 * OpenRegister Endpoint Entity
 *
 * This file contains the class for handling endpoint entity related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
use OCA\OpenRegister\Db\Endpoint\EndpointFieldTypes;
use OCP\AppFramework\Db\Entity;
use RuntimeException;

/**
 * Class Endpoint
 *
 * Represents an API endpoint configuration entity
 *
 * @package   OCA\OpenRegister\Db
 * @category  Database
 * @author    Conduction Development Team
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.TooManyFields) Domain entity requires many fields for complete endpoint configuration
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Endpoint extends Entity implements JsonSerializable {
	use JsonFieldsTrait;
	use HydrateBySettersTrait;
	use TypedFieldsJsonTrait;

	/**
	 * Unique identifier for the endpoint.
	 *
	 * @var string|null Unique identifier for the endpoint
	 */
	protected ?string $uuid = null;

	/**
	 * Name of the endpoint.
	 *
	 * @var string|null The name of the endpoint
	 */
	protected ?string $name = null;

	/**
	 * Description of the endpoint.
	 *
	 * @var string|null The description of the endpoint
	 */
	protected ?string $description = null;

	/**
	 * Reference of the endpoint.
	 *
	 * @var string|null The reference of the endpoint
	 */
	protected ?string $reference = null;

	/**
	 * Version of the endpoint.
	 *
	 * @var string|null The version of the endpoint
	 */
	protected ?string $version = '0.0.0';

	/**
	 * The actual endpoint path e.g /api/buildings/{{id}}.
	 * An endpoint may contain parameters e.g {{id}}.
	 *
	 * @var string|null The endpoint path
	 */
	protected ?string $endpoint = null;

	/**
	 * An array representation of the endpoint.
	 * Automatically generated.
	 *
	 * @var array|null An array representation of the endpoint
	 */
	protected ?array $endpointArray = [];

	/**
	 * A regex representation of the endpoint.
	 * Automatically generated.
	 *
	 * @var string|null A regex representation of the endpoint
	 */
	protected ?string $endpointRegex = null;

	/**
	 * HTTP method for the endpoint.
	 * One of GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD.
	 * Method and endpoint combination should be unique.
	 *
	 * @var string|null The HTTP method
	 */
	protected ?string $method = null;

	/**
	 * The target type to attach this endpoint to.
	 * Should be one of: view, agent, webhook, register, schema.
	 *
	 * @var string|null The target type
	 */
	protected ?string $targetType = null;

	/**
	 * The target id to attach this endpoint to.
	 *
	 * @var string|null The target id
	 */
	protected ?string $targetId = null;

	/**
	 * Array of conditions to be applied.
	 *
	 * @var array|null Array of conditions
	 */
	protected ?array $conditions = [];

	/**
	 * Input mapping identifier.
	 *
	 * @var string|null The input mapping identifier
	 */
	protected ?string $inputMapping = null;

	/**
	 * Output mapping identifier.
	 *
	 * @var string|null The output mapping identifier
	 */
	protected ?string $outputMapping = null;

	/**
	 * Array of rules to be applied.
	 *
	 * @var array|null Array of rules
	 */
	protected ?array $rules = [];

	/**
	 * Array of configuration IDs that this endpoint belongs to.
	 *
	 * @var array|null Array of configuration IDs
	 */
	protected ?array $configurations = [];

	/**
	 * URL-friendly identifier for the endpoint.
	 *
	 * @var string|null URL-friendly slug for the endpoint
	 */
	protected ?string $slug = null;

	/**
	 * An array defining group-based permissions for CRUD actions.
	 * The keys are the CRUD actions ('create', 'read', 'update', 'delete'),
	 * and the values are arrays of group IDs that are permitted to perform that action.
	 * If an action is not present as a key, or its value is an empty array,
	 * it is assumed that all users have permission for that action.
	 *
	 * @var array<string, array<string>>|null Array of group-based permissions
	 */
	protected ?array $groups = [];

	/**
	 * Organisation associated with the endpoint.
	 *
	 * @var string|null Organisation associated with the endpoint
	 */
	protected ?string $organisation = null;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null Creation timestamp
	 */
	protected ?DateTime $created = null;

	/**
	 * Last update timestamp.
	 *
	 * @var DateTime|null Last update timestamp
	 */
	protected ?DateTime $updated = null;

	/**
	 * Initialize the entity and define field types
	 */
	public function __construct() {
		foreach (EndpointFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Get the endpoint path
	 *
	 * @return string|null The endpoint path
	 */
	public function getEndpoint(): ?string {
		return $this->endpoint;
	}//end getEndpoint()

	/**
	 * Get the endpoint array representation
	 *
	 * @return array The endpoint array or empty array if null
	 */
	public function getEndpointArray(): array {
		return $this->endpointArray ?? [];
	}//end getEndpointArray()

	/**
	 * Get the conditions array
	 *
	 * @return array The conditions or empty array if null
	 */
	public function getConditions(): array {
		return $this->conditions ?? [];
	}//end getConditions()

	/**
	 * Get the rules array
	 *
	 * @return array The rules or empty array if null
	 */
	public function getRules(): array {
		return $this->rules ?? [];
	}//end getRules()

	/**
	 * Get the groups array
	 *
	 * @return string[][] The groups or empty array if null
	 *
	 * @psalm-return array<string, list<string>>
	 */
	public function getGroups(): array {
		return $this->groups ?? [];
	}//end getGroups()

	/**
	 * Get the configurations array
	 *
	 * @return array The configurations or empty array if null
	 */
	public function getConfigurations(): array {
		return $this->configurations ?? [];
	}//end getConfigurations()

	/**
	 * Get the slug for the endpoint.
	 * If the slug is not set, generate one from the name.
	 *
	 * @return string The endpoint slug.
	 *
	 * @phpstan-return non-empty-string
	 */
	public function getSlug(): string {
		// Check if the slug is already set.
		if (empty($this->slug) === false) {
			return $this->slug;
		}

		// Generate a slug from the name if not set.
		// Convert the name to lowercase, replace spaces with hyphens, and remove non-alphanumeric characters.
		$generatedSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($this->name ?? '')));

		// Ensure the generated slug is not empty.
		if (empty($generatedSlug) === true) {
			throw new RuntimeException('Unable to generate a valid slug from the name.');
		}

		return $generatedSlug;
	}//end getSlug()

	/**
	 * The serialised values that differ from the stored ones, by field name.
	 *
	 * @return array<string, mixed>
	 */
	protected function jsonOverrides(): array {
		return [
			'endpointArray' => $this->getEndpointArray(),
			'conditions' => $this->getConditions(),
			'rules' => $this->getRules(),
			'configurations' => $this->getConfigurations(),
			'slug' => $this->getSlug(),
			'groups' => $this->getGroups(),
		];
	}//end jsonOverrides()
}//end class

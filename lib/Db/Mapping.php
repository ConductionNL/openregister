<?php

/**
 * OpenRegister Mapping Entity
 *
 * This file contains the class for handling mapping entity related operations
 * in the OpenRegister application. Mappings define how to transform data between
 * different formats using Twig templating.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCA\OpenRegister\Db\Mapping\MappingFieldTypes;
use OCA\OpenRegister\Db\Mapping\MappingSlug;
use OCP\AppFramework\Db\Entity;

/**
 * Class Mapping
 *
 * Represents a mapping configuration entity that defines how to transform data between different formats.
 *
 * @package   OCA\OpenRegister\Db
 * @category  Database
 * @author    Conduction Development Team
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getReference()
 * @method void setReference(?string $reference)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getMapping()
 * @method void setMapping(?array $mapping)
 * @method array|null getUnset()
 * @method void setUnset(?array $unset)
 * @method array|null getCast()
 * @method void setCast(?array $cast)
 * @method bool|null getPassThrough()
 * @method void setPassThrough(?bool $passThrough)
 * @method array|null getConfigurations()
 * @method void setConfigurations(?array $configurations)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress UnusedClass
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Mapping extends Entity implements JsonSerializable {
	use JsonFieldsTrait;
	use HydrateBySettersTrait;

	/**
	 * The serialised fields, in API order.
	 *
	 * @var string[]
	 */
	private const JSON_ORDER = [
		'id', 'uuid', 'name', 'description', 'version', 'reference', 'mapping', 'unset',
		'cast', 'passThrough', 'configurations', 'slug', 'organisation', 'created', 'updated',
	];

	/**
	 * Unique identifier for the mapping.
	 *
	 * @var string|null Unique identifier for the mapping
	 */
	protected ?string $uuid = null;

	/**
	 * External reference for the mapping.
	 *
	 * @var string|null External reference
	 */
	protected ?string $reference = null;

	/**
	 * Version of the mapping.
	 *
	 * @var string|null The version of the mapping (format: X.Y.Z)
	 */
	protected ?string $version = '0.0.0';

	/**
	 * Name of the mapping.
	 *
	 * @var string|null The name of the mapping
	 */
	protected ?string $name = null;

	/**
	 * Description of the mapping.
	 *
	 * @var string|null The description of the mapping
	 */
	protected ?string $description = null;

	/**
	 * The core mapping configuration.
	 * Defines how to transform data using Twig templating.
	 *
	 * @var array|null The mapping configuration
	 */
	protected ?array $mapping = [];

	/**
	 * Array of keys to remove from output.
	 *
	 * @var array|null Array of keys to unset
	 */
	protected ?array $unset = [];

	/**
	 * Type casting rules for specific fields.
	 *
	 * @var array|null Array of cast rules
	 */
	protected ?array $cast = [];

	/**
	 * Whether to include input data not explicitly mapped.
	 *
	 * @var boolean|null Pass through flag
	 */
	protected ?bool $passThrough = null;

	/**
	 * Array of configuration IDs that this mapping belongs to.
	 *
	 * @var array|null Array of configuration IDs
	 */
	protected ?array $configurations = [];

	/**
	 * URL-friendly identifier for the mapping.
	 *
	 * @var string|null URL-friendly slug for the mapping
	 */
	protected ?string $slug = null;

	/**
	 * Organisation associated with the mapping.
	 *
	 * @var string|null Organisation associated with the mapping
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
		foreach (MappingFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Get the mapping configuration
	 *
	 * @return array The mapping configuration or empty array if null
	 */
	public function getMapping(): array {
		return $this->mapping ?? [];
	}//end getMapping()

	/**
	 * Get the unset configuration
	 *
	 * @return array The unset configuration or empty array if null
	 */
	public function getUnset(): array {
		return $this->unset ?? [];
	}//end getUnset()

	/**
	 * Get the cast configuration
	 *
	 * @return array The cast configuration or empty array if null
	 */
	public function getCast(): array {
		return $this->cast ?? [];
	}//end getCast()

	/**
	 * Get the configurations array
	 *
	 * @return array The configurations or empty array if null
	 */
	public function getConfigurations(): array {
		return $this->configurations ?? [];
	}//end getConfigurations()

	/**
	 * Get the slug for the mapping.
	 * If the slug is not set, generate one from the name.
	 *
	 * @return string The mapping slug.
	 *
	 * @phpstan-return non-empty-string
	 *
	 */
	public function getSlug(): string {
		if (empty($this->slug) === false) {
			return $this->slug;
		}

		return (new MappingSlug())->generated(name: $this->name, id: $this->id);
	}//end getSlug()

	/**
	 * Serialize the entity to JSON format
	 *
	 * @return array<string, mixed>
	 *
	 * @phpstan-return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		$json = (new TypedFieldsJson())->pick(values: get_object_vars($this), names: self::JSON_ORDER);

		return array_merge(
			$json,
			[
				'mapping' => $this->getMapping(),
				'unset' => $this->getUnset(),
				'cast' => $this->getCast(),
				'configurations' => $this->getConfigurations(),
				'slug' => $this->getSlug(),
			]
		);
	}//end jsonSerialize()
}//end class

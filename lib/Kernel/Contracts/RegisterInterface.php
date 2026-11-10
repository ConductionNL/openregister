<?php
/**
 * RegisterInterface: what callers outside lib/Db need of a Register row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use Exception;
use JsonSerializable;

/**
 * A register: a named collection of schemas and the objects stored under them.
 *
 * Implemented by \OCA\OpenRegister\Db\Register. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
 * getManagedByConfiguration() stays on the entity: it returns a Db\Configuration.
 *
 * @method int getId()
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method string|null getTablePrefix()
 * @method void setTablePrefix(?string $tablePrefix)
 * @method string|null getFolder()
 * @method void setFolder(?string $folder)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getApplication()
 * @method void setApplication(?string $application)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method array|null getAuthorization()
 * @method void setAuthorization(?array $authorization)
 * @method array|null getSharedWith()
 * @method void setSharedWith(?array $sharedWith)
 * @method array|null getGroups()
 * @method void setGroups(?array $groups)
 * @method DateTime|null getDeleted()
 * @method void setDeleted(?DateTime $deleted)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method array|null getLanguages()
 * @method void setLanguages(?array $languages)
 * @method array|null getMail()
 * @method void setMail(?array $mail)
 * @method array|null getContacts()
 * @method void setContacts(?array $contacts)
 * @method array|null getNotes()
 * @method void setNotes(?array $notes)
 * @method array|null getTodos()
 * @method void setTodos(?array $todos)
 * @method array|null getCalendar()
 * @method void setCalendar(?array $calendar)
 * @method array|null getTalk()
 * @method void setTalk(?array $talk)
 * @method array|null getDeck()
 * @method void setDeck(?array $deck)
 */
interface RegisterInterface extends JsonSerializable {

	/**
	 * Get the schemas data
	 *
	 * @return array The schemas data or empty array if null
	 */
	public function getSchemas(): array;

	/**
	 * Set the schemas data
	 *
	 * @param array|string $schemas Array of schema IDs or JSON string
	 *
	 * @return static Returns self for method chaining
	 */
	public function setSchemas($schemas): static;

	/**
	 * Record that a schema belongs to this register.
	 *
	 * The `schemas` list is a BOUNDARY since register-scoped slug resolution
	 * landed: a slug it does not carry is refused with
	 * {@see \OCA\OpenRegister\Exception\SchemaNotInRegisterException}. A boundary
	 * that nothing maintains is worse than none — a write addressed by numeric id
	 * lands while the matching slug read 404s.
	 *
	 * Additive and idempotent. Existing entries are preserved VERBATIM: a
	 * normalising rewrite would silently drop a non-numeric legacy entry, and that
	 * is data loss rather than cleanup. Repair of an existing list belongs to
	 * `occ openregister:registers:relink-schemas`.
	 *
	 * Mutates the entity only; the caller persists it, so the register's own RBAC
	 * and cross-tenant checks stay on the mapper where they already are.
	 *
	 * @param int $schemaId The schema id to record.
	 *
	 * @return bool True when the list gained the id, false when it already had it.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function addSchemaId(int $schemaId): bool;

	/**
	 * Get JSON fields from the entity
	 *
	 * Returns all fields that are of type 'json'
	 *
	 * @return string[] List of JSON field names
	 *
	 * @psalm-return list<string>
	 */
	public function getJsonFields(): array;

	/**
	 * Hydrate the entity with data from an array
	 *
	 * Sets entity properties based on input array values
	 *
	 * @param array $object The data array to hydrate from
	 *
	 * @return static Returns $this for method chaining
	 */
	public function hydrate(array $object): static;

	/**
	 * Convert entity to JSON serializable array
	 *
	 * Prepares the entity data for JSON serialization
	 *
	 * @return ((int|mixed|null|string[])[]|int|null|string)[] Array of serializable entity data
	 *
	 * @psalm-return array{
	 *     id: int,
	 *     uuid: null|string,
	 *     slug: null|string,
	 *     title: null|string,
	 *     version: null|string,
	 *     description: null|string,
	 *     schemas: array<int|string>,
	 *     source: null|string,
	 *     tablePrefix: null|string,
	 *     folder: null|string,
	 *     updated: null|string,
	 *     created: null|string,
	 *     owner: null|string,
	 *     application: null|string,
	 *     organisation: null|string,
	 *     authorization: array|null,
	 *     sharedWith: array<int, string>,
	 *     groups: array<string, list<string>>,
	 *     languages: array<string>|null,
	 *     configuration: array|null,
	 *     quota: array{
	 *         storage: null,
	 *         bandwidth: null,
	 *         requests: null,
	 *         users: null,
	 *         groups: null
	 *     },
	 *     usage: array{
	 *         storage: 0,
	 *         bandwidth: 0,
	 *         requests: 0,
	 *         users: 0,
	 *         groups: int<0, max>
	 *     },
	 *     deleted: null|string
	 * }
	 */
	public function jsonSerialize(): array;

	/**
	 * String representation of the register
	 *
	 * This magic method is required for proper entity handling in Nextcloud
	 * when the framework needs to convert the object to a string.
	 *
	 * @return string String representation of the register
	 */
	public function __toString(): string;

	/**
	 * Check if this register is managed by any configuration
	 *
	 * This method checks if the register's ID is present in the registers array
	 * of any provided configuration entities.
	 *
	 * @param array<Configuration> $configurations Array of Configuration entities to check against
	 *
	 * @return bool True if this register is managed by at least one configuration
	 *
	 * @phpstan-param array<Configuration> $configurations
	 * @psalm-param   array<Configuration> $configurations
	 */
	public function isManagedByConfiguration(array $configurations): bool;

	/**
	 * Get the default language for this register.
	 *
	 * The default language is the first element in the languages array.
	 * Falls back to 'nl' if no languages are configured.
	 *
	 * @return string The default language code (BCP 47)
	 */
	public function getDefaultLanguage(): string;

	/**
	 * Check if a language is available for this register.
	 *
	 * @param string $language The language code to check
	 *
	 * @return bool True if the language is available
	 */
	public function hasLanguage(string $language): bool;

	/**
	 * Get configuration settings.
	 *
	 * @return array Configuration settings or empty array if null.
	 */
	public function getConfiguration(): array;

	/**
	 * Set configuration settings.
	 *
	 * **TYPE SAFETY**: Handle both array and JSON string inputs for database hydration.
	 * The database stores configuration as JSON strings, but we want to work with arrays in PHP.
	 *
	 * @param array|string|null $configuration Configuration settings (array or JSON string).
	 *
	 * @return void
	 */
	public function setConfiguration(array|string|null $configuration): void;

	/**
	 * Check if magic mapping is enabled for a specific schema in this register.
	 *
	 * This is the SINGLE SOURCE OF TRUTH for magic mapping checks.
	 * All other classes should delegate to this method.
	 *
	 * Supports two configuration formats:
	 * - New format: { "schemas": { "<slug>": { "magicMapping": true } } }
	 * - Legacy format: { "enableMagicMapping": true, "magicMappingSchemas": ["<slug>", "<id>"] }
	 *
	 * @param int $schemaId The schema ID to check.
	 * @param string|null $schemaSlug Optional schema slug to also check in configuration.
	 *
	 * @return bool True if magic mapping is enabled for this schema.
	 */
	public function isMagicMappingEnabledForSchema(int $schemaId, ?string $schemaSlug = null): bool;

	/**
	 * Check if auto-create table is enabled for a specific schema in this register.
	 *
	 * Supports both configuration formats (new and legacy).
	 * Note: Legacy format doesn't have per-schema autoCreateTable, so defaults to true if magicMapping is enabled.
	 *
	 * @param int $schemaId The schema ID to check.
	 * @param string|null $schemaSlug Optional schema slug to also check in configuration.
	 *
	 * @return bool True if auto-create table is enabled for this schema.
	 */
	public function isAutoCreateTableEnabledForSchema(int $schemaId, ?string $schemaSlug = null): bool;

	/**
	 * Enable magic mapping for a specific schema in this register.
	 *
	 * @param int $schemaId The schema ID.
	 * @param bool $autoCreateTable Whether to auto-create the table (default: true).
	 * @param string|null $comment Optional comment describing why magic mapping is enabled.
	 *
	 * @return static Returns self for method chaining.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Auto-create table toggle is intentional
	 */
	public function enableMagicMappingForSchema(int $schemaId, bool $autoCreateTable = true, ?string $comment = null): static;

	/**
	 * Disable magic mapping for a specific schema in this register.
	 *
	 * @param int $schemaId The schema ID.
	 *
	 * @return static Returns self for method chaining.
	 */
	public function disableMagicMappingForSchema(int $schemaId): static;

	/**
	 * Get all schema IDs that have magic mapping enabled in this register.
	 *
	 * @return int[] Array of schema IDs with magic mapping enabled.
	 *
	 * @psalm-return list<int>
	 */
	public function getSchemasWithMagicMapping(): array;
}//end interface

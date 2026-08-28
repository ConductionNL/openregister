<?php

/**
 * MigrationPack entity — a declarative source-format-to-schema mapping definition.
 *
 * Infrastructure DB state (NOT an OpenRegister object/register — ADR-001, same
 * reasoning as `ScheduledReport`/`PushSubscription`): a reusable migration/import
 * mapping definition, admin-managed, with no cross-app business meaning and no
 * audit/relation value. Backed by the `openregister_migration_packs` table.
 *
 * The full pack document (fieldMappings, transforms, defaults, skipRows,
 * idStrategy) lives in `definition` as JSON; `packSlug`/`name`/`sourceFormat`/
 * `version` are denormalised columns so packs can be listed/filtered without
 * decoding the JSON on every row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class MigrationPack
 *
 * @method string|null getPackSlug()
 * @method void setPackSlug(?string $packSlug)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getSourceFormat()
 * @method void setSourceFormat(?string $sourceFormat)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getDefinition()
 * @method void setDefinition(?string $definition)
 * @method bool|null getBuiltin()
 * @method void setBuiltin(?bool $builtin)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
class MigrationPack extends Entity implements JsonSerializable {

	/**
	 * The pack's own `id` field from its definition document (slug, unique).
	 *
	 * @var string|null
	 */
	protected ?string $packSlug = null;

	/**
	 * User-facing label.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * Source format: csv|json|excel.
	 *
	 * @var string|null
	 */
	protected ?string $sourceFormat = null;

	/**
	 * Pack definition version (semver).
	 *
	 * @var string|null
	 */
	protected ?string $version = null;

	/**
	 * The full pack document, JSON-encoded (fieldMappings, defaults, skipRows, idStrategy, ...).
	 *
	 * @var string|null
	 */
	protected ?string $definition = null;

	/**
	 * Whether this pack ships with OpenRegister as a seeded reference pack.
	 *
	 * @var boolean|null
	 */
	protected ?bool $builtin = null;

	/**
	 * The owning Nextcloud user id, or null for built-in/system-seeded packs.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;

	/**
	 * When this pack was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When this pack was last updated.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updatedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'packSlug', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'sourceFormat', type: 'string');
		$this->addType(fieldName: 'version', type: 'string');
		$this->addType(fieldName: 'definition', type: 'string');
		$this->addType(fieldName: 'builtin', type: 'boolean');
		$this->addType(fieldName: 'owner', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'updatedAt', type: 'datetime');
	}//end __construct()

	/**
	 * The decoded pack definition document, or an empty array when unset/invalid.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function getDefinitionArray(): array {
		if ($this->definition === null || $this->definition === '') {
			return [];
		}

		$decoded = json_decode($this->definition, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end getDefinitionArray()

	/**
	 * JSON serialization for the REST API (definition inlined as an object, not a string).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'packSlug' => $this->packSlug,
			'name' => $this->name,
			'sourceFormat' => $this->sourceFormat,
			'version' => $this->version,
			'definition' => $this->getDefinitionArray(),
			'builtin' => $this->builtin,
			'owner' => $this->owner,
			'createdAt' => $this->createdAt?->format(DateTime::ATOM),
			'updatedAt' => $this->updatedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class

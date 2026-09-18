<?php

/**
 * ExportProfile — a declared export, not a query string.
 *
 * A name, an ordered field set, a value mode, a format and an optional filter,
 * bound to a register and a schema. The field set is the profile's own and owes
 * nothing to any saved view's columns: the monthly aanlevering to the VNG is a
 * fixed shape, and a screen somebody rearranged must not change it.
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
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * An export profile row.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getFields()
 * @method void setFields(?string $fields)
 * @method string|null getValueMode()
 * @method void setValueMode(?string $valueMode)
 * @method string|null getFormat()
 * @method void setFormat(?string $format)
 * @method string|null getFilters()
 * @method void setFilters(?string $filters)
 * @method bool|null getWholeSet()
 * @method void setWholeSet(?bool $wholeSet)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */
class ExportProfile extends Entity implements JsonSerializable {

	/**
	 * Values written as the object holds them.
	 *
	 * @var string
	 */
	public const MODE_STORED = 'stored';

	/**
	 * Values written as a surface would show them.
	 *
	 * @var string
	 */
	public const MODE_RENDERED = 'rendered';

	/**
	 * The value modes a profile may declare.
	 *
	 * @var string[]
	 */
	public const MODES = [self::MODE_STORED, self::MODE_RENDERED];

	/**
	 * The formats a profile may declare.
	 *
	 * @var string[]
	 */
	public const FORMATS = ['csv', 'json'];

	/**
	 * The stable public identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The owning Nextcloud user id.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;

	/**
	 * The name an administrator points at in a procedure.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * One sentence saying what the profile is for.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * The register the profile exports.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The schema the profile exports, or null on a whole-set profile.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The ordered field set, JSON encoded.
	 *
	 * @var string|null
	 */
	protected ?string $fields = null;

	/**
	 * Either `stored` or `rendered`.
	 *
	 * @var string|null
	 */
	protected ?string $valueMode = null;

	/**
	 * The format the profile writes.
	 *
	 * @var string|null
	 */
	protected ?string $format = null;

	/**
	 * The optional filter map, JSON encoded.
	 *
	 * @var string|null
	 */
	protected ?string $filters = null;

	/**
	 * Whether the profile covers every schema of its register.
	 *
	 * @var boolean|null
	 */
	protected ?bool $wholeSet = null;

	/**
	 * When the profile was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the profile was last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updatedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'owner', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'fields', type: 'string');
		$this->addType(fieldName: 'valueMode', type: 'string');
		$this->addType(fieldName: 'format', type: 'string');
		$this->addType(fieldName: 'filters', type: 'string');
		$this->addType(fieldName: 'wholeSet', type: 'boolean');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'updatedAt', type: 'datetime');
	}//end __construct()

	/**
	 * The ordered field set.
	 *
	 * Order is preserved because order is the contract: a receiving system that
	 * reads by position breaks the moment the list is re-sorted.
	 *
	 * @return array<int, string> The field names, in the profile's order.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function getFieldsArray(): array {
		if ($this->fields === null || $this->fields === '') {
			return [];
		}

		$decoded = json_decode($this->fields, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, static fn ($field) => is_string($field) === true));
	}//end getFieldsArray()

	/**
	 * The decoded filter map, or an empty array when none is stored.
	 *
	 * @return array<string, mixed> The filters.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function getFiltersArray(): array {
		if ($this->filters === null || $this->filters === '') {
			return [];
		}

		$decoded = json_decode($this->filters, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end getFiltersArray()

	/**
	 * Whether this profile is the whole-dataset extract.
	 *
	 * Both halves are required, and the spec says so: no filter, and every
	 * schema of the register in scope. A filtered profile with the flag set is
	 * a report somebody mislabelled, and running it as an overnight job would
	 * be the wrong answer to it.
	 *
	 * @return bool True when the profile is a whole-set extract.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function isWholeSet(): bool {
		return ($this->wholeSet === true && $this->getFiltersArray() === []);
	}//end isWholeSet()

	/**
	 * JSON serialization.
	 *
	 * @return array<string, mixed> The published shape.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'owner' => $this->owner,
			'name' => $this->name,
			'description' => $this->description,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'fields' => $this->getFieldsArray(),
			'valueMode' => ($this->valueMode ?? self::MODE_STORED),
			'format' => ($this->format ?? 'csv'),
			'filters' => $this->getFiltersArray(),
			'wholeSet' => ($this->wholeSet ?? false),
			'createdAt' => $this->createdAt?->format(DateTime::ATOM),
			'updatedAt' => $this->updatedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class

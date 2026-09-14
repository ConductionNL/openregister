<?php

/**
 * ObjectRelation entity — a typed link that no $ref property can hold.
 *
 * Four kinds of link live here rather than in a schema property: the
 * provenance of a split, what a child inherited from its parent at creation,
 * an address outside the product, and a reference written in prose. All four
 * are relations in every sense that matters — they appear in the reverse view,
 * in the graph and in the export — and none of them fits a `$ref`.
 *
 * Labels are deliberately NOT the authority here. A row names its vocabulary
 * key and the schema resolves the key to text at read, so renaming "blocks" to
 * "blokkeert" is one schema edit rather than a backfill over every row that
 * ever used it. The `label` and `inverseLabel` columns hold only what a row
 * carries when no schema can answer, which is the external and prose cases.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ObjectRelation
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSourceUuid()
 * @method void setSourceUuid(?string $sourceUuid)
 * @method int|null getSourceRegister()
 * @method void setSourceRegister(?int $sourceRegister)
 * @method int|null getSourceSchema()
 * @method void setSourceSchema(?int $sourceSchema)
 * @method string|null getTargetUuid()
 * @method void setTargetUuid(?string $targetUuid)
 * @method int|null getTargetRegister()
 * @method void setTargetRegister(?int $targetRegister)
 * @method int|null getTargetSchema()
 * @method void setTargetSchema(?int $targetSchema)
 * @method string|null getTargetUrl()
 * @method void setTargetUrl(?string $targetUrl)
 * @method string|null getTargetTitle()
 * @method void setTargetTitle(?string $targetTitle)
 * @method string|null getKind()
 * @method void setKind(?string $kind)
 * @method string|null getRelationType()
 * @method void setRelationType(?string $relationType)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method string|null getInverseLabel()
 * @method void setInverseLabel(?string $inverseLabel)
 * @method bool getSymmetric()
 * @method void setSymmetric(bool $symmetric)
 * @method string|null getOrigin()
 * @method void setOrigin(?string $origin)
 * @method string|null getSourceEntry()
 * @method void setSourceEntry(?string $sourceEntry)
 * @method array|null getInherited()
 * @method void setInherited(?array $inherited)
 * @method string|null getAnchor()
 * @method void setAnchor(?string $anchor)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ObjectRelation extends Entity implements JsonSerializable {
	/**
	 * The row names another object in this register.
	 *
	 * @var string
	 */
	public const KIND_OBJECT = 'object';

	/**
	 * The row names an address outside the product.
	 *
	 * @var string
	 */
	public const KIND_EXTERNAL = 'external';

	/**
	 * The row records that this object was created from an entry of another.
	 *
	 * @var string
	 */
	public const ORIGIN_SPLIT = 'split';

	/**
	 * The row records a child created under a parent, with what it inherited.
	 *
	 * @var string
	 */
	public const ORIGIN_DERIVE = 'derive';

	/**
	 * The row was written by a reference resolved out of text, and is
	 * withdrawn when that text goes.
	 *
	 * @var string
	 */
	public const ORIGIN_PROSE = 'prose';

	/**
	 * Somebody added the row by hand.
	 *
	 * @var string
	 */
	public const ORIGIN_MANUAL = 'manual';

	/**
	 * The row's own identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The object the row hangs off.
	 *
	 * @var string|null
	 */
	protected ?string $sourceUuid = null;

	/**
	 * The source object's register, for the deep link and the graph node.
	 *
	 * @var integer|null
	 */
	protected ?int $sourceRegister = null;

	/**
	 * The source object's schema.
	 *
	 * @var integer|null
	 */
	protected ?int $sourceSchema = null;

	/**
	 * The object at the other end, for an `object` row.
	 *
	 * @var string|null
	 */
	protected ?string $targetUuid = null;

	/**
	 * The target object's register.
	 *
	 * @var integer|null
	 */
	protected ?int $targetRegister = null;

	/**
	 * The target object's schema.
	 *
	 * @var integer|null
	 */
	protected ?int $targetSchema = null;

	/**
	 * The address at the other end, for an `external` row.
	 *
	 * @var string|null
	 */
	protected ?string $targetUrl = null;

	/**
	 * What to call the thing at the other end.
	 *
	 * @var string|null
	 */
	protected ?string $targetTitle = null;

	/**
	 * Whether the other end is an object or an external address.
	 *
	 * @var string|null
	 */
	protected ?string $kind = self::KIND_OBJECT;

	/**
	 * The vocabulary key this row names, resolved to labels at read.
	 *
	 * @var string|null
	 */
	protected ?string $relationType = null;

	/**
	 * The near label, when no schema can answer for this row.
	 *
	 * @var string|null
	 */
	protected ?string $label = null;

	/**
	 * The far label, when no schema can answer for this row.
	 *
	 * @var string|null
	 */
	protected ?string $inverseLabel = null;

	/**
	 * Whether the row reads the same from both ends.
	 *
	 * @var boolean
	 */
	protected bool $symmetric = false;

	/**
	 * How the row came to exist.
	 *
	 * @var string|null
	 */
	protected ?string $origin = self::ORIGIN_MANUAL;

	/**
	 * The entry of the source object this row came out of, for a split.
	 *
	 * This is the column zammad does not have, and the reason the whole row
	 * exists for a split: without it "why does this zaak exist" has no answer
	 * once the person who split it has moved on.
	 *
	 * @var string|null
	 */
	protected ?string $sourceEntry = null;

	/**
	 * What the child took from the parent at creation, as role to value.
	 *
	 * Kept as written, at creation. A later change to the parent does not
	 * touch it, which is the point: reclassifying a parent is an access change,
	 * and one that silently reached its children would be an access change
	 * nobody authorised.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $inherited = null;

	/**
	 * The text anchor a prose reference came from.
	 *
	 * @var string|null
	 */
	protected ?string $anchor = null;

	/**
	 * Who wrote the row.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Last-update timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'sourceUuid', type: 'string');
		$this->addType(fieldName: 'sourceRegister', type: 'integer');
		$this->addType(fieldName: 'sourceSchema', type: 'integer');
		$this->addType(fieldName: 'targetUuid', type: 'string');
		$this->addType(fieldName: 'targetRegister', type: 'integer');
		$this->addType(fieldName: 'targetSchema', type: 'integer');
		$this->addType(fieldName: 'targetUrl', type: 'string');
		$this->addType(fieldName: 'targetTitle', type: 'string');
		$this->addType(fieldName: 'kind', type: 'string');
		$this->addType(fieldName: 'relationType', type: 'string');
		$this->addType(fieldName: 'label', type: 'string');
		$this->addType(fieldName: 'inverseLabel', type: 'string');
		$this->addType(fieldName: 'symmetric', type: 'boolean');
		$this->addType(fieldName: 'origin', type: 'string');
		$this->addType(fieldName: 'sourceEntry', type: 'string');
		$this->addType(fieldName: 'inherited', type: 'json');
		$this->addType(fieldName: 'anchor', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * Hydrate the entity from an array.
	 *
	 * @param array<string, mixed> $object The source data.
	 *
	 * @return static This entity, hydrated.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function hydrate(array $object): static {
		foreach ($object as $key => $value) {
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
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised relation row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'sourceUuid' => $this->sourceUuid,
			'sourceRegister' => $this->sourceRegister,
			'sourceSchema' => $this->sourceSchema,
			'targetUuid' => $this->targetUuid,
			'targetRegister' => $this->targetRegister,
			'targetSchema' => $this->targetSchema,
			'targetUrl' => $this->targetUrl,
			'targetTitle' => $this->targetTitle,
			'kind' => $this->kind,
			'relationType' => $this->relationType,
			'label' => $this->label,
			'inverseLabel' => $this->inverseLabel,
			'symmetric' => $this->symmetric,
			'origin' => $this->origin,
			'sourceEntry' => $this->sourceEntry,
			'inherited' => $this->inherited,
			'anchor' => $this->anchor,
			'createdBy' => $this->createdBy,
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class

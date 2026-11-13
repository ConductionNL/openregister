<?php

/**
 * DraftVersion entity — one named draft version of an object.
 *
 * A draft is a DELTA beside the live object, never a second object (design D-1):
 * it carries only the fields it changes, the object version it was taken against
 * (`baseVersion`, for conflict detection on promotion) and who made it. The live
 * object is not touched until the draft is promoted.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use DateTimeInterface;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class DraftVersion
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getObjectRegister()
 * @method void setObjectRegister(?string $objectRegister)
 * @method string|null getObjectSchema()
 * @method void setObjectSchema(?string $objectSchema)
 * @method string|null getDraftKey()
 * @method void setDraftKey(?string $draftKey)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method string|null getBaseVersion()
 * @method void setBaseVersion(?string $baseVersion)
 * @method array|null getDelta()
 * @method void setDelta(?array $delta)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */
class DraftVersion extends Entity implements JsonSerializable {

	/**
	 * Stable UUID of the draft.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * UUID of the object the draft belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The object's register id.
	 *
	 * @var string|null
	 */
	protected ?string $objectRegister = null;

	/**
	 * The object's schema id.
	 *
	 * @var string|null
	 */
	protected ?string $objectSchema = null;

	/**
	 * The draft key, unique per object; `main` is reserved for the published version.
	 *
	 * @var string|null
	 */
	protected ?string $draftKey = null;

	/**
	 * A human-readable name.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * The user who made the draft.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * The object's version when the draft was made.
	 *
	 * @var string|null
	 */
	protected ?string $baseVersion = null;

	/**
	 * The changed fields only, keyed by property name.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $delta = null;

	/**
	 * When the draft was made.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * When the draft last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Register the column types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'objectRegister', type: 'string');
		$this->addType(fieldName: 'objectSchema', type: 'string');
		$this->addType(fieldName: 'draftKey', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'baseVersion', type: 'string');
		$this->addType(fieldName: 'delta', type: 'json');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
	}//end __construct()

	/**
	 * The draft as the versions list shows it: key, name, creator, dates, base and the changed fields.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/records-draft-versions/design.md
	 */
	public function jsonSerialize(): array {
		$delta = ($this->delta ?? []);

		return [
			'uuid'          => $this->uuid,
			'object'        => $this->objectUuid,
			'key'           => $this->draftKey,
			'name'          => $this->name,
			'createdBy'     => $this->createdBy,
			'created'       => $this->created?->format(DateTimeInterface::ATOM),
			'updated'       => $this->updated?->format(DateTimeInterface::ATOM),
			'baseVersion'   => $this->baseVersion,
			'changedFields' => array_map('strval', array_keys($delta)),
			'delta'         => $delta,
		];
	}//end jsonSerialize()
}//end class

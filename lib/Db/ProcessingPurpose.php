<?php

/**
 * An administered purpose, bound to a processing activity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One purpose a caller may name when it reads personal data.
 *
 * A purpose is deliberately NOT free text. Free text is a purpose nobody can
 * report on: "under which grondslag did we query the BRP this month" has to
 * have one answer per query and a count per purpose, and both need an
 * administered value. The binding to a verwerkingsactiviteit is what makes the
 * answer legally meaningful rather than merely consistent.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getCode()
 * @method void setCode(?string $code)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getActivity()
 * @method void setActivity(?string $activity)
 * @method string|null getActivityUuid()
 * @method void setActivityUuid(?string $activityUuid)
 * @method string|null getOrganisationId()
 * @method void setOrganisationId(?string $organisationId)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */
class ProcessingPurpose extends Entity implements JsonSerializable {
	/**
	 * A purpose a caller may name today.
	 *
	 * @var string
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * A purpose that existed and no longer applies. Kept rather than deleted,
	 * because audit rows written under it still name it.
	 *
	 * @var string
	 */
	public const STATUS_RETIRED = 'retired';

	/**
	 * The lifecycle vocabulary.
	 *
	 * @var string[]
	 */
	public const STATUS_VOCABULARY = [self::STATUS_ACTIVE, self::STATUS_RETIRED];

	/**
	 * Stable identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The short readable code a caller names on a request.
	 *
	 * @var string|null
	 */
	protected ?string $code = null;

	/**
	 * Human-readable name.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * What this purpose covers, for the administrator who maintains the list.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * The verwerkingsactiviteit reference as administered (code or uuid).
	 *
	 * @var string|null
	 */
	protected ?string $activity = null;

	/**
	 * The resolved verwerkingsactiviteit uuid. Null means the purpose names no
	 * activity that exists, which is exactly the state a query is refused for.
	 *
	 * @var string|null
	 */
	protected ?string $activityUuid = null;

	/**
	 * Owning organisation, when the instance is multi-tenant.
	 *
	 * @var string|null
	 */
	protected ?string $organisationId = null;

	/**
	 * Lifecycle status.
	 *
	 * @var string|null
	 */
	protected ?string $status = self::STATUS_ACTIVE;

	/**
	 * Creation time.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Last change time.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Register the entity's typed columns.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'code', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'activity', type: 'string');
		$this->addType(fieldName: 'activityUuid', type: 'string');
		$this->addType(fieldName: 'organisationId', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
	}//end __construct()

	/**
	 * Whether the supplied status string is in the lifecycle vocabulary.
	 *
	 * @param string|null $status Candidate status string.
	 *
	 * @return bool True when the status is one this entity recognises.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public static function isValidStatus(?string $status): bool {
		if ($status === null || $status === '') {
			return false;
		}

		return in_array(needle: $status, haystack: self::STATUS_VOCABULARY, strict: true);
	}//end isValidStatus()

	/**
	 * Whether this purpose may be named on a query right now.
	 *
	 * Both halves are required and they fail for different reasons, so the
	 * caller is told which: a retired purpose was administered and withdrawn,
	 * an unbound one names an activity the register does not have.
	 *
	 * @return bool True when the purpose is active AND bound to an activity.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function isUsable(): bool {
		return $this->status === self::STATUS_ACTIVE && $this->isBound() === true;
	}//end isUsable()

	/**
	 * Whether this purpose resolves to an entry in the processing register.
	 *
	 * @return bool True when an activity uuid is present.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function isBound(): bool {
		return $this->activityUuid !== null && $this->activityUuid !== '';
	}//end isBound()

	/**
	 * Render the purpose as JSON.
	 *
	 * @return array<string, mixed> The serialized purpose.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'code' => $this->code,
			'name' => $this->name,
			'description' => $this->description,
			'activity' => $this->activity,
			'activityUuid' => $this->activityUuid,
			'bound' => $this->isBound(),
			'organisationId' => $this->organisationId,
			'status' => $this->status,
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
		];
	}//end jsonSerialize()
}//end class

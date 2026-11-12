<?php

/**
 * AVG / GDPR per-access processing-log entry.
 *
 * One row of `oc_openregister_processing_log` — an append-only record
 * of a READ or EXPORT of an object whose schema opts in via the
 * `x-openregister-processing` annotation (`logReads: true`). Writes are
 * never duplicated here; they live on the hash-chained audit trail. The
 * per-subject inzage extract joins both records.
 *
 * The entry is intentionally narrow: purpose (doelbinding) and legal
 * basis (rechtsgrond) are NOT denormalised per row — they join from the
 * referenced Verwerkingsactiviteit so the rows stay aggregate-friendly
 * (design D3). `confidential` IS denormalised so FG-gating filters never
 * need a join.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCA\OpenRegister\Db\ProcessingLogEntry\ProcessingLogEntryFieldTypes;
use OCP\AppFramework\Db\Entity;

/**
 * Append-only processing-log entry entity.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getActivityId()
 * @method void setActivityId(?string $activityId)
 * @method string|null getAction()
 * @method void setAction(?string $action)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method string|null getChannel()
 * @method void setChannel(?string $channel)
 * @method string|null getRegisterId()
 * @method void setRegisterId(?string $registerId)
 * @method string|null getSchemaId()
 * @method void setSchemaId(?string $schemaId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getSubjectIdType()
 * @method void setSubjectIdType(?string $subjectIdType)
 * @method string|null getSubjectIdValue()
 * @method void setSubjectIdValue(?string $subjectIdValue)
 * @method int|null getObjectCount()
 * @method void setObjectCount(?int $objectCount)
 * @method array|null getObjectUuids()
 * @method void setObjectUuids(?array $objectUuids)
 * @method array|null getSubjectIds()
 * @method void setSubjectIds(?array $subjectIds)
 * @method array|null getQueryParams()
 * @method void setQueryParams(?array $queryParams)
 * @method bool|null getConfidential()
 * @method void setConfidential(?bool $confidential)
 * @method string|null getOrganisationId()
 * @method void setOrganisationId(?string $organisationId)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One field per column of the append-only processing-log row.
 */
class ProcessingLogEntry extends Entity implements JsonSerializable {
	// Serialises as the canonical VNG verwerkingsactie JSON shape: every registered field, in registration order.
	use TypedFieldsJsonTrait;

	/**
	 * VNG resource identity.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * Referenced Verwerkingsactiviteit uuid.
	 *
	 * @var string|null
	 */
	protected ?string $activityId = null;

	/**
	 * Processing action: `read` | `export`.
	 *
	 * @var string|null
	 */
	protected ?string $action = 'read';

	/**
	 * NC user id or API-client identifier.
	 *
	 * @var string|null
	 */
	protected ?string $actor = null;

	/**
	 * Access channel: ui | api | graphql | mcp | public | background.
	 *
	 * @var string|null
	 */
	protected ?string $channel = 'ui';

	/**
	 * Register identifier (scoping slice).
	 *
	 * @var string|null
	 */
	protected ?string $registerId = null;

	/**
	 * Schema identifier (scoping slice).
	 *
	 * @var string|null
	 */
	protected ?string $schemaId = null;

	/**
	 * Read object uuid (null for collapsed list/search entries).
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * Data-subject identifier type (e.g. `BSN`).
	 *
	 * @var string|null
	 */
	protected ?string $subjectIdType = null;

	/**
	 * Data-subject identifier value.
	 *
	 * @var string|null
	 */
	protected ?string $subjectIdValue = null;

	/**
	 * Number of objects covered by this entry (bulk/list collapse).
	 *
	 * @var integer|null
	 */
	protected ?int $objectCount = 1;

	/**
	 * Uuids of the objects a list/search entry covers, when it covers 100 or fewer.
	 *
	 * @var array<int, string>|null
	 */
	protected ?array $objectUuids = null;

	/**
	 * Subject identifiers (`{type, value}`) of a list/search entry of 100 or fewer objects.
	 *
	 * @var array<int, array{type: string, value: string}>|null
	 */
	protected ?array $subjectIds = null;

	/**
	 * Query parameters of a list/search entry.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $queryParams = null;

	/**
	 * Denormalised confidentiality marker from the activity (FG-gating).
	 *
	 * @var boolean|null
	 */
	protected ?bool $confidential = false;

	/**
	 * Tenant identifier for multi-tenant isolation.
	 *
	 * @var string|null
	 */
	protected ?string $organisationId = null;

	/**
	 * Append timestamp (period queries + retention prune).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Register the entity's typed columns.
	 */
	public function __construct() {
		foreach (ProcessingLogEntryFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()
}//end class

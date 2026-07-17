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
use OCP\AppFramework\Db\Entity;

/**
 * Append-only processing-log entry entity.
 *
 * @method string|null   getUuid()
 * @method void          setUuid(?string $uuid)
 * @method string|null   getActivityId()
 * @method void          setActivityId(?string $activityId)
 * @method string|null   getAction()
 * @method void          setAction(?string $action)
 * @method string|null   getActor()
 * @method void          setActor(?string $actor)
 * @method string|null   getChannel()
 * @method void          setChannel(?string $channel)
 * @method string|null   getRegisterId()
 * @method void          setRegisterId(?string $registerId)
 * @method string|null   getSchemaId()
 * @method void          setSchemaId(?string $schemaId)
 * @method string|null   getObjectUuid()
 * @method void          setObjectUuid(?string $objectUuid)
 * @method string|null   getSubjectIdType()
 * @method void          setSubjectIdType(?string $subjectIdType)
 * @method string|null   getSubjectIdValue()
 * @method void          setSubjectIdValue(?string $subjectIdValue)
 * @method int|null      getObjectCount()
 * @method void          setObjectCount(?int $objectCount)
 * @method bool|null     getConfidential()
 * @method void          setConfidential(?bool $confidential)
 * @method string|null   getOrganisationId()
 * @method void          setOrganisationId(?string $organisationId)
 * @method DateTime|null getCreated()
 * @method void          setCreated(?DateTime $created)
 */
class ProcessingLogEntry extends Entity implements JsonSerializable
{

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
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'activityId', type: 'string');
        $this->addType(fieldName: 'action', type: 'string');
        $this->addType(fieldName: 'actor', type: 'string');
        $this->addType(fieldName: 'channel', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'string');
        $this->addType(fieldName: 'schemaId', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'subjectIdType', type: 'string');
        $this->addType(fieldName: 'subjectIdValue', type: 'string');
        $this->addType(fieldName: 'objectCount', type: 'integer');
        $this->addType(fieldName: 'confidential', type: 'boolean');
        $this->addType(fieldName: 'organisationId', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');

    }//end __construct()

    /**
     * Render the entry as the canonical VNG verwerkingsactie JSON shape.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'activityId'     => $this->activityId,
            'action'         => $this->action,
            'actor'          => $this->actor,
            'channel'        => $this->channel,
            'registerId'     => $this->registerId,
            'schemaId'       => $this->schemaId,
            'objectUuid'     => $this->objectUuid,
            'subjectIdType'  => $this->subjectIdType,
            'subjectIdValue' => $this->subjectIdValue,
            'objectCount'    => $this->objectCount,
            'confidential'   => $this->confidential,
            'organisationId' => $this->organisationId,
            'created'        => $this->created?->format('c'),
        ];

    }//end jsonSerialize()
}//end class

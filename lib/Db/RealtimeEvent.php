<?php

/**
 * OpenRegister RealtimeEvent
 *
 * Append-only row in `openregister_realtime_events`. Represents one
 * CloudEvent-shaped record of a register-object change; clients poll
 * the realtime endpoint with `?since={id}` to receive every event
 * newer than their last seen id.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use JsonSerializable;

/**
 * Append-only realtime event row.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string|null getEventType()
 * @method void setEventType(?string $eventType)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getRegisterId()
 * @method void setRegisterId(?string $registerId)
 * @method string|null getSchemaId()
 * @method void setSchemaId(?string $schemaId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getActorUid()
 * @method void setActorUid(?string $actorUid)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getPayload()
 * @method void setPayload(?string $payload)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 */
class RealtimeEvent extends Entity implements JsonSerializable
{

    /**
     * CloudEvent type, e.g. `nl.openregister.object.updated`.
     *
     * @var string|null
     */
    protected ?string $eventType = null;

    /**
     * CloudEvent source URI for this event.
     *
     * @var string|null
     */
    protected ?string $source = null;

    /**
     * Optional CloudEvent subject (typically the object UUID).
     *
     * @var string|null
     */
    protected ?string $subject = null;

    /**
     * Register identifier (denormalised for filtering).
     *
     * @var string|null
     */
    protected ?string $registerId = null;

    /**
     * Schema identifier (denormalised for filtering).
     *
     * @var string|null
     */
    protected ?string $schemaId = null;

    /**
     * UUID of the object the event is about.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * UID of the actor that triggered the event.
     *
     * @var string|null
     */
    protected ?string $actorUid = null;

    /**
     * Organisation identifier the event belongs to.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * Full CloudEvent payload as JSON string.
     *
     * @var string|null
     */
    protected ?string $payload = null;

    /**
     * Creation timestamp for the event row.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Configure typed columns for the entity.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'eventType', type: 'string');
        $this->addType(fieldName: 'source', type: 'string');
        $this->addType(fieldName: 'subject', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'string');
        $this->addType(fieldName: 'schemaId', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'actorUid', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'payload', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the event in CloudEvents-1.0-compatible shape.
     *
     * The stored `payload` is the canonical CloudEvent (already a JSON
     * string emitted by `RealtimeService`); on serialise we decode it
     * and tag the row id so clients can use it as the next `since`
     * cursor.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $payload = $this->payload !== null ? json_decode($this->payload, true) : null;
        if (is_array($payload) === false) {
            $payload = [];
        }

        $payload['_cursor'] = $this->id;
        return $payload;
    }//end jsonSerialize()
}//end class

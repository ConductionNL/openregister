<?php

/**
 * CalendarLink entity for linking CalDAV VEVENTs to OpenRegister objects.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class CalendarLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int getSchemaId()
 * @method void setSchemaId(int $schemaId)
 * @method string getCalendarUri()
 * @method void setCalendarUri(string $calendarUri)
 * @method int|null getCalendarId()
 * @method void setCalendarId(?int $calendarId)
 * @method string getEventUid()
 * @method void setEventUid(string $eventUid)
 * @method string getEventUri()
 * @method void setEventUri(string $eventUri)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method DateTime|null getDtstart()
 * @method void setDtstart(?DateTime $dtstart)
 * @method DateTime|null getDtend()
 * @method void setDtend(?DateTime $dtend)
 * @method string|null getLocation()
 * @method void setLocation(?string $location)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 * @method bool getTaggedWithXor()
 * @method void setTaggedWithXor(bool $taggedWithXor)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class CalendarLink extends Entity implements JsonSerializable
{

    /**
     * The object uuid.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The register id.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * The schema id.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * The calendar uri.
     *
     * @var string|null
     */
    protected ?string $calendarUri = null;

    /**
     * The calendar id (denormalised for fast joins).
     *
     * @var integer|null
     */
    protected ?int $calendarId = null;

    /**
     * The event uid.
     *
     * @var string|null
     */
    protected ?string $eventUid = null;

    /**
     * The event uri (DAV uri).
     *
     * @var string|null
     */
    protected ?string $eventUri = null;

    /**
     * Event summary (cached at link-time).
     *
     * @var string|null
     */
    protected ?string $summary = null;

    /**
     * Event dtstart.
     *
     * @var DateTime|null
     */
    protected ?DateTime $dtstart = null;

    /**
     * Event dtend.
     *
     * @var DateTime|null
     */
    protected ?DateTime $dtend = null;

    /**
     * Event location.
     *
     * @var string|null
     */
    protected ?string $location = null;

    /**
     * The user who created the link.
     *
     * @var string|null
     */
    protected ?string $linkedBy = null;

    /**
     * When the link was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $linkedAt = null;

    /**
     * Whether the VEVENT also carries X-OPENREGISTER-* properties.
     *
     * @var boolean|null
     */
    protected ?bool $taggedWithXor = false;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'calendarUri', type: 'string');
        $this->addType(fieldName: 'calendarId', type: 'integer');
        $this->addType(fieldName: 'eventUid', type: 'string');
        $this->addType(fieldName: 'eventUri', type: 'string');
        $this->addType(fieldName: 'summary', type: 'string');
        $this->addType(fieldName: 'dtstart', type: 'datetime');
        $this->addType(fieldName: 'dtend', type: 'datetime');
        $this->addType(fieldName: 'location', type: 'string');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
        $this->addType(fieldName: 'taggedWithXor', type: 'boolean');
    }//end __construct()

    /**
     * JSON serialization in CnCalendarTab-consumer shape.
     *
     * Mirrors CalendarEventService::veventToArray() so the UNION read
     * path in CalendarLinkService can return a homogeneous array of
     * rows regardless of source.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $calendarIdStr = null;
        if ($this->calendarId !== null) {
            $calendarIdStr = (string) $this->calendarId;
        }

        return [
            'id'            => $this->eventUri,
            'uid'           => $this->eventUid,
            'calendarId'    => $calendarIdStr,
            'calendarUri'   => $this->calendarUri,
            'summary'       => $this->summary ?? '',
            'dtstart'       => $this->dtstart?->format(DateTime::ATOM),
            'dtend'         => $this->dtend?->format(DateTime::ATOM),
            'location'      => $this->location,
            'description'   => '',
            'attendees'     => [],
            'status'        => null,
            'objectUuid'    => $this->objectUuid,
            'registerId'    => $this->registerId,
            'schemaId'      => $this->schemaId,
            'linkedBy'      => $this->linkedBy,
            'linkedAt'      => $this->linkedAt?->format(DateTime::ATOM),
            'taggedWithXor' => (bool) $this->taggedWithXor,
        ];
    }//end jsonSerialize()
}//end class

<?php

/**
 * TalkLink entity for linking Nextcloud Talk (spreed) rooms to
 * OpenRegister objects.
 *
 * Tier-2 schema: also carries schema_id + cached room metadata
 * (subtitle, participantCount, lastMessageData, lastActivity) so the
 * link row alone can hydrate the sidebar tab + picker UX without a
 * per-row roundtrip to Talk's Manager. Caches are refreshed by
 * TalkLinkService on read (>5min staleness).
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class TalkLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string getRoomToken()
 * @method void setRoomToken(string $roomToken)
 * @method int|null getRoomId()
 * @method void setRoomId(?int $roomId)
 * @method string|null getRoomName()
 * @method void setRoomName(?string $roomName)
 * @method int|null getRoomType()
 * @method void setRoomType(?int $roomType)
 * @method string|null getSubtitle()
 * @method void setSubtitle(?string $subtitle)
 * @method int|null getParticipantCount()
 * @method void setParticipantCount(?int $participantCount)
 * @method string|null getLastMessageData()
 * @method void setLastMessageData(?string $lastMessageData)
 * @method DateTime|null getLastActivity()
 * @method void setLastActivity(?DateTime $lastActivity)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class TalkLink extends Entity implements JsonSerializable
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
     * The Talk room token (canonical id).
     *
     * @var string|null
     */
    protected ?string $roomToken = null;

    /**
     * The Talk room internal id (legacy numeric Room::id).
     *
     * @var integer|null
     */
    protected ?int $roomId = null;

    /**
     * The cached Talk room display name.
     *
     * @var string|null
     */
    protected ?string $roomName = null;

    /**
     * The Talk room type (Talk Room::TYPE_*: 1=one2one, 2=group,
     * 3=public, 4=changelog, 6=note-to-self).
     *
     * @var integer|null
     */
    protected ?int $roomType = null;

    /**
     * Human-readable subtitle (cached translation of room type or
     * description).
     *
     * @var string|null
     */
    protected ?string $subtitle = null;

    /**
     * Cached participant count from ParticipantService::getNumberOfActors.
     *
     * @var integer|null
     */
    protected ?int $participantCount = null;

    /**
     * JSON-encoded last-message payload, shape:
     *   { actor: {type, id}, text, timestamp }
     *
     * @var string|null
     */
    protected ?string $lastMessageData = null;

    /**
     * Cached Talk Room::getLastActivity() timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastActivity = null;

    /**
     * The user UID that linked the room.
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
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'roomToken', type: 'string');
        $this->addType(fieldName: 'roomId', type: 'integer');
        $this->addType(fieldName: 'roomName', type: 'string');
        $this->addType(fieldName: 'roomType', type: 'integer');
        $this->addType(fieldName: 'subtitle', type: 'string');
        $this->addType(fieldName: 'participantCount', type: 'integer');
        $this->addType(fieldName: 'lastMessageData', type: 'string');
        $this->addType(fieldName: 'lastActivity', type: 'datetime');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * Decodes the JSON `lastMessageData` column into an associative
     * array so the leaf row is directly consumable by the sidebar tab
     * and picker UX. Returns `null` for `lastMessage` on malformed
     * JSON.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $lastMessage = null;
        if ($this->lastMessageData !== null && $this->lastMessageData !== '') {
            $decoded = json_decode($this->lastMessageData, true);
            if (is_array($decoded) === true) {
                $lastMessage = $decoded;
            }
        }

        // Convenience deep-link for the UI.
        $url = null;
        if ($this->roomToken !== null) {
            $url = '/index.php/call/'.$this->roomToken;
        }

        return [
            'id'               => $this->id,
            'objectUuid'       => $this->objectUuid,
            'registerId'       => $this->registerId,
            'schemaId'         => $this->schemaId,
            'roomToken'        => $this->roomToken,
            'roomId'           => $this->roomId,
            'roomName'         => $this->roomName,
            'roomType'         => $this->roomType,
            'subtitle'         => $this->subtitle,
            'participantCount' => $this->participantCount,
            'lastMessage'      => $lastMessage,
            'lastActivity'     => $this->lastActivity?->format(DateTime::ATOM),
            'linkedBy'         => $this->linkedBy,
            'linkedAt'         => $this->linkedAt?->format(DateTime::ATOM),
            'url'              => $url,
        ];
    }//end jsonSerialize()
}//end class

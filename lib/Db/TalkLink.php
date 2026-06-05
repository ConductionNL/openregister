<?php

/**
 * TalkLink entity for linking NC Talk conversations to OpenRegister objects.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-talk/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class TalkLink
 *
 * Stores the association between an OR object UUID and a Nextcloud Talk
 * conversation token (room token as returned by the Talk OCS API).
 *
 * @method string      getObjectUuid()
 * @method void        setObjectUuid(string $objectUuid)
 * @method int         getRegisterId()
 * @method void        setRegisterId(int $registerId)
 * @method string      getConversationToken()
 * @method void        setConversationToken(string $conversationToken)
 * @method string|null getConversationName()
 * @method void        setConversationName(?string $conversationName)
 * @method string      getLinkedBy()
 * @method void        setLinkedBy(string $linkedBy)
 * @method DateTime    getLinkedAt()
 * @method void        setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class TalkLink extends Entity implements JsonSerializable
{

    /**
     * The OR object UUID this link belongs to.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The OR register ID.
     *
     * @var int|null
     */
    protected ?int $registerId = null;

    /**
     * The Talk conversation token (room identifier).
     *
     * @var string|null
     */
    protected ?string $conversationToken = null;

    /**
     * Human-readable conversation display name (cached from Talk).
     *
     * @var string|null
     */
    protected ?string $conversationName = null;

    /**
     * User ID that created this link.
     *
     * @var string|null
     */
    protected ?string $linkedBy = null;

    /**
     * Timestamp when this link was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $linkedAt = null;

    /**
     * Constructor — registers field types for NC's magic setter infrastructure.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'conversationToken', type: 'string');
        $this->addType(fieldName: 'conversationName', type: 'string');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization for API responses.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->id,
            'objectUuid'        => $this->objectUuid,
            'registerId'        => $this->registerId,
            'conversationToken' => $this->conversationToken,
            'conversationName'  => $this->conversationName,
            'linkedBy'          => $this->linkedBy,
            'linkedAt'          => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()

}//end class

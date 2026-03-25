<?php

/**
 * EmailLink entity maps emails to OpenRegister objects.
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
 * Class EmailLink
 *
 * Links a Nextcloud Mail message to an OpenRegister object.
 *
 * @method int getMailAccountId()
 * @method void setMailAccountId(int $mailAccountId)
 * @method int getMailMessageId()
 * @method void setMailMessageId(int $mailMessageId)
 * @method string|null getMailMessageUid()
 * @method void setMailMessageUid(?string $mailMessageUid)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getSender()
 * @method void setSender(?string $sender)
 * @method string|null getMailDate()
 * @method void setMailDate(?string $mailDate)
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getLinkedBy()
 * @method void setLinkedBy(?string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(?DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class EmailLink extends Entity implements JsonSerializable
{

    /**
     * The Nextcloud Mail account ID.
     *
     * @var integer|null
     */
    protected ?int $mailAccountId = null;

    /**
     * The Nextcloud Mail message ID.
     *
     * @var integer|null
     */
    protected ?int $mailMessageId = null;

    /**
     * The mail message UID (optional, for cross-referencing).
     *
     * @var string|null
     */
    protected ?string $mailMessageUid = null;

    /**
     * The email subject.
     *
     * @var string|null
     */
    protected ?string $subject = null;

    /**
     * The email sender address.
     *
     * @var string|null
     */
    protected ?string $sender = null;

    /**
     * The email date.
     *
     * @var string|null
     */
    protected ?string $mailDate = null;

    /**
     * The OpenRegister object UUID.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The register ID the object belongs to.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * The schema ID of the object (optional, resolved from object).
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

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
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'mailAccountId', type: 'integer');
        $this->addType(fieldName: 'mailMessageId', type: 'integer');
        $this->addType(fieldName: 'mailMessageUid', type: 'string');
        $this->addType(fieldName: 'subject', type: 'string');
        $this->addType(fieldName: 'sender', type: 'string');
        $this->addType(fieldName: 'mailDate', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->id,
            'mailAccountId'  => $this->mailAccountId,
            'mailMessageId'  => $this->mailMessageId,
            'mailMessageUid' => $this->mailMessageUid,
            'subject'        => $this->subject,
            'sender'         => $this->sender,
            'mailDate'       => $this->mailDate,
            'objectUuid'     => $this->objectUuid,
            'registerId'     => $this->registerId,
            'schemaId'       => $this->schemaId,
            'linkedBy'       => $this->linkedBy,
            'linkedAt'       => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class

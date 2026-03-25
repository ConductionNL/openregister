<?php

/**
 * EmailLink entity for linking Nextcloud Mail messages to OpenRegister objects.
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
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
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
 * @method DateTime|null getDate()
 * @method void setMailDate(?DateTime $mailDate)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class EmailLink extends Entity implements JsonSerializable
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
     * The mail account id.
     *
     * @var integer|null
     */
    protected ?int $mailAccountId = null;

    /**
     * The mail message id.
     *
     * @var integer|null
     */
    protected ?int $mailMessageId = null;

    /**
     * The mail message uid.
     *
     * @var string|null
     */
    protected ?string $mailMessageUid = null;

    /**
     * The subject.
     *
     * @var string|null
     */
    protected ?string $subject = null;

    /**
     * The sender.
     *
     * @var string|null
     */
    protected ?string $sender = null;

    /**
     * The mail date.
     *
     * @var DateTime|null
     */
    protected ?DateTime $mailDate = null;

    /**
     * The linked by.
     *
     * @var string|null
     */
    protected ?string $linkedBy = null;

    /**
     * The linked at.
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
        $this->addType(fieldName: 'mailAccountId', type: 'integer');
        $this->addType(fieldName: 'mailMessageId', type: 'integer');
        $this->addType(fieldName: 'mailMessageUid', type: 'string');
        $this->addType(fieldName: 'subject', type: 'string');
        $this->addType(fieldName: 'sender', type: 'string');
        $this->addType(fieldName: 'mailDate', type: 'datetime');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->id,
            'objectUuid'     => $this->objectUuid,
            'registerId'     => $this->registerId,
            'mailAccountId'  => $this->mailAccountId,
            'mailMessageId'  => $this->mailMessageId,
            'mailMessageUid' => $this->mailMessageUid,
            'subject'        => $this->subject,
            'sender'         => $this->sender,
            'mailDate'       => $this->mailDate?->format(DateTime::ATOM),
            'linkedBy'       => $this->linkedBy,
            'linkedAt'       => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class

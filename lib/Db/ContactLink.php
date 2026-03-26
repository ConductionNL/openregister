<?php

/**
 * ContactLink entity for linking CardDAV contacts to OpenRegister objects.
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
 * Class ContactLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method string getContactUid()
 * @method void setContactUid(string $contactUid)
 * @method int getAddressbookId()
 * @method void setAddressbookId(int $addressbookId)
 * @method string getContactUri()
 * @method void setContactUri(string $contactUri)
 * @method string|null getDisplayName()
 * @method void setDisplayName(?string $displayName)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ContactLink extends Entity implements JsonSerializable
{

    /** @var string|null */
    protected ?string $objectUuid = null;

    /** @var int|null */
    protected ?int $registerId = null;

    /** @var string|null */
    protected ?string $contactUid = null;

    /** @var int|null */
    protected ?int $addressbookId = null;

    /** @var string|null */
    protected ?string $contactUri = null;

    /** @var string|null */
    protected ?string $displayName = null;

    /** @var string|null */
    protected ?string $email = null;

    /** @var string|null */
    protected ?string $role = null;

    /** @var string|null */
    protected ?string $linkedBy = null;

    /** @var DateTime|null */
    protected ?DateTime $linkedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType('objectUuid', 'string');
        $this->addType('registerId', 'integer');
        $this->addType('contactUid', 'string');
        $this->addType('addressbookId', 'integer');
        $this->addType('contactUri', 'string');
        $this->addType('displayName', 'string');
        $this->addType('email', 'string');
        $this->addType('role', 'string');
        $this->addType('linkedBy', 'string');
        $this->addType('linkedAt', 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'objectUuid'    => $this->objectUuid,
            'registerId'    => $this->registerId,
            'contactUid'    => $this->contactUid,
            'addressbookId' => $this->addressbookId,
            'contactUri'    => $this->contactUri,
            'displayName'   => $this->displayName,
            'email'         => $this->email,
            'role'          => $this->role,
            'linkedBy'      => $this->linkedBy,
            'linkedAt'      => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class

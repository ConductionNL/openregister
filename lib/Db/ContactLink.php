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
     * The contact uid.
     *
     * @var string|null
     */
    protected ?string $contactUid = null;

    /**
     * The addressbook id.
     *
     * @var integer|null
     */
    protected ?int $addressbookId = null;

    /**
     * The contact uri.
     *
     * @var string|null
     */
    protected ?string $contactUri = null;

    /**
     * The display name.
     *
     * @var string|null
     */
    protected ?string $displayName = null;

    /**
     * The email.
     *
     * @var string|null
     */
    protected ?string $email = null;

    /**
     * The role.
     *
     * @var string|null
     */
    protected ?string $role = null;

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
        $this->addType(fieldName: 'contactUid', type: 'string');
        $this->addType(fieldName: 'addressbookId', type: 'integer');
        $this->addType(fieldName: 'contactUri', type: 'string');
        $this->addType(fieldName: 'displayName', type: 'string');
        $this->addType(fieldName: 'email', type: 'string');
        $this->addType(fieldName: 'role', type: 'string');
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

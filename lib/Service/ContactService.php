<?php

/**
 * ContactService
 *
 * Service that wraps CardDAV vCard operations for linking contacts to OpenRegister objects.
 * Uses dual storage: X-OPENREGISTER-* vCard properties + openregister_contact_links table.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

/**
 * ContactService manages contact-to-object links via dual storage.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class ContactService
{

    /**
     * Contact link mapper.
     *
     * @var ContactLinkMapper
     */
    private readonly ContactLinkMapper $contactLinkMapper;

    /**
     * CardDAV backend.
     *
     * @var CardDavBackend
     */
    private readonly CardDavBackend $cardDavBackend;

    /**
     * User session.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param ContactLinkMapper $contactLinkMapper Contact link mapper
     * @param CardDavBackend    $cardDavBackend    CardDAV backend
     * @param IUserSession      $userSession       User session
     * @param LoggerInterface   $logger            Logger
     *
     * @return void
     */
    public function __construct(
        ContactLinkMapper $contactLinkMapper,
        CardDavBackend $cardDavBackend,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->contactLinkMapper = $contactLinkMapper;
        $this->cardDavBackend    = $cardDavBackend;
        $this->userSession       = $userSession;
        $this->logger            = $logger;
    }//end __construct()

    /**
     * Get all contact links for an object.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return array{results: array, total: int}
     */
    public function getContactsForObject(string $objectUuid): array
    {
        $links = $this->contactLinkMapper->findByObjectUuid($objectUuid);
        $total = $this->contactLinkMapper->countByObjectUuid($objectUuid);

        $results = array_map(
            static function (ContactLink $link): array {
                return $link->jsonSerialize();
            },
            $links
        );

        return ['results' => $results, 'total' => $total];
    }//end getContactsForObject()

    /**
     * Link an existing contact to an object.
     *
     * @param string      $objectUuid    The object UUID.
     * @param int         $registerId    The register ID.
     * @param int         $addressbookId The addressbook ID.
     * @param string      $contactUri    The contact URI in the addressbook.
     * @param string|null $role          The role of this contact on the object.
     *
     * @return ContactLink The created link.
     *
     * @throws Exception If the contact does not exist.
     */
    public function linkContact(
        string $objectUuid,
        int $registerId,
        int $addressbookId,
        string $contactUri,
        ?string $role=null
    ): ContactLink {
        // Verify the contact exists.
        $card = $this->cardDavBackend->getCard($addressbookId, $contactUri);
        if ($card === false) {
            throw new Exception('Contact not found', 404);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        // Parse vCard for cached fields.
        $vcard       = Reader::read($card['carddata']);
        $contactUid  = isset($vcard->UID) === true ? (string) $vcard->UID : '';
        $displayName = isset($vcard->FN) === true ? (string) $vcard->FN : null;
        $email       = null;
        if (isset($vcard->EMAIL) === true) {
            $email = (string) $vcard->EMAIL;
        }

        // Add X-OPENREGISTER-* properties to the vCard.
        $vcard->add('X-OPENREGISTER-OBJECT', $objectUuid);
        if ($role !== null) {
            $vcard->add('X-OPENREGISTER-ROLE', $role);
        }

        $this->cardDavBackend->updateCard($addressbookId, $contactUri, $vcard->serialize());

        // Create DB record.
        $link = new ContactLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setContactUid($contactUid);
        $link->setAddressbookId($addressbookId);
        $link->setContactUri($contactUri);
        $link->setDisplayName($displayName);
        $link->setEmail($email);
        $link->setRole($role);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->contactLinkMapper->insert($link);
    }//end linkContact()

    /**
     * Create a new contact and link it to an object.
     *
     * @param string $objectUuid The object UUID.
     * @param int    $registerId The register ID.
     * @param array  $data       Contact data: fullName, email, phone, role.
     *
     * @return ContactLink The created link.
     *
     * @throws Exception If no user or addressbook.
     */
    public function createAndLinkContact(
        string $objectUuid,
        int $registerId,
        array $data
    ): ContactLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $addressbook = $this->findUserAddressbook();
        if ($addressbook === null) {
            throw new Exception('No addressbook found');
        }

        $uid  = strtoupper(bin2hex(random_bytes(16)));
        $role = $data['role'] ?? null;

        // Build vCard.
        $lines   = [];
        $lines[] = 'BEGIN:VCARD';
        $lines[] = 'VERSION:3.0';
        $lines[] = 'UID:'.$uid;
        $lines[] = 'FN:'.($data['fullName'] ?? 'Unknown');

        if (empty($data['email']) === false) {
            $lines[] = 'EMAIL;TYPE=INTERNET:'.$data['email'];
        }

        if (empty($data['phone']) === false) {
            $lines[] = 'TEL;TYPE=CELL:'.$data['phone'];
        }

        $lines[] = 'X-OPENREGISTER-OBJECT:'.$objectUuid;
        if ($role !== null) {
            $lines[] = 'X-OPENREGISTER-ROLE:'.$role;
        }

        $lines[] = 'END:VCARD';

        $cardData   = implode("\r\n", $lines)."\r\n";
        $contactUri = $uid.'.vcf';

        $this->cardDavBackend->createCard($addressbook['id'], $contactUri, $cardData);

        // Create DB record.
        $link = new ContactLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setContactUid($uid);
        $link->setAddressbookId($addressbook['id']);
        $link->setContactUri($contactUri);
        $link->setDisplayName($data['fullName'] ?? null);
        $link->setEmail($data['email'] ?? null);
        $link->setRole($role);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->contactLinkMapper->insert($link);
    }//end createAndLinkContact()

    /**
     * Update the role on a contact-object link.
     *
     * @param int    $linkId The link ID.
     * @param string $role   The new role.
     *
     * @return ContactLink The updated link.
     *
     * @throws Exception If link not found.
     */
    public function updateRole(int $linkId, string $role): ContactLink
    {
        try {
            $link = $this->contactLinkMapper->find($linkId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new Exception('Contact link not found', 404);
        }

        // Update vCard role property.
        try {
            $card = $this->cardDavBackend->getCard($link->getAddressbookId(), $link->getContactUri());
            if ($card !== false) {
                $vcard = Reader::read($card['carddata']);
                // Remove old role properties.
                unset($vcard->{'X-OPENREGISTER-ROLE'});
                $vcard->add('X-OPENREGISTER-ROLE', $role);
                $this->cardDavBackend->updateCard($link->getAddressbookId(), $link->getContactUri(), $vcard->serialize());
            }
        } catch (Exception $e) {
            $this->logger->warning('Failed to update vCard role: '.$e->getMessage());
        }

        $link->setRole($role);

        return $this->contactLinkMapper->update($link);
    }//end updateRole()

    /**
     * Remove a contact link.
     *
     * @param int $linkId The link ID.
     *
     * @return void
     *
     * @throws Exception If link not found.
     */
    public function unlinkContact(int $linkId): void
    {
        try {
            $link = $this->contactLinkMapper->find($linkId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new Exception('Contact link not found', 404);
        }

        // Remove X-OPENREGISTER-* from vCard.
        try {
            $card = $this->cardDavBackend->getCard($link->getAddressbookId(), $link->getContactUri());
            if ($card !== false) {
                $vcard = Reader::read($card['carddata']);
                unset($vcard->{'X-OPENREGISTER-OBJECT'});
                unset($vcard->{'X-OPENREGISTER-ROLE'});
                $this->cardDavBackend->updateCard($link->getAddressbookId(), $link->getContactUri(), $vcard->serialize());
            }
        } catch (Exception $e) {
            $this->logger->warning('Failed to clean vCard properties: '.$e->getMessage());
        }

        $this->contactLinkMapper->delete($link);
    }//end unlinkContact()

    /**
     * Find all objects linked to a contact.
     *
     * @param string $contactUid The contact UID.
     *
     * @return array Array of contact links with object UUIDs and roles.
     */
    public function getObjectsForContact(string $contactUid): array
    {
        $links = $this->contactLinkMapper->findByContactUid($contactUid);

        return array_map(
            static function (ContactLink $link): array {
                return $link->jsonSerialize();
            },
            $links
        );
    }//end getObjectsForContact()

    /**
     * Delete all contact links for an object (cleanup).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return void
     */
    public function deleteLinksForObject(string $objectUuid): void
    {
        $links = $this->contactLinkMapper->findByObjectUuid($objectUuid);

        foreach ($links as $link) {
            try {
                $card = $this->cardDavBackend->getCard($link->getAddressbookId(), $link->getContactUri());
                if ($card !== false) {
                    $vcard = Reader::read($card['carddata']);
                    // Remove properties matching this object only.
                    unset($vcard->{'X-OPENREGISTER-OBJECT'});
                    unset($vcard->{'X-OPENREGISTER-ROLE'});
                    $this->cardDavBackend->updateCard(
                        $link->getAddressbookId(),
                        $link->getContactUri(),
                        $vcard->serialize()
                    );
                }
            } catch (Exception $e) {
                $this->logger->warning(
                    'Failed to clean vCard for contact '.$link->getContactUid().': '.$e->getMessage()
                );
            }
        }//end foreach

        $this->contactLinkMapper->deleteByObjectUuid($objectUuid);
    }//end deleteLinksForObject()

    /**
     * Find the user's default addressbook.
     *
     * @return array|null Addressbook data or null.
     */
    private function findUserAddressbook(): ?array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        $principal    = 'principals/users/'.$user->getUID();
        $addressbooks = $this->cardDavBackend->getAddressBooksForUser($principal);

        if (empty($addressbooks) === true) {
            return null;
        }

        // Return first addressbook.
        return $addressbooks[0];
    }//end findUserAddressbook()
}//end class

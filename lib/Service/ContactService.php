<?php

/**
 * ContactService
 *
 * Service that wraps CardDAV vCard operations for linking contacts to OpenRegister objects.
 * Uses _contacts metadata column as primary storage + X-OPENREGISTER-* vCard properties as
 * secondary notification to the Contacts app.
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

use Exception;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\OpenRegister\Db\MagicMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

/**
 * ContactService manages contact-to-object links via the _contacts metadata column.
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
     * Constructor.
     *
     * @param MagicMapper          $magicMapper         Object mapper
     * @param LinkedEntityService  $linkedEntityService Reverse lookup service
     * @param CardDavBackend       $cardDavBackend      CardDAV backend
     * @param IUserSession         $userSession         User session
     * @param LoggerInterface      $logger              Logger
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly LinkedEntityService $linkedEntityService,
        private readonly CardDavBackend $cardDavBackend,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get all contact links for an object, enriched from CardDAV.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return array{results: array, total: int}
     */
    public function getContactsForObject(string $objectUuid): array
    {
        $object     = $this->magicMapper->find($objectUuid);
        $contactIds = $object->getContacts() ?? [];
        $total      = count($contactIds);

        $results = [];
        foreach ($contactIds as $contactUid) {
            $results[] = $this->enrichContact($contactUid);
        }

        return ['results' => $results, 'total' => $total];
    }//end getContactsForObject()

    /**
     * Link an existing contact to an object.
     *
     * @param string      $objectUuid    The object UUID.
     * @param int         $registerId    The register ID (kept for interface compatibility).
     * @param int         $addressbookId The addressbook ID.
     * @param string      $contactUri    The contact URI in the addressbook.
     * @param string|null $role          The role of this contact on the object.
     *
     * @return array The enriched contact data.
     *
     * @throws Exception If the contact does not exist.
     */
    public function linkContact(
        string $objectUuid,
        int $registerId,
        int $addressbookId,
        string $contactUri,
<<<<<<< HEAD
        ?string $role=null
    ): ContactLink {
=======
        ?string $role = null
    ): array {
>>>>>>> origin/feature/linked-entity-types
        // Verify the contact exists.
        $card = $this->cardDavBackend->getCard($addressbookId, $contactUri);
        if ($card === false) {
            throw new Exception('Contact not found', 404);
        }

        // Parse vCard for UID.
        $vcard      = Reader::read($card['carddata']);
        $contactUid = isset($vcard->UID) === true ? (string) $vcard->UID : '';

        // Add X-OPENREGISTER-* properties to the vCard.
        $vcard->add('X-OPENREGISTER-OBJECT', $objectUuid);
        if ($role !== null) {
            $vcard->add('X-OPENREGISTER-ROLE', $role);
        }

        $this->cardDavBackend->updateCard($addressbookId, $contactUri, $vcard->serialize());

        // Append to _contacts column.
        $object     = $this->magicMapper->find($objectUuid);
        $contactIds = $object->getContacts() ?? [];

        if (in_array($contactUid, $contactIds, true) === false) {
            $contactIds[] = $contactUid;
            $object->setContacts($contactIds);
            $this->magicMapper->update($object);
        }

        return $this->enrichContact($contactUid);
    }//end linkContact()

    /**
     * Create a new contact and link it to an object.
     *
     * @param string $objectUuid The object UUID.
<<<<<<< HEAD
     * @param int    $registerId The register ID.
=======
     * @param int    $registerId The register ID (kept for interface compatibility).
>>>>>>> origin/feature/linked-entity-types
     * @param array  $data       Contact data: fullName, email, phone, role.
     *
     * @return array The enriched contact data.
     *
     * @throws Exception If no user or addressbook.
     */
    public function createAndLinkContact(
        string $objectUuid,
        int $registerId,
        array $data
    ): array {
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
        $lines[] = 'UID:' . $uid;
        $lines[] = 'FN:' . ($data['fullName'] ?? 'Unknown');

        if (empty($data['email']) === false) {
            $lines[] = 'EMAIL;TYPE=INTERNET:' . $data['email'];
        }

        if (empty($data['phone']) === false) {
            $lines[] = 'TEL;TYPE=CELL:' . $data['phone'];
        }

        $lines[] = 'X-OPENREGISTER-OBJECT:' . $objectUuid;
        if ($role !== null) {
            $lines[] = 'X-OPENREGISTER-ROLE:' . $role;
        }

        $lines[] = 'END:VCARD';

<<<<<<< HEAD
        $cardData   = implode("\r\n", $lines)."\r\n";
        $contactUri = $uid.'.vcf';
=======
        $cardData   = implode("\r\n", $lines) . "\r\n";
        $contactUri = $uid . '.vcf';
>>>>>>> origin/feature/linked-entity-types

        $this->cardDavBackend->createCard($addressbook['id'], $contactUri, $cardData);

        // Append UID to _contacts column.
        $object     = $this->magicMapper->find($objectUuid);
        $contactIds = $object->getContacts() ?? [];
        $contactIds[] = $uid;
        $object->setContacts($contactIds);
        $this->magicMapper->update($object);

        return $this->enrichContact($uid);
    }//end createAndLinkContact()

    /**
     * Remove a contact link from an object.
     *
     * @param string $objectUuid The object UUID.
     * @param string $contactUid The contact UID.
     *
     * @return void
     *
     * @throws Exception If object not found.
     */
    public function unlinkContact(string $objectUuid, string $contactUid): void
    {
        // Remove X-OPENREGISTER-* from vCard.
        $this->cleanVcardProperties($contactUid);

        // Remove from _contacts array.
        $object     = $this->magicMapper->find($objectUuid);
        $contactIds = $object->getContacts() ?? [];

        $contactIds = array_values(array_filter(
            $contactIds,
            static function (string $uid) use ($contactUid): bool {
                return $uid !== $contactUid;
            }
        ));

        $object->setContacts($contactIds);
        $this->magicMapper->update($object);
    }//end unlinkContact()

    /**
     * Find all objects linked to a contact.
     *
     * @param string $contactUid The contact UID.
     *
     * @return array Array of linked objects.
     */
    public function getObjectsForContact(string $contactUid): array
    {
        return $this->linkedEntityService->reverseLookup('contacts', $contactUid);
    }//end getObjectsForContact()

    /**
     * Delete all contact links for an object (cleanup on object deletion).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return void
     */
    public function deleteLinksForObject(string $objectUuid): void
    {
        try {
            $object     = $this->magicMapper->find($objectUuid);
            $contactIds = $object->getContacts() ?? [];

            // Clean vCard properties for each contact.
            foreach ($contactIds as $contactUid) {
                $this->cleanVcardProperties($contactUid);
            }

            $object->setContacts(null);
            $this->magicMapper->update($object);
        } catch (Exception $e) {
            $this->logger->warning('[ContactService] deleteLinksForObject failed: ' . $e->getMessage());
        }
    }//end deleteLinksForObject()

    /**
     * Enrich a contact UID with data from CardDAV.
     *
     * @param string $contactUid The contact UID.
     *
     * @return array Enriched contact data.
     */
    private function enrichContact(string $contactUid): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ['id' => $contactUid, 'label' => 'Not found'];
        }

        $principal    = 'principals/users/' . $user->getUID();
        $addressbooks = $this->cardDavBackend->getAddressBooksForUser($principal);

        foreach ($addressbooks as $addressbook) {
            $cards = $this->cardDavBackend->getCards($addressbook['id']);
            foreach ($cards as $card) {
                if (isset($card['carddata']) === false) {
                    continue;
                }

                try {
                    $vcard = Reader::read($card['carddata']);
                    $uid   = isset($vcard->UID) === true ? (string) $vcard->UID : '';
                    if ($uid === $contactUid) {
                        return [
                            'id'    => $contactUid,
                            'name'  => isset($vcard->FN) === true ? (string) $vcard->FN : $contactUid,
                            'email' => isset($vcard->EMAIL) === true ? (string) $vcard->EMAIL : null,
                        ];
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        return ['id' => $contactUid, 'label' => 'Not found'];
    }//end enrichContact()

    /**
     * Remove X-OPENREGISTER-* properties from a contact's vCard.
     *
     * @param string $contactUid The contact UID.
     *
     * @return void
     */
    private function cleanVcardProperties(string $contactUid): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        $principal    = 'principals/users/' . $user->getUID();
        $addressbooks = $this->cardDavBackend->getAddressBooksForUser($principal);

        foreach ($addressbooks as $addressbook) {
            $cards = $this->cardDavBackend->getCards($addressbook['id']);
            foreach ($cards as $card) {
                if (isset($card['carddata']) === false) {
                    continue;
                }

                try {
                    $vcard = Reader::read($card['carddata']);
                    $uid   = isset($vcard->UID) === true ? (string) $vcard->UID : '';
                    if ($uid === $contactUid) {
                        unset($vcard->{'X-OPENREGISTER-OBJECT'});
                        unset($vcard->{'X-OPENREGISTER-ROLE'});
                        $this->cardDavBackend->updateCard(
                            $addressbook['id'],
                            $card['uri'],
                            $vcard->serialize()
                        );
                        return;
                    }
                } catch (Exception $e) {
                    $this->logger->warning(
                        '[ContactService] Failed to clean vCard for ' . $contactUid . ': ' . $e->getMessage()
                    );
                }
            }
        }
    }//end cleanVcardProperties()

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

        $principal    = 'principals/users/' . $user->getUID();
        $addressbooks = $this->cardDavBackend->getAddressBooksForUser($principal);

        if (empty($addressbooks) === true) {
            return null;
        }

        return $addressbooks[0];
    }//end findUserAddressbook()
}//end class

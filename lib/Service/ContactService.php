<?php

/**
 * ContactService
 *
 * Service that wraps CardDAV vCard operations for linking contacts to OpenRegister objects.
 * Uses dual storage: X-OPENREGISTER-* vCard properties + openregister_contact_links table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

/**
 * ContactService manages contact-to-object links via dual storage.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Class exposes get/link/unlink/list for
 * contact-to-object bindings and an internal vCard enrichment path; complexity is distributed across
 * multiple private helpers and is not reducible without splitting the dual-storage
 * (vCard + link table) abstraction.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Depends on ContactLinkMapper, CardDavBackend,
 * IUserSession, LoggerInterface, DateTime, and the Sabre VObject Reader — each is a required
 * integration point for the dual CardDAV+link-table storage strategy.
 * @SuppressWarnings(PHPMD.StaticAccess)             Sabre\VObject\Reader::read() is a static factory; Sabre
 * provides no injectable alternative in the library.
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
     * URL generator (webroot-aware deep links).
     *
     * @var IURLGenerator
     */
    private readonly IURLGenerator $urlGenerator;

    /**
     * Constructor.
     *
     * @param ContactLinkMapper $contactLinkMapper Contact link mapper
     * @param CardDavBackend    $cardDavBackend    CardDAV backend
     * @param IUserSession      $userSession       User session
     * @param LoggerInterface   $logger            Logger
     * @param IURLGenerator     $urlGenerator      URL generator
     *
     * @return void
     */
    public function __construct(
        ContactLinkMapper $contactLinkMapper,
        CardDavBackend $cardDavBackend,
        IUserSession $userSession,
        LoggerInterface $logger,
        IURLGenerator $urlGenerator
    ) {
        $this->contactLinkMapper = $contactLinkMapper;
        $this->cardDavBackend    = $cardDavBackend;
        $this->userSession       = $userSession;
        $this->logger            = $logger;
        $this->urlGenerator      = $urlGenerator;
    }//end __construct()

    /**
     * Enrichment TTL for cached vCard fields.
     *
     * `getContactsForObject()` re-reads the vCard (phone/org/avatar)
     * when the cached link row is older than this. The cached values
     * still take precedence so the serialised payload remains stable
     * on the hot path; the re-enrichment back-fills only when CardDAV
     * disagrees with the cache, and the update is best-effort.
     *
     * @var int Seconds. Defaults to 24h.
     */
    private const ENRICHMENT_TTL_SECONDS = 86400;

    /**
     * Get all contact links for an object.
     *
     * Each row is the canonical {@see ContactLink::jsonSerialize()} payload
     * enriched with `phone`, `org`, and `avatarUrl` resolved from the
     * underlying vCard. The bespoke CnContactsCard surface
     * (`single-entity` chip) reads `avatarUrl` for the round avatar; the
     * `detail-page` and tab surfaces use `phone` + `org` to differentiate
     * organisational vs. personal contacts.
     *
     * Enrichment is best-effort:
     *   * If CardDAV throws or the card no longer exists, the link is
     *     still returned but `phone` / `org` / `avatarUrl` are `null`.
     *   * If the vCard is present but lacks any of TEL / ORG / PHOTO,
     *     the corresponding field is `null`.
     *   * The cached `phone` / `org` / `avatar_url` columns on the row
     *     are used as long as the link is younger than
     *     `ENRICHMENT_TTL_SECONDS`; older rows trigger a fresh vCard
     *     read + a non-blocking persist of the re-enriched values.
     *
     * Idempotent: re-running the enrichment doesn't double-write — the
     * widened keys are computed fresh each call.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return array{results: array, total: int}
     *
     * @spec openspec/specs/contacts-actions/spec.md
     */
    public function getContactsForObject(string $objectUuid): array
    {
        $links = $this->contactLinkMapper->findByObjectUuid($objectUuid);
        $total = $this->contactLinkMapper->countByObjectUuid($objectUuid);

        $now = new DateTime();

        $results = array_map(
            function (ContactLink $link) use ($now): array {
                $row = $link->jsonSerialize();

                // Decide whether to refresh the cached fields. A link
                // without any cached values is always refreshed; a stale
                // link (older than ENRICHMENT_TTL_SECONDS) is refreshed
                // and the updated values are persisted opportunistically.
                $linkedAt        = $link->getLinkedAt();
                $isStale         = (
                    $linkedAt === null
                    || ($now->getTimestamp() - $linkedAt->getTimestamp()) > self::ENRICHMENT_TTL_SECONDS
                );
                $hasCachedValues = (
                    $link->getPhone() !== null
                    || $link->getOrg() !== null
                    || $link->getAvatarUrl() !== null
                );

                if ($hasCachedValues === false || $isStale === true) {
                    $vfields = $this->extractVcardFields(
                        addressbookId: $link->getAddressbookId(),
                        contactUri: $link->getContactUri(),
                        contactUid: $link->getContactUid()
                    );

                    // Persist the freshened values + bump linkedAt so
                    // the next read can rely on the cache. Best-effort;
                    // a DB failure here doesn't break the list call.
                    try {
                        $link->setPhone($vfields['phone']);
                        $link->setOrg($vfields['org']);
                        $link->setAvatarUrl($vfields['avatarUrl']);
                        $this->contactLinkMapper->update($link);
                    } catch (\Throwable $e) {
                        $this->logger->debug(
                            'Failed to persist re-enriched vCard fields: '.$e->getMessage()
                        );
                    }

                    $row['phone']     = $vfields['phone'];
                    $row['org']       = $vfields['org'];
                    $row['avatarUrl'] = $vfields['avatarUrl'];
                }//end if

                // Deep-link to the specific contact in the Contacts app.
                $deepLink = $this->buildContactDeepLink(
                    addressbookId: $link->getAddressbookId(),
                    contactUid: $link->getContactUid()
                );
                if ($deepLink !== null) {
                    $row['url'] = $deepLink;
                }

                return $row;
            },
            $links
        );

        return ['results' => $results, 'total' => $total];
    }//end getContactsForObject()

    /**
     * Build a webroot-aware deep-link to a specific contact.
     *
     * Nextcloud Contacts (8.x) opens a contact at
     * `/apps/contacts/All contacts/{token}` where `token` is the
     * base64url-encoded `"{uid}~{addressbookUri}"`. The addressbook URI is
     * resolved from the numeric addressbook id via the CardDAV backend.
     *
     * Returns null (record gets no `url`) when the uid is missing or the
     * addressbook can no longer be resolved, rather than a broken link.
     *
     * @param int|null    $addressbookId The numeric CardDAV addressbook id.
     * @param string|null $contactUid    The vCard UID of the contact.
     *
     * @return string|null The deep-link URL, or null when not resolvable.
     */
    private function buildContactDeepLink(?int $addressbookId, ?string $contactUid): ?string
    {
        if ($contactUid === null || $contactUid === '' || $addressbookId === null) {
            return null;
        }

        try {
            $addressbook = $this->cardDavBackend->getAddressBookById($addressbookId);
            if (is_array($addressbook) === false) {
                return null;
            }

            $addressbookUri = ($addressbook['uri'] ?? null);
            if ($addressbookUri === null || $addressbookUri === '') {
                return null;
            }

            // NC Contacts opens a contact via `atob(routeParam)`, i.e. STANDARD
            // base64 of "{uid}~{addressbookUri}" (matches its own generated
            // links, e.g. base64("admin~z-server-generated--system")). The
            // token is URL-encoded so any `+`/`/`/`=` survive as a single path
            // segment; NC routing decodes it back to standard base64 before atob.
            $token = rawurlencode(base64_encode($contactUid.'~'.$addressbookUri));
            $base  = $this->urlGenerator->linkToRoute('contacts.page.index');

            return rtrim($base, '/').'/'.rawurlencode('All contacts').'/'.$token;
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to build contact deep-link: '.$e->getMessage());
            return null;
        }//end try
    }//end buildContactDeepLink()

    /**
     * Resolve the supplementary vCard fields (`phone`, `org`, `avatarUrl`)
     * for a link row.
     *
     * Returns a defaults-shaped array even when the lookup fails so the
     * caller never sees missing keys.
     *
     * @param int|null    $addressbookId The CardDAV addressbook id.
     * @param string|null $contactUri    The vCard uri (e.g. `jan.vcf`).
     * @param string|null $contactUid    The vCard UID (used to build a
     *                                   fallback avatar route when PHOTO
     *                                   is absent).
     *
     * @return array{phone: ?string, org: ?string, avatarUrl: ?string}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Each vCard property (TEL, ORG, PHOTO) requires
     * distinct handling: TEL is iterable or scalar, PHOTO may be URI/data-URL/raw bytes requiring
     * format detection; collapsing branches loses semantic specificity.
     * @SuppressWarnings(PHPMD.NPathComplexity)       TEL iterable vs scalar + PHOTO URI vs data vs bytes +
     * contactUid fallback produce many distinct execution paths; all are in-method because extracting
     * each to a helper would scatter the single "resolve phone+org+avatar" contract.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The method resolves phone, org, and avatar from
     * vCard in one pass; splitting into three sub-helpers would require re-reading and re-parsing the
     * vCard three times or passing the parsed vCard object across helpers.
     */
    private function extractVcardFields(?int $addressbookId, ?string $contactUri, ?string $contactUid): array
    {
        $defaults = ['phone' => null, 'org' => null, 'avatarUrl' => null];

        if ($addressbookId === null || $contactUri === null || $contactUri === '') {
            // No CardDAV coordinates → still emit a fallback avatar URL
            // when we have a uid so the chip can render an initials
            // placeholder via the Contacts app's avatar endpoint.
            if ($contactUid !== null && $contactUid !== '') {
                $defaults['avatarUrl'] = '/index.php/apps/contacts/photo/'.rawurlencode($contactUid);
            }

            return $defaults;
        }

        try {
            $card = $this->cardDavBackend->getCard($addressbookId, $contactUri);
        } catch (\Throwable $e) {
            $this->logger->debug('vCard lookup failed for enrichment: '.$e->getMessage());
            return $defaults;
        }

        if ($card === false || isset($card['carddata']) === false) {
            return $defaults;
        }

        try {
            $vcard = Reader::read($card['carddata']);
        } catch (\Throwable $e) {
            $this->logger->debug('vCard parse failed for enrichment: '.$e->getMessage());
            return $defaults;
        }

        $phone = null;
        if (isset($vcard->TEL) === true) {
            // TEL may be a single value or an iterable of typed entries.
            // Prefer the first non-empty entry — the bespoke UI shows
            // one number per chip; richer multi-number rendering belongs
            // in the upcoming reverse-lookup flyout (AD-3).
            $tel = $vcard->TEL;
            if (is_iterable($tel) === true) {
                foreach ($tel as $entry) {
                    $value = (string) $entry;
                    if ($value !== '') {
                        $phone = $value;
                        break;
                    }
                }
            }

            if (is_iterable($tel) === false) {
                $value = (string) $tel;
                if ($value !== '') {
                    $phone = $value;
                }
            }
        }//end if

        $org = null;
        if (isset($vcard->ORG) === true) {
            $value = (string) $vcard->ORG;
            // VCard ORG often uses semicolon-separated component fields
            // (`Acme;Engineering;Backend`) — surface only the primary
            // organisation name.
            $primary = trim(explode(';', $value)[0]);
            if ($primary !== '') {
                $org = $primary;
            }
        }

        $avatarUrl = null;
        if (isset($vcard->PHOTO) === true) {
            // Sabre auto-classifies vCard 3.0 PHOTO bodies as Binary when
            // no `VALUE=URI` parameter is present, which means casting to
            // string returns the base64-decoded blob. Use the raw
            // mime-dir value and decide based on its shape instead.
            $photoProp = $vcard->PHOTO;
            $rawValue  = '';
            if (method_exists($photoProp, 'getRawMimeDirValue') === true) {
                $rawValue = (string) $photoProp->getRawMimeDirValue();
            }

            if ($rawValue === '') {
                $rawValue = (string) $photoProp;
            }

            if ($rawValue !== '') {
                // Trim any line-folding artefacts ("https//examplecom/janjpg"
                // can occur when Sabre eagerly strips colons/dots from
                // the binary view of a URL; in that case we fall back to
                // the per-uid Contacts route below).
                if (preg_match('#^(https?://|data:)#i', $rawValue) === 1) {
                    $avatarUrl = $rawValue;
                }

                if (preg_match('#^(https?://|data:)#i', $rawValue) === 0) {
                    // Otherwise treat as inline image bytes; wrap as data URL.
                    $mediaType = 'image/jpeg';
                    if (isset($photoProp['TYPE']) === true) {
                        $typeParam = (string) $photoProp['TYPE'];
                        if ($typeParam !== '') {
                            $mediaType = 'image/'.strtolower($typeParam);
                        }
                    }

                    // Strip any embedded `data:` prefix Sabre may have
                    // left in place when round-tripping a data URL.
                    if (str_starts_with($rawValue, 'data:') === true) {
                        $avatarUrl = $rawValue;
                    }

                    if (str_starts_with($rawValue, 'data:') === false) {
                        $avatarUrl = 'data:'.$mediaType.';base64,'.$rawValue;
                    }
                }
            }//end if
        }//end if

        // Fallback to the Contacts app's per-uid avatar route when no
        // PHOTO property is embedded — the route 404s gracefully when
        // the contact has no photo, which the UI treats as "use the
        // initials placeholder".
        if ($avatarUrl === null && $contactUid !== null && $contactUid !== '') {
            $avatarUrl = '/index.php/apps/contacts/photo/'.rawurlencode($contactUid);
        }

        return [
            'phone'     => $phone,
            'org'       => $org,
            'avatarUrl' => $avatarUrl,
        ];
    }//end extractVcardFields()

    /**
     * Link an existing contact to an object.
     *
     * Tier-2: the call is idempotent — if a link row already exists for
     * `(objectUuid, contactUid)` (enforced by the
     * `idx_contact_object_uid_uniq` index) the row is updated in-place
     * with the freshened cached fields and the new role. Callers that
     * relied on the previous "always-insert" semantics still get a
     * persisted entity back.
     *
     * @param string      $objectUuid    The object UUID.
     * @param int         $registerId    The register ID.
     * @param int         $addressbookId The addressbook ID.
     * @param string      $contactUri    The contact URI in the addressbook.
     * @param string|null $role          The role of this contact on the object.
     * @param int|null    $schemaId      Optional schema id (Tier-2).
     *
     * @return ContactLink The created or updated link.
     *
     * @throws Exception If the contact does not exist.
     *
     * @spec openspec/specs/contacts-actions/spec.md
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Single switch on upsert state — extracting a sub-method
     *                                                doesn't add clarity and would split the vCard hydration
     *                                                from the DB write that consumes it.
     * @SuppressWarnings(PHPMD.NPathComplexity)       insert vs update branches plus optional role/schemaId
     * null-checks plus best-effort persist error path expand NPath; each path serves a distinct upsert
     * variant in the idempotent link contract.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) linkContact() covers card lookup, user auth, vCard
     * hydration, existence check, and insert-or-update in sequence; splitting would scatter the
     * idempotent upsert contract across multiple methods.
     */
    public function linkContact(
        string $objectUuid,
        int $registerId,
        int $addressbookId,
        string $contactUri,
        ?string $role=null,
        ?int $schemaId=null
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
        $vcard      = Reader::read($card['carddata']);
        $contactUid = '';
        if (isset($vcard->UID) === true) {
            $contactUid = (string) $vcard->UID;
        }

        $displayName = null;
        if (isset($vcard->FN) === true) {
            $displayName = (string) $vcard->FN;
        }

        $email = null;
        if (isset($vcard->EMAIL) === true) {
            $email = (string) $vcard->EMAIL;
        }

        // Tier-2: extract the widened payload (phone / org / avatar) so
        // the row can serve future list calls without round-tripping
        // CardDAV.
        $vfields = $this->extractVcardFields(
            addressbookId: $addressbookId,
            contactUri: $contactUri,
            contactUid: $contactUid
        );

        // Add X-OPENREGISTER-* properties to the vCard.
        $vcard->add('X-OPENREGISTER-OBJECT', $objectUuid);
        if ($role !== null) {
            $vcard->add('X-OPENREGISTER-ROLE', $role);
        }

        $this->cardDavBackend->updateCard($addressbookId, $contactUri, $vcard->serialize());

        // Upsert: if a row already exists for this (objectUuid, contactUid)
        // pair, refresh its cached fields + role instead of inserting.
        $existing = null;
        if ($contactUid !== '') {
            $existing = $this->contactLinkMapper->findByObjectAndContact(
                objectUuid: $objectUuid,
                contactUid: $contactUid
            );
        }

        if ($existing !== null) {
            $existing->setRegisterId($registerId);
            if ($schemaId !== null) {
                $existing->setSchemaId($schemaId);
            }

            $existing->setAddressbookId($addressbookId);
            $existing->setContactUri($contactUri);
            $existing->setDisplayName($displayName);
            $existing->setEmail($email);
            $existing->setPhone($vfields['phone']);
            $existing->setOrg($vfields['org']);
            $existing->setAvatarUrl($vfields['avatarUrl']);
            $existing->setRole($role);
            $existing->setLinkedBy($user->getUID());
            $existing->setLinkedAt(new DateTime());

            return $this->contactLinkMapper->update($existing);
        }

        // Create DB record.
        $link = new ContactLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        if ($schemaId !== null) {
            $link->setSchemaId($schemaId);
        }

        $link->setContactUid($contactUid);
        $link->setAddressbookId($addressbookId);
        $link->setContactUri($contactUri);
        $link->setDisplayName($displayName);
        $link->setEmail($email);
        $link->setPhone($vfields['phone']);
        $link->setOrg($vfields['org']);
        $link->setAvatarUrl($vfields['avatarUrl']);
        $link->setRole($role);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->contactLinkMapper->insert($link);
    }//end linkContact()

    /**
     * Create a new contact and link it to an object.
     *
     * Tier-2: extended to persist `phone` / `org` straight from the
     * caller-supplied payload, and to accept an optional `schemaId`
     * + free-form `org` field. The avatar URL falls back to the
     * per-uid Contacts route (no PHOTO is set on freshly-created
     * vCards yet).
     *
     * @param string   $objectUuid The object UUID.
     * @param int      $registerId The register ID.
     * @param array    $data       Contact data: `fullName` or `displayName`, `email`,
     *                             `phone`, `org`, `role`.
     * @param int|null $schemaId   Optional schema id (Tier-2).
     *
     * @return ContactLink The created link.
     *
     * @throws Exception If no user or addressbook.
     *
     * @spec openspec/specs/contacts-actions/spec.md
     */
    public function createAndLinkContact(
        string $objectUuid,
        int $registerId,
        array $data,
        ?int $schemaId=null
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
        // Accept both `fullName` (existing) and `displayName` (spec /
        // dialog form field) as the human-readable label.
        $displayName = $data['displayName'] ?? $data['fullName'] ?? 'Unknown';
        $phone       = $data['phone'] ?? null;
        $email       = $data['email'] ?? null;
        $org         = $data['org'] ?? null;

        // Build vCard.
        $lines   = [];
        $lines[] = 'BEGIN:VCARD';
        $lines[] = 'VERSION:3.0';
        $lines[] = 'UID:'.$uid;
        $lines[] = 'FN:'.$displayName;

        if (empty($email) === false) {
            $lines[] = 'EMAIL;TYPE=INTERNET:'.$email;
        }

        if (empty($phone) === false) {
            $lines[] = 'TEL;TYPE=CELL:'.$phone;
        }

        if (empty($org) === false) {
            $lines[] = 'ORG:'.$org;
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
        if ($schemaId !== null) {
            $link->setSchemaId($schemaId);
        }

        $link->setContactUid($uid);
        $link->setAddressbookId($addressbook['id']);
        $link->setContactUri($contactUri);
        $link->setDisplayName($displayName);
        $link->setEmail($email);
        $link->setPhone($phone);
        $link->setOrg($org);
        // PHOTO not set yet — fall back to the per-uid Contacts route.
        $link->setAvatarUrl('/index.php/apps/contacts/photo/'.rawurlencode($uid));
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
     *
     * @spec openspec/specs/contacts-actions/spec.md
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
     * Idempotent: tolerates a missing or corrupt vCard so the link row
     * can always be cleaned. If the underlying vCard has been removed
     * from the addressbook (e.g. user deleted the contact via NC
     * Contacts), the CardDAV custom-property cleanup is skipped and the
     * link row is still dropped. Any Throwable from the cleanup path is
     * caught and logged at warning level — the link row deletion
     * proceeds regardless so orphan rows are recoverable through this
     * path rather than only via direct DB DELETE.
     *
     * @param int $linkId The link ID.
     *
     * @return void
     *
     * @throws Exception If the link row itself isn't found (404).
     *
     * @spec openspec/specs/contacts-actions/spec.md
     */
    public function unlinkContact(int $linkId): void
    {
        try {
            $link = $this->contactLinkMapper->find($linkId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new Exception('Contact link not found', 404);
        }

        // Best-effort: remove X-OPENREGISTER-* from the vCard. If the
        // vCard is gone or unreadable, skip the cleanup and proceed with
        // the link-row delete — orphan link rows would otherwise only
        // be cleanable via direct DB DELETE.
        try {
            $card = $this->cardDavBackend->getCard($link->getAddressbookId(), $link->getContactUri());
            if ($card !== false) {
                $vcard = Reader::read($card['carddata']);
                unset($vcard->{'X-OPENREGISTER-OBJECT'});
                unset($vcard->{'X-OPENREGISTER-ROLE'});
                $this->cardDavBackend->updateCard($link->getAddressbookId(), $link->getContactUri(), $vcard->serialize());
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to clean vCard properties (link row will still be removed): '.$e->getMessage(),
                [
                    'linkId'        => $linkId,
                    'addressbookId' => $link->getAddressbookId(),
                    'contactUri'    => $link->getContactUri(),
                ]
            );
        }

        $this->contactLinkMapper->delete($link);
    }//end unlinkContact()

    /**
     * Unlink a contact from a specific object by contact uid.
     *
     * Convenience overload of `unlinkContact(int $linkId)` for callers
     * that hold the vCard's UID (e.g. the REST destroy endpoint, which
     * routes on `{contactUid}` because the link id isn't visible in the
     * URL). Resolves the link row via
     * `ContactLinkMapper::findByObjectAndContact()` and delegates to the
     * id-based path; tolerates missing rows + missing vCards exactly
     * the same way.
     *
     * @param string $objectUuid The object UUID.
     * @param string $contactUid The vCard UID.
     *
     * @return void
     *
     * @throws Exception If no row is found for the (objectUuid, contactUid) pair.
     *
     * @spec exclude Thin (objectUuid,contactUid)->linkId overload; resolves the row then delegates to the
     *              already-specced unlinkContact (contacts-actions#task-1).
     */
    public function unlinkContactByUid(string $objectUuid, string $contactUid): void
    {
        $link = $this->contactLinkMapper->findByObjectAndContact(
            objectUuid: $objectUuid,
            contactUid: $contactUid
        );
        if ($link === null) {
            throw new Exception('Contact link not found', 404);
        }

        $this->unlinkContact(linkId: $link->getId());
    }//end unlinkContactByUid()

    /**
     * Find all objects linked to a contact.
     *
     * @param string $contactUid The contact UID.
     *
     * @return array Array of contact links with object UUIDs and roles.
     *
     * @spec openspec/specs/contacts-actions/spec.md
     */
    public function getObjectsForContact(string $contactUid): array
    {
        // IDOR guard: only surface object links for contacts that live in the
        // caller's own addressbooks. Without this any authenticated user could
        // pass an arbitrary (enumerable CardDAV) contact UID and learn which
        // OpenRegister objects reference contacts belonging to other users.
        $allowedAddressbookIds = $this->currentUserAddressbookIds();
        if ($allowedAddressbookIds === []) {
            return [];
        }

        $links = $this->contactLinkMapper->findByContactUid($contactUid);

        return array_values(
            array_map(
                static function (ContactLink $link): array {
                    return $link->jsonSerialize();
                },
                array_filter(
                    $links,
                    static function (ContactLink $link) use ($allowedAddressbookIds): bool {
                        return in_array((int) $link->getAddressbookId(), $allowedAddressbookIds, true);
                    }
                )
            )
        );
    }//end getObjectsForContact()

    /**
     * Collect the addressbook IDs owned by the current session user.
     *
     * Used to scope contact-link reads to the caller's own addressbooks so a
     * user cannot resolve links for contacts they do not own.
     *
     * @return array<int, int> Addressbook IDs, or [] when anonymous / none.
     */
    private function currentUserAddressbookIds(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        $principal    = 'principals/users/'.$user->getUID();
        $addressbooks = $this->cardDavBackend->getAddressBooksForUser($principal);

        return array_map(
            static function (array $addressbook): int {
                return (int) $addressbook['id'];
            },
            $addressbooks
        );
    }//end currentUserAddressbookIds()

    /**
     * Delete all contact links for an object (cleanup).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return void
     *
     * @spec openspec/specs/contacts-actions/spec.md
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
     *
     * @spec openspec/specs/contacts-actions/spec.md
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

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
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCP\IURLGenerator;
use OCP\IUser;
use Sabre\VObject\Document;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
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
class ContactService implements ContactServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapperInterface $contactLinkMapper Contact link mapper
	 * @param CardDavBackend $cardDavBackend CardDAV backend
	 * @param LinkSessionRuntime $runtime User session and logger
	 * @param IURLGenerator $urlGenerator URL generator
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContactLinkMapperInterface $contactLinkMapper,
		private readonly CardDavBackend $cardDavBackend,
		private readonly LinkSessionRuntime $runtime,
		private readonly IURLGenerator $urlGenerator,
	) {
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
	public function getContactsForObject(string $objectUuid): array {
		$links = $this->contactLinkMapper->findByObjectUuid($objectUuid);
		$total = $this->contactLinkMapper->countByObjectUuid($objectUuid);

		$now = new DateTime();

		$results = array_map(
			fn (ContactLink $link): array => $this->contactRow(link: $link, now: $now),
			$links
		);

		return ['results' => $results, 'total' => $total];
	}//end getContactsForObject()

	/**
	 * The listed row of one link: re-enriched from the vCard when it has no cached values or is stale.
	 *
	 * A user link is returned as stored: it has no vCard.
	 *
	 * @param ContactLink $link The link row.
	 * @param DateTime    $now  The listing time.
	 *
	 * @return array<string,mixed>
	 */
	private function contactRow(ContactLink $link, DateTime $now): array {
		$row = $link->jsonSerialize();

		// Decide whether to refresh the cached fields. A link
		// without any cached values is always refreshed; a stale
		// link (older than ENRICHMENT_TTL_SECONDS) is refreshed
		// and the updated values are persisted opportunistically.
		$isStale = $this->isStale(link: $link, now: $now);
		$hasCachedValues = $this->hasCachedValues(link: $link);

		if ($link->isUserLink() === true) {
			return $row;
		}

		if ($hasCachedValues === false || $isStale === true) {
			$row = $this->reEnrichedRow(link: $link, row: $row);
		}

		return $this->withDeepLink(link: $link, row: $row);
	}//end contactRow()

	/**
	 * Whether the link's cached fields are older than the enrichment TTL, or were never stamped.
	 *
	 * @param ContactLink $link The link row.
	 * @param DateTime    $now  The listing time.
	 *
	 * @return bool
	 */
	private function isStale(ContactLink $link, DateTime $now): bool {
		$linkedAt = $link->getLinkedAt();
		return (
			$linkedAt === null
			|| ($now->getTimestamp() - $linkedAt->getTimestamp()) > self::ENRICHMENT_TTL_SECONDS
		);
	}//end isStale()

	/**
	 * Whether the link carries any cached vCard field.
	 *
	 * @param ContactLink $link The link row.
	 *
	 * @return bool
	 */
	private function hasCachedValues(ContactLink $link): bool {
		return (
			$link->getPhone() !== null
			|| $link->getOrg() !== null
			|| $link->getAvatarUrl() !== null
		);
	}//end hasCachedValues()

	/**
	 * Re-read phone, org and avatar from the vCard into the row, persisting them best-effort.
	 *
	 * @param ContactLink         $link The link row.
	 * @param array<string,mixed> $row  The serialised row.
	 *
	 * @return array<string,mixed>
	 */
	private function reEnrichedRow(ContactLink $link, array $row): array {
		$vfields = $this->extractVcardFields(
			card: $this->cardRefOf(link: $link),
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
			$this->runtime->logger->debug(
				'Failed to persist re-enriched vCard fields: ' . $e->getMessage()
			);
		}

		$row['phone'] = $vfields['phone'];
		$row['org'] = $vfields['org'];
		$row['avatarUrl'] = $vfields['avatarUrl'];
		return $row;
	}//end reEnrichedRow()

	/**
	 * Add the deep-link to the specific contact in the Contacts app, when it resolves.
	 *
	 * @param ContactLink         $link The link row.
	 * @param array<string,mixed> $row  The serialised row.
	 *
	 * @return array<string,mixed>
	 */
	private function withDeepLink(ContactLink $link, array $row): array {
		$addressbookId = $link->getAddressbookId();
		$contactUid = (string)$link->getContactUid();
		if ($contactUid === '' || $addressbookId === null) {
			return $row;
		}

		$deepLink = $this->buildContactDeepLink(addressbookId: $addressbookId, contactUid: $contactUid);
		if ($deepLink !== null) {
			$row['url'] = $deepLink;
		}

		return $row;
	}//end withDeepLink()

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
	 * @param int    $addressbookId The numeric CardDAV addressbook id.
	 * @param string $contactUid    The vCard UID of the contact, not empty.
	 *
	 * @return string|null The deep-link URL, or null when not resolvable.
	 */
	private function buildContactDeepLink(int $addressbookId, string $contactUid): ?string {
		try {
			$addressbookUri = $this->addressbookUri(addressbookId: $addressbookId);
			if ($addressbookUri === null) {
				return null;
			}

			// NC Contacts opens a contact via `atob(routeParam)`, i.e. STANDARD
			// base64 of "{uid}~{addressbookUri}" (matches its own generated
			// links, e.g. base64("admin~z-server-generated--system")). The
			// token is URL-encoded so any `+`/`/`/`=` survive as a single path
			// segment; NC routing decodes it back to standard base64 before atob.
			$token = rawurlencode(base64_encode($contactUid . '~' . $addressbookUri));
			$base = $this->urlGenerator->linkToRoute('contacts.page.index');

			return rtrim($base, '/') . '/' . rawurlencode('All contacts') . '/' . $token;
		} catch (\Throwable $e) {
			$this->runtime->logger->debug('Failed to build contact deep-link: ' . $e->getMessage());
			return null;
		}//end try
	}//end buildContactDeepLink()

	/**
	 * The uri of a CardDAV addressbook, or null when it cannot be resolved or has none.
	 *
	 * @param int $addressbookId The numeric CardDAV addressbook id.
	 *
	 * @return mixed The uri as stored, or null.
	 */
	private function addressbookUri(int $addressbookId): mixed {
		$addressbook = $this->cardDavBackend->getAddressBookById($addressbookId);
		if (is_array($addressbook) === false) {
			return null;
		}

		$addressbookUri = ($addressbook['uri'] ?? null);
		if ($addressbookUri === null || $addressbookUri === '') {
			return null;
		}

		return $addressbookUri;
	}//end addressbookUri()

	/**
	 * Resolve the supplementary vCard fields (`phone`, `org`, `avatarUrl`)
	 * for a link row.
	 *
	 * Returns a defaults-shaped array even when the lookup fails so the
	 * caller never sees missing keys.
	 *
	 * @param ContactCardRef|null $card       The vCard (e.g. `jan.vcf` in an
	 *                                        addressbook); null when the row
	 *                                        lacks an id or uri.
	 * @param string|null         $contactUid The vCard UID (used to build a
	 *                                        fallback avatar route when PHOTO
	 *                                        is absent).
	 *
	 * @return array{phone: ?string, org: ?string, avatarUrl: ?string}
	 */
	private function extractVcardFields(?ContactCardRef $card, ?string $contactUid): array {
		$defaults = ['phone' => null, 'org' => null, 'avatarUrl' => null];

		// No CardDAV coordinates: still emit a fallback avatar URL when
		// there is a uid, so the chip can render an initials placeholder.
		if ($this->hasCardCoordinates(card: $card) === false) {
			$defaults['avatarUrl'] = $this->contactPhotoRoute(contactUid: $contactUid);
			return $defaults;
		}

		return $this->fieldsFromCard(
			vcard: $this->readCard(addressbookId: $card->addressbookId, contactUri: $card->contactUri),
			contactUid: $contactUid
		);
	}//end extractVcardFields()

	/**
	 * The phone, organisation and avatar URL of a parsed vCard; all null
	 * when the card could not be read.
	 *
	 * Without an embedded PHOTO the Contacts app's per-uid avatar route is
	 * used; it 404s when the contact has no photo, which the UI treats as
	 * "use the initials placeholder".
	 *
	 * @param Document|null $vcard      The parsed vCard, or null.
	 * @param string|null   $contactUid The vCard UID.
	 *
	 * @return array{phone: ?string, org: ?string, avatarUrl: ?string}
	 */
	private function fieldsFromCard(?Document $vcard, ?string $contactUid): array {
		if ($vcard === null) {
			return ['phone' => null, 'org' => null, 'avatarUrl' => null];
		}

		return [
			'phone' => $this->firstPhone(vcard: $vcard),
			'org' => $this->primaryOrganisation(vcard: $vcard),
			'avatarUrl' => $this->photoUrl(vcard: $vcard) ?? $this->contactPhotoRoute(contactUid: $contactUid),
		];
	}//end fieldsFromCard()

	/**
	 * The vCard a link row points at; null when it lacks an addressbook id or a vCard uri.
	 *
	 * @param ContactLink $link The link row.
	 *
	 * @return ContactCardRef|null
	 */
	private function cardRefOf(ContactLink $link): ?ContactCardRef {
		$addressbookId = $link->getAddressbookId();
		$contactUri = $link->getContactUri();
		if ($addressbookId === null || $contactUri === null) {
			return null;
		}

		return new ContactCardRef(addressbookId: $addressbookId, contactUri: $contactUri);
	}//end cardRefOf()

	/**
	 * Whether there is a vCard reference with a non-empty vCard uri.
	 *
	 * @param ContactCardRef|null $card The vCard reference.
	 *
	 * @return bool
	 */
	private function hasCardCoordinates(?ContactCardRef $card): bool {
		return $card !== null && $card->contactUri !== '';
	}//end hasCardCoordinates()

	/**
	 * Fetch and parse a vCard, or null when it cannot be read.
	 *
	 * @param int    $addressbookId The CardDAV addressbook id.
	 * @param string $contactUri    The vCard uri.
	 *
	 * @return Document|null
	 */
	private function readCard(int $addressbookId, string $contactUri): ?Document {
		$cardData = $this->fetchCardData(addressbookId: $addressbookId, contactUri: $contactUri);
		if ($cardData === null) {
			return null;
		}

		try {
			return Reader::read($cardData);
		} catch (\Throwable $e) {
			$this->runtime->logger->debug('vCard parse failed for enrichment: ' . $e->getMessage());
			return null;
		}
	}//end readCard()

	/**
	 * The raw vCard data of a card, or null when the backend has none.
	 *
	 * @param int    $addressbookId The CardDAV addressbook id.
	 * @param string $contactUri    The vCard uri.
	 *
	 * @return mixed The `carddata` value, or null.
	 */
	private function fetchCardData(int $addressbookId, string $contactUri): mixed {
		try {
			$card = $this->cardDavBackend->getCard($addressbookId, $contactUri);
		} catch (\Throwable $e) {
			$this->runtime->logger->debug('vCard lookup failed for enrichment: ' . $e->getMessage());
			return null;
		}

		if ($card === false || isset($card['carddata']) === false) {
			return null;
		}

		return $card['carddata'];
	}//end fetchCardData()

	/**
	 * The first non-empty TEL value of a vCard.
	 *
	 * @param Document $vcard The parsed vCard.
	 *
	 * @return string|null
	 */
	private function firstPhone(Document $vcard): ?string {
		foreach ($vcard->select('TEL') as $entry) {
			$value = (string) $entry;
			if ($value !== '') {
				return $value;
			}
		}

		return null;
	}//end firstPhone()

	/**
	 * The primary organisation name of a vCard ORG (`Acme;Engineering` gives `Acme`).
	 *
	 * @param Document $vcard The parsed vCard.
	 *
	 * @return string|null
	 */
	private function primaryOrganisation(Document $vcard): ?string {
		if (isset($vcard->ORG) === false) {
			return null;
		}

		$primary = trim(explode(';', (string) $vcard->ORG)[0]);
		if ($primary === '') {
			return null;
		}

		return $primary;
	}//end primaryOrganisation()

	/**
	 * The avatar URL an embedded PHOTO gives: a URL or data URL as is,
	 * inline image bytes wrapped as a base64 data URL.
	 *
	 * Sabre classifies vCard 3.0 PHOTO bodies without `VALUE=URI` as
	 * Binary, so the raw mime-dir value decides the shape.
	 *
	 * @param Document $vcard The parsed vCard.
	 *
	 * @return string|null
	 */
	private function photoUrl(Document $vcard): ?string {
		if (isset($vcard->PHOTO) === false) {
			return null;
		}

		$photo = $vcard->PHOTO;
		$rawValue = $this->photoRawValue(photo: $photo);
		if ($rawValue === '') {
			return null;
		}

		if (preg_match('#^(https?://|data:)#i', $rawValue) === 1) {
			return $rawValue;
		}

		return 'data:' . $this->photoMediaType(photo: $photo) . ';base64,' . $rawValue;
	}//end photoUrl()

	/**
	 * The raw mime-dir value of a PHOTO, else its string value.
	 *
	 * @param Property $photo The PHOTO property.
	 *
	 * @return string
	 */
	private function photoRawValue(Property $photo): string {
		$rawValue = (string) $photo->getRawMimeDirValue();
		if ($rawValue === '') {
			$rawValue = (string) $photo;
		}

		return $rawValue;
	}//end photoRawValue()

	/**
	 * The media type of an inline PHOTO, from its TYPE parameter (default JPEG).
	 *
	 * @param Property $photo The PHOTO property.
	 *
	 * @return string
	 */
	private function photoMediaType(Property $photo): string {
		$type = $photo['TYPE'];
		if ($type instanceof Parameter === false || (string) $type->getValue() === '') {
			return 'image/jpeg';
		}

		return 'image/' . strtolower((string) $type->getValue());
	}//end photoMediaType()

	/**
	 * The Contacts app's per-uid avatar route, or null without a uid.
	 *
	 * @param string|null $contactUid The vCard UID.
	 *
	 * @return string|null
	 */
	private function contactPhotoRoute(?string $contactUid): ?string {
		if ($contactUid === null || $contactUid === '') {
			return null;
		}

		return '/index.php/apps/contacts/photo/' . rawurlencode($contactUid);
	}//end contactPhotoRoute()

	/**
	 * Tag an existing vCard with its object and role, and save the link row of it.
	 *
	 * Tier-2: the call is idempotent — if a link row already exists for
	 * `(objectUuid, contactUid)` (enforced by the
	 * `idx_contact_object_uid_uniq` index) the row is updated in-place
	 * with the freshened cached fields and the new role.
	 *
	 * @param ContactCardRef   $card      The vCard to link.
	 * @param ContactPlacement $placement Where it is linked, and in what role.
	 *
	 * @return ContactLink The created or updated link.
	 *
	 * @throws Exception If the contact does not exist (404), or nobody is logged in.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function linkCard(ContactCardRef $card, ContactPlacement $placement): ContactLink {
		$found = $this->requireCard(card: $card);
		$user = $this->requireUser();

		$vcard = Reader::read($found['carddata']);
		$contactUid = $this->vcardText(vcard: $vcard, property: 'UID') ?? '';

		// Tier-2: the widened payload (phone / org / avatar) lets the row serve
		// future list calls without round-tripping CardDAV.
		$vfields = $this->extractVcardFields(card: $card, contactUid: $contactUid);

		$this->tagVcard(vcard: $vcard, placement: $placement);

		$this->cardDavBackend->updateCard($card->addressbookId, $card->contactUri, $vcard->serialize());

		$existing = $this->existingLink(objectUuid: $placement->objectUuid, contactUid: $contactUid);
		$link = $existing ?? $this->newLink(objectUuid: $placement->objectUuid, contactUid: $contactUid);
		$this->place(link: $link, placement: $placement);

		$link->setAddressbookId($card->addressbookId);
		$link->setContactUri($card->contactUri);
		$link->setDisplayName($this->vcardText(vcard: $vcard, property: 'FN'));
		$link->setEmail($this->vcardText(vcard: $vcard, property: 'EMAIL'));
		$link->setPhone($vfields['phone']);
		$link->setOrg($vfields['org']);
		$link->setAvatarUrl($vfields['avatarUrl']);
		$link->setRole($placement->role);
		$this->stampLinkedBy(link: $link, uid: $user->getUID());

		return $this->saveLink(link: $link, existing: $existing);
	}//end linkCard()

	/**
	 * The CardDAV row of a vCard that must exist.
	 *
	 * @param ContactCardRef $card The vCard.
	 *
	 * @return array The card row, with its `carddata`.
	 *
	 * @throws Exception If the contact does not exist (404).
	 */
	private function requireCard(ContactCardRef $card): array {
		$found = $this->cardDavBackend->getCard($card->addressbookId, $card->contactUri);
		if ($found === false) {
			throw new Exception('Contact not found', 404);
		}

		return $found;
	}//end requireCard()

	/**
	 * The logged-in user.
	 *
	 * @return IUser
	 *
	 * @throws Exception If nobody is logged in.
	 */
	private function requireUser(): IUser {
		$user = $this->runtime->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		return $user;
	}//end requireUser()

	/**
	 * Put a link row on the placement's register and, when given, its schema.
	 *
	 * @param ContactLink      $link      The link row.
	 * @param ContactPlacement $placement Where it is linked.
	 *
	 * @return void
	 */
	private function place(ContactLink $link, ContactPlacement $placement): void {
		$link->setRegisterId($placement->registerId);
		$this->applySchema(link: $link, schemaId: $placement->schemaId);
	}//end place()

	/**
	 * Record who linked the row, and when.
	 *
	 * @param ContactLink $link The link row.
	 * @param string      $uid  The linking user's id.
	 *
	 * @return void
	 */
	private function stampLinkedBy(ContactLink $link, string $uid): void {
		$link->setLinkedBy($uid);
		$link->setLinkedAt(new DateTime());
	}//end stampLinkedBy()

	/**
	 * Tag a vCard with the object it is linked to and, when given, the role.
	 *
	 * @param Document         $vcard     The vCard.
	 * @param ContactPlacement $placement The linked object and the contact's role.
	 *
	 * @return void
	 */
	private function tagVcard(Document $vcard, ContactPlacement $placement): void {
		$vcard->add('X-OPENREGISTER-OBJECT', $placement->objectUuid);
		if ($placement->role !== null) {
			$vcard->add('X-OPENREGISTER-ROLE', $placement->role);
		}
	}//end tagVcard()

	/**
	 * Set the schema of a link when one is given.
	 *
	 * @param ContactLink $link     The link row.
	 * @param int|null    $schemaId The schema id, or null to leave it.
	 *
	 * @return void
	 */
	private function applySchema(ContactLink $link, ?int $schemaId): void {
		if ($schemaId !== null) {
			$link->setSchemaId($schemaId);
		}
	}//end applySchema()

	/**
	 * Update the existing link row, or insert the new one.
	 *
	 * @param ContactLink      $link     The link to save.
	 * @param ContactLink|null $existing The row it was loaded from, or null for a new row.
	 *
	 * @return ContactLink
	 */
	private function saveLink(ContactLink $link, ?ContactLink $existing): ContactLink {
		if ($existing !== null) {
			return $this->contactLinkMapper->update($link);
		}

		return $this->contactLinkMapper->insert($link);
	}//end saveLink()

	/**
	 * The text of a vCard property, or null when the card does not have it.
	 *
	 * @param Document $vcard    The parsed vCard.
	 * @param string   $property The property name, such as FN or EMAIL.
	 *
	 * @return string|null The first value of the property.
	 */
	private function vcardText(Document $vcard, string $property): ?string {
		if (isset($vcard->$property) === false) {
			return null;
		}

		return (string) $vcard->$property;
	}//end vcardText()

	/**
	 * The link row of a contact on an object, when the contact has a UID and is linked already.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $contactUid The vCard UID, empty when the card has none.
	 *
	 * @return ContactLink|null The existing link.
	 */
	private function existingLink(string $objectUuid, string $contactUid): ?ContactLink {
		if ($contactUid === '') {
			return null;
		}

		return $this->contactLinkMapper->findByObjectAndContact(objectUuid: $objectUuid, contactUid: $contactUid);
	}//end existingLink()

	/**
	 * A new, unsaved link of a contact to an object.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $contactUid The vCard UID.
	 *
	 * @return ContactLink The link.
	 */
	private function newLink(string $objectUuid, string $contactUid): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid($objectUuid);
		$link->setContactUid($contactUid);
		return $link;
	}//end newLink()

	/**
	 * Create a new contact and link it to an object.
	 *
	 * Creates a vCard in the user's first addressbook and inserts the link row of it.
	 * Tier-2: persists `phone` / `org` straight from the caller-supplied payload. The
	 * avatar URL falls back to the per-uid Contacts route (no PHOTO is set on
	 * freshly-created vCards yet).
	 *
	 * @param ContactPlacement $placement Where it is linked, and in what role.
	 * @param array            $data      Contact data: `fullName` or `displayName`, `email`, `phone`, `org`.
	 *
	 * @return ContactLink The created link.
	 *
	 * @throws Exception If no user or addressbook.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function createAndLinkContact(ContactPlacement $placement, array $data): ContactLink {
		$user = $this->requireUser();

		$addressbook = $this->findUserAddressbook();
		if ($addressbook === null) {
			throw new Exception('No addressbook found');
		}

		$contact = ['uid' => strtoupper(bin2hex(random_bytes(16)))] + $this->newContactFields(data: $data);
		$contactUri = $contact['uid'] . '.vcf';

		$this->cardDavBackend->createCard(
			$addressbook['id'],
			$contactUri,
			$this->newCardData(placement: $placement, contact: $contact)
		);

		// Create DB record.
		$link = new ContactLink();
		$link->setObjectUuid($placement->objectUuid);
		$this->place(link: $link, placement: $placement);

		$link->setContactUid($contact['uid']);
		$link->setAddressbookId($addressbook['id']);
		$link->setContactUri($contactUri);
		$link->setDisplayName($contact['displayName']);
		$link->setEmail($contact['email']);
		$link->setPhone($contact['phone']);
		$link->setOrg($contact['org']);
		// PHOTO not set yet — fall back to the per-uid Contacts route.
		$link->setAvatarUrl('/index.php/apps/contacts/photo/' . rawurlencode($contact['uid']));
		$link->setRole($placement->role);
		$this->stampLinkedBy(link: $link, uid: $user->getUID());

		return $this->contactLinkMapper->insert($link);
	}//end createAndLinkContact()

	/**
	 * The display name, email, phone and organisation of a new contact.
	 *
	 * Accepts both `fullName` (existing) and `displayName` (spec / dialog
	 * form field) as the human-readable label.
	 *
	 * @param array $data The caller's contact data.
	 *
	 * @return array{displayName: mixed, email: mixed, phone: mixed, org: mixed}
	 */
	private function newContactFields(array $data): array {
		return [
			'displayName' => $data['displayName'] ?? $data['fullName'] ?? 'Unknown',
			'email' => $data['email'] ?? null,
			'phone' => $data['phone'] ?? null,
			'org' => $data['org'] ?? null,
		];
	}//end newContactFields()

	/**
	 * The vCard 3.0 text of a new contact tagged with the object it is linked to.
	 *
	 * @param ContactPlacement    $placement The linked object and the contact's role.
	 * @param array<string,mixed> $contact   uid, displayName, email, phone and org.
	 *
	 * @return string
	 */
	private function newCardData(ContactPlacement $placement, array $contact): string {
		$lines = [];
		$lines[] = 'BEGIN:VCARD';
		$lines[] = 'VERSION:3.0';
		$lines[] = 'UID:' . $contact['uid'];
		$lines[] = 'FN:' . $contact['displayName'];

		if (empty($contact['email']) === false) {
			$lines[] = 'EMAIL;TYPE=INTERNET:' . $contact['email'];
		}

		if (empty($contact['phone']) === false) {
			$lines[] = 'TEL;TYPE=CELL:' . $contact['phone'];
		}

		if (empty($contact['org']) === false) {
			$lines[] = 'ORG:' . $contact['org'];
		}

		$lines[] = 'X-OPENREGISTER-OBJECT:' . $placement->objectUuid;
		if ($placement->role !== null) {
			$lines[] = 'X-OPENREGISTER-ROLE:' . $placement->role;
		}

		$lines[] = 'END:VCARD';

		return implode("\r\n", $lines) . "\r\n";
	}//end newCardData()

	/**
	 * Update the role on a contact-object link.
	 *
	 * @param int         $linkId     The link ID.
	 * @param string      $role       The new role.
	 * @param string|null $objectUuid When given, the object the link must belong to.
	 *
	 * @return ContactLink The updated link.
	 *
	 * @throws Exception If the link isn't found, or belongs to another object (404).
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function updateRole(int $linkId, string $role, ?string $objectUuid = null): ContactLink {
		return $this->assignRole(link: $this->findLinkOf(linkId: $linkId, objectUuid: $objectUuid), role: $role);
	}//end updateRole()

	/**
	 * Give a contact link a new role, on the vCard (best effort) and on the link row.
	 *
	 * @param ContactLink $link The link, already resolved within the caller's object.
	 * @param string      $role The new role.
	 *
	 * @return ContactLink The updated link.
	 *
	 * @spec openspec/specs/contactpersonen-handling/spec.md
	 */
	public function assignRole(ContactLink $link, string $role): ContactLink {
		$this->retagRole(link: $link, role: $role);
		$link->setRole($role);

		return $this->contactLinkMapper->update($link);
	}//end assignRole()

	/**
	 * Replace the role on the vCard of a link, best-effort; a user link has no vCard.
	 *
	 * @param ContactLink $link The link row.
	 * @param string      $role The new role.
	 *
	 * @return void
	 */
	private function retagRole(ContactLink $link, string $role): void {
		try {
			$card = false;
			if ($link->isUserLink() === false) {
				$card = $this->cardDavBackend->getCard((int)$link->getAddressbookId(), (string)$link->getContactUri());
			}

			if ($card !== false) {
				$vcard = Reader::read($card['carddata']);
				// Remove old role properties.
				unset($vcard->{'X-OPENREGISTER-ROLE'});
				$vcard->add('X-OPENREGISTER-ROLE', $role);
				$this->cardDavBackend->updateCard($link->getAddressbookId(), $link->getContactUri(), $vcard->serialize());
			}
		} catch (Exception $e) {
			$this->runtime->logger->warning('Failed to update vCard role: ' . $e->getMessage());
		}
	}//end retagRole()

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
	 * @param int         $linkId     The link ID.
	 * @param string|null $objectUuid When given, the object the link must belong to.
	 *
	 * @return void
	 *
	 * @throws Exception If the link row isn't found, or belongs to another object (404).
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function unlinkContact(int $linkId, ?string $objectUuid = null): void {
		$link = $this->findLinkOf(linkId: $linkId, objectUuid: $objectUuid);

		// Best-effort: remove X-OPENREGISTER-* from the vCard. If the
		// vCard is gone or unreadable, skip the cleanup and proceed with
		// the link-row delete — orphan link rows would otherwise only
		// be cleanable via direct DB DELETE.
		try {
			$card = false;
			if ($link->isUserLink() === false) {
				$card = $this->cardDavBackend->getCard((int)$link->getAddressbookId(), (string)$link->getContactUri());
			}

			if ($card !== false) {
				$vcard = Reader::read($card['carddata']);
				unset($vcard->{'X-OPENREGISTER-OBJECT'});
				unset($vcard->{'X-OPENREGISTER-ROLE'});
				$this->cardDavBackend->updateCard($link->getAddressbookId(), $link->getContactUri(), $vcard->serialize());
			}
		} catch (\Throwable $e) {
			$this->runtime->logger->warning(
				'Failed to clean vCard properties (link row will still be removed): ' . $e->getMessage(),
				[
					'linkId' => $linkId,
					'addressbookId' => $link->getAddressbookId(),
					'contactUri' => $link->getContactUri(),
				]
			);
		}

		$this->contactLinkMapper->delete($link);
	}//end unlinkContact()

	/**
	 * A link by id, optionally only when it belongs to the given object.
	 *
	 * @param int         $linkId     The link ID.
	 * @param string|null $objectUuid When given, the object the link must belong to.
	 *
	 * @return ContactLink The link.
	 *
	 * @throws Exception If the link isn't found, or belongs to another object (404).
	 */
	private function findLinkOf(int $linkId, ?string $objectUuid): ContactLink {
		try {
			$link = $this->contactLinkMapper->find($linkId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new Exception('Contact link not found', 404);
		}

		if ($objectUuid !== null && $link->getObjectUuid() !== $objectUuid) {
			throw new Exception('Contact link not found', 404);
		}

		return $link;
	}//end findLinkOf()

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
	public function unlinkContactByUid(string $objectUuid, string $contactUid): void {
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
	public function getObjectsForContact(string $contactUid): array {
		// IDOR guard: only surface object links for contacts that live in the
		// caller's own addressbooks. Without this any authenticated user could
		// pass an arbitrary (enumerable CardDAV) contact UID and learn which
		// OpenRegister objects reference contacts belonging to other users.
		$allowedAddressbookIds = $this->currentUserAddressbookIds();
		if ($this->runtime->userSession->getUser() === null) {
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
						if ($link->isUserLink() === true) {
							return true;
						}

						return in_array((int)$link->getAddressbookId(), $allowedAddressbookIds, true);
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
	private function currentUserAddressbookIds(): array {
		$user = $this->runtime->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$principal = 'principals/users/' . $user->getUID();
		$addressbooks = $this->cardDavBackend->getAddressBooksForUser($principal);

		return array_map(
			static function (array $addressbook): int {
				return (int)$addressbook['id'];
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
	public function deleteLinksForObject(string $objectUuid): void {
		$links = $this->contactLinkMapper->findByObjectUuid($objectUuid);

		foreach ($links as $link) {
			try {
				if ($link->isUserLink() === true) {
					$this->runtime->logger->debug('User link, no vCard to clean: ' . $link->getContactUid());
					continue;
				}

				$card = $this->cardDavBackend->getCard((int)$link->getAddressbookId(), (string)$link->getContactUri());
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
				$this->runtime->logger->warning(
					'Failed to clean vCard for contact ' . $link->getContactUid() . ': ' . $e->getMessage()
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
	private function findUserAddressbook(): ?array {
		$user = $this->runtime->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$principal = 'principals/users/' . $user->getUID();
		$addressbooks = $this->cardDavBackend->getAddressBooksForUser($principal);

		if (empty($addressbooks) === true) {
			return null;
		}

		// Return first addressbook.
		return $addressbooks[0];
	}//end findUserAddressbook()
}//end class

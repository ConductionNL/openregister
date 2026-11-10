<?php

/**
 * Mints, resolves, revokes and records access links.
 *
 * An access link is a grant whose principal is the link itself. It is minted
 * by somebody who may already read the subject, it declares exactly what its
 * holder may do, it carries an end date, and it may carry a password. It never
 * resolves to a user account, so a forwarded link never borrows anybody's
 * rights and the audit trail can say that the act was the link's.
 *
 * Minting and revoking are deliberately NOT audit actions. They are recorded on
 * the link row itself, in `created_by`, `created` and `revoked_at`, which is
 * where an owner reviewing what they published looks. The audit trail carries
 * the USES, which is what the spec asks for and what a wrong publication is
 * reconstructed from.
 *
 * Four refusals live here, and they are the whole point of the class:
 *
 *   - a mint with no expiry is refused, naming the requirement;
 *   - a capability the link did not declare is refused;
 *   - an unknown, revoked, switched-off or expired anchor resolves to nothing,
 *     so the caller answers one uniform 404;
 *   - a password that does not verify serves nothing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapperInterface;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * The lifecycle of an access link.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Each dependency is load-bearing:
 * the mapper is the store, the hasher and the RNG are the two secrets a link
 * carries, the URL generator builds the link a holder is given, and the audit
 * mapper is where every use is written. Splitting them would move the refusals
 * away from the row they refuse.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
class AccessLinkService implements AccessLinkServiceInterface {

	/**
	 * The length of the random anchor, in characters.
	 *
	 * Forty-three alphanumeric characters is the same entropy a calendar feed
	 * token carries, and it is far past anything a case number could suggest.
	 *
	 * @var int
	 */
	private const ANCHOR_LENGTH = 43;

	/**
	 * Constructor.
	 *
	 * @param AccessLinkMapperInterface $mapper The link store.
	 * @param AccessLinkUseLog $useLog The audit trail every use is written to, and the log.
	 * @param AccessLinkSecrets $secrets The RNG for the anchor and the password hasher.
	 * @param IURLGenerator $urlGenerator The URL generator, for the link a holder is given.
	 */
	public function __construct(
		private readonly AccessLinkMapperInterface $mapper,
		private readonly AccessLinkUseLog $useLog,
		private readonly AccessLinkSecrets $secrets,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * Mint a link over one subject.
	 *
	 * The minting principal travels in the request rather than being something
	 * this service reaches for, so the identity behind a published link is
	 * decided at the HTTP boundary and is visible there.
	 *
	 * @param LinkMintRequest $request The minting principal, the subject (`object`, `view` or `file`, and its id)
	 *                                 and the terms: capabilities, the expiry (required), the password and the label.
	 *
	 * @return array<string, mixed> The link row plus the URL a holder opens.
	 *
	 * @throws InvalidArgumentException When the subject, the capabilities or the expiry are unusable.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function mint(LinkMintRequest $request): array {
		// Every refusal is answered before anything is drawn, hashed or written.
		$terms = $request->terms;
		$minter = $this->requireMinter(userId: $request->minter);
		$kind = $this->requireSubjectType(subjectType: $request->subjectType);
		$subject = $this->requireSubjectId(subjectId: $request->subjectId);
		$declared = $this->normaliseCapabilities(capabilities: $terms->capabilities);
		$expiry = $this->requireExpiry(expiresAt: $terms->expiresAt);

		$link = $this->newLink(subjectType: $kind, subjectId: $subject);
		$link->setCapabilities(implode(',', $declared));
		$link->setLabel($this->trimmedOrNull(value: $terms->label));
		$link->setPasswordHash($this->hashedPassword(password: $terms->password));
		$link->setCreatedBy($minter);
		$link->setCreated(new DateTime());
		$link->setExpiresAt($expiry);

		$saved = $this->mapper->insert($link);

		return $this->describeForOwner(link: $saved);
	}//end mint()

	/**
	 * Resolve an anchor to a link that may still answer, or null.
	 *
	 * Unknown, revoked, switched off and expired all return null, so the
	 * caller's single 404 cannot be used to tell them apart. A holder who knows
	 * the anchor learns nothing from the answer that they did not already hold.
	 *
	 * @param string $anchor The anchor from the URL.
	 *
	 * @return AccessLink|null The live link, or null.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function resolve(string $anchor): ?AccessLink {
		$anchor = trim($anchor);
		if ($anchor === '') {
			return null;
		}

		$link = $this->mapper->findByAnchor(anchor: $anchor);
		if ($link === null || $link->isLive() === false) {
			return null;
		}

		return $link;
	}//end resolve()

	/**
	 * Whether the password presented opens this link.
	 *
	 * A link with no password opens without one. A link with a password never
	 * opens without it, and never on a wrong one.
	 *
	 * @param AccessLink $link The link.
	 * @param string|null $password The password presented, when one was.
	 *
	 * @return bool True when the holder may proceed.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function passwordAccepted(AccessLink $link, ?string $password): bool {
		if ($link->hasPassword() === false) {
			return true;
		}

		if ($password === null || $password === '') {
			return false;
		}

		return $this->secrets->hasher->verify($password, (string)$link->getPasswordHash());
	}//end passwordAccepted()

	/**
	 * Revoke a link the calling principal minted.
	 *
	 * Revocation takes effect on the next use, because every use reads the row.
	 * A link somebody else minted is, to this caller, a link that does not
	 * exist.
	 *
	 * @param int $id The link row id.
	 * @param string $userId The principal asking.
	 *
	 * @return bool True when a link was revoked, false when there was none to revoke.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function revoke(int $id, string $userId): bool {
		$link = $this->ownedLink(id: $id, userId: $userId);
		if ($link === null) {
			return false;
		}

		if ($link->getRevokedAt() !== null) {
			return true;
		}

		$link->setRevokedAt(new DateTime());
		$this->mapper->update($link);

		return true;
	}//end revoke()

	/**
	 * Switch a link off without revoking it.
	 *
	 * Switching off is the reversible half of revocation, and it is what the
	 * spec means by a link that grants nothing while it is off. A switched-off
	 * link answers the same 404 as a revoked one. A link somebody else minted
	 * is, to this caller, a link that does not exist.
	 *
	 * @param int    $id     The link row id.
	 * @param string $userId The principal asking.
	 *
	 * @return array<string, mixed>|null The updated link, or null when there is none.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function disable(int $id, string $userId): ?array {
		$link = $this->ownedLink(id: $id, userId: $userId);
		$link?->setDisabled(true);

		return $this->storedOrNull(link: $link);
	}//end disable()

	/**
	 * Switch a link the caller minted back on.
	 *
	 * @param int    $id     The link row id.
	 * @param string $userId The principal asking.
	 *
	 * @return array<string, mixed>|null The updated link, or null when there is none.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function enable(int $id, string $userId): ?array {
		$link = $this->ownedLink(id: $id, userId: $userId);
		$link?->setDisabled(false);

		return $this->storedOrNull(link: $link);
	}//end enable()

	/**
	 * Switch a link off, or back on, without revoking it.
	 *
	 * Kept for callers in other apps that look this method up by name.
	 *
	 * @param int    $id       The link row id.
	 * @param string $userId   The principal asking.
	 * @param bool   $disabled True to switch the link off.
	 *
	 * @return array<string, mixed>|null The updated link, or null when there is none.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function setDisabled(int $id, string $userId, bool $disabled): ?array {
		if ($disabled === true) {
			return $this->disable(id: $id, userId: $userId);
		}

		return $this->enable(id: $id, userId: $userId);
	}//end setDisabled()

	/**
	 * Store a switched link and describe it to its owner, or null when there was none.
	 *
	 * @param AccessLink|null $link The caller's own link, already switched.
	 *
	 * @return array<string, mixed>|null The updated link, or null.
	 */
	private function storedOrNull(?AccessLink $link): ?array {
		if ($link === null) {
			return null;
		}

		return $this->describeForOwner(link: $this->mapper->update($link));
	}//end storedOrNull()

	/**
	 * Every link one principal minted, with the URL each one opens.
	 *
	 * @param string $userId The minting principal.
	 *
	 * @return array<int, array<string, mixed>> The serialised links.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function listForUser(string $userId): array {
		$userId = trim($userId);
		if ($userId === '') {
			return [];
		}

		$rows = [];
		foreach ($this->mapper->findByCreator(userId: $userId) as $link) {
			$rows[] = $this->describeForOwner(link: $link);
		}

		return $rows;
	}//end listForUser()

	/**
	 * Record one use of a link on the audit trail, and note it on the row.
	 *
	 * The actor is the link, never a user, so four uses of one link are four
	 * entries naming that link. The calling address rides along because a
	 * publication that turns out to have been wrong is reconstructed from
	 * where it was opened as much as from when.
	 *
	 * The row's own counters are a convenience and are updated after the audit
	 * entry: a failure to bump a counter must never lose the entry.
	 *
	 * @param AccessLink $link The link that acted.
	 * @param AccessLinkUse $use What it did, to which object, from which address, with what context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function recordUse(AccessLink $link, AccessLinkUse $use): void {
		if ($use->object !== null) {
			try {
				$this->useLog->auditTrail->createAuditTrailEntry(
					object: $use->object,
					action: $use->act,
					context: array_merge(
						$use->context,
						[
							'accessLink' => $link->getUuid(),
							'capabilities' => $link->declaredCapabilities(),
							'subjectType' => $link->getSubjectType(),
							'subjectId' => $link->getSubjectId(),
							'ipAddress' => $use->ipAddress,
						]
					),
					actorId: $link->principalId(),
					actorName: $link->principalName(),
					ipAddress: $use->ipAddress
				);
			} catch (Throwable $failure) {
				// An audit write that fails must be visible, and it must not
				// silently turn a recorded act into an unrecorded one.
				$this->useLog->logger->error(
					'[AccessLinkService] Could not record a link use: ' . $failure->getMessage(),
					['accessLink' => $link->getUuid(), 'act' => $use->act]
				);
			}
		}

		$this->noteUse(link: $link);
	}//end recordUse()

	/**
	 * The absolute URL a holder opens.
	 *
	 * @param string $anchor The link anchor.
	 *
	 * @return string The absolute link URL.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function linkUrl(string $anchor): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute(
				'openregister.accessLink.open',
				['anchor' => $anchor]
			)
		);
	}//end linkUrl()

	/**
	 * The page a holder opens for an anchor, rendered for a person, not JSON.
	 *
	 * @param string $anchor The anchor.
	 *
	 * @return string The absolute page URL.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function pageUrl(string $anchor): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute(
				'openregister.accessLinkPage.show',
				['anchor' => $anchor]
			)
		);
	}//end pageUrl()

	/**
	 * A link row plus the URL it opens, for its owner.
	 *
	 * @param AccessLink $link The link.
	 *
	 * @return array<string, mixed> The serialised link.
	 */
	private function describeForOwner(AccessLink $link): array {
		$data = $link->jsonSerialize();
		// `url` stays the JSON API link: dossiq and other API callers read it.
		// `pageUrl` is the page a person without an account can open.
		$data['url'] = $this->linkUrl(anchor: (string)$link->getAnchor());
		$data['pageUrl'] = $this->pageUrl(anchor: (string)$link->getAnchor());

		return $data;
	}//end describeForOwner()

	/**
	 * A link this principal minted, or null.
	 *
	 * @param int $id The link row id.
	 * @param string $userId The principal asking.
	 *
	 * @return AccessLink|null The link, or null when it is unknown or somebody else's.
	 */
	private function ownedLink(int $id, string $userId): ?AccessLink {
		$userId = trim($userId);
		if ($userId === '') {
			return null;
		}

		$link = $this->mapper->findById(id: $id);
		if ($link === null || $link->getCreatedBy() !== $userId) {
			return null;
		}

		return $link;
	}//end ownedLink()

	/**
	 * The minting principal, trimmed.
	 *
	 * @param string $userId The principal minting the link.
	 *
	 * @return string The trimmed principal.
	 *
	 * @throws InvalidArgumentException When nobody is minting.
	 */
	private function requireMinter(string $userId): string {
		$userId = trim($userId);
		if ($userId === '') {
			throw new InvalidArgumentException('A logged-in user is required to mint an access link.');
		}

		return $userId;
	}//end requireMinter()

	/**
	 * The subject kind, normalised.
	 *
	 * @param string $subjectType The requested kind.
	 *
	 * @return string `object`, `view` or `file`.
	 *
	 * @throws InvalidArgumentException When the kind is not one a link can open.
	 */
	private function requireSubjectType(string $subjectType): string {
		$subjectType = strtolower(trim($subjectType));
		if (in_array($subjectType, AccessLink::SUBJECT_TYPES, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					"'%s' is not a link subject: use %s.",
					$subjectType,
					implode(', ', AccessLink::SUBJECT_TYPES)
				)
			);
		}

		return $subjectType;
	}//end requireSubjectType()

	/**
	 * The subject identifier, trimmed.
	 *
	 * @param string $subjectId The requested subject.
	 *
	 * @return string The trimmed subject.
	 *
	 * @throws InvalidArgumentException When no subject is named.
	 */
	private function requireSubjectId(string $subjectId): string {
		$subjectId = trim($subjectId);
		if ($subjectId === '') {
			throw new InvalidArgumentException('An access link must name the subject it opens.');
		}

		return $subjectId;
	}//end requireSubjectId()

	/**
	 * A fresh, unsaved, unused link over one subject, with its uuid and random anchor.
	 *
	 * @param string $subjectType The validated subject kind.
	 * @param string $subjectId The validated subject.
	 *
	 * @return AccessLink The new row.
	 */
	private function newLink(string $subjectType, string $subjectId): AccessLink {
		$link = new AccessLink();
		$link->setUuid(Uuid::v4()->toRfc4122());
		$link->setAnchor($this->secrets->secureRandom->generate(self::ANCHOR_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
		$link->setSubjectType($subjectType);
		$link->setSubjectId($subjectId);
		$link->setRevokedAt(null);
		$link->setDisabled(false);
		$link->setLastUsedAt(null);
		$link->setUseCount(0);

		return $link;
	}//end newLink()

	/**
	 * Bump the row's use counters, never failing the act.
	 *
	 * @param AccessLink $link The link that acted.
	 *
	 * @return void
	 */
	private function noteUse(AccessLink $link): void {
		try {
			$link->setLastUsedAt(new DateTime());
			$link->setUseCount(($link->getUseCount() + 1));
			$this->mapper->update($link);
		} catch (Throwable $failure) {
			unset($failure);
		}
	}//end noteUse()

	/**
	 * The capability set a link is minted with.
	 *
	 * Empty means read. Read is always present, because comment and upload are
	 * things done to something the holder can see, and a comment-only link that
	 * serves nothing is a link with no use. A capability this app does not know
	 * is refused rather than dropped: silently dropping one would mint a link
	 * narrower than the caller asked for and tell nobody.
	 *
	 * @param array<int, string> $capabilities The requested capabilities.
	 *
	 * @return array<int, string> The declared set, read first.
	 *
	 * @throws InvalidArgumentException When a capability is not one this app knows.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	private function normaliseCapabilities(array $capabilities): array {
		$declared = [AccessLink::CAP_READ];

		foreach ($capabilities as $candidate) {
			$name = $this->capabilityName(candidate: $candidate);
			if ($name !== '' && in_array($name, $declared, true) === false) {
				$declared[] = $name;
			}
		}

		return $declared;
	}//end normaliseCapabilities()

	/**
	 * One requested capability, lower-cased and trimmed; '' for a blank one.
	 *
	 * @param mixed $candidate The requested capability.
	 *
	 * @return string The capability name, or ''.
	 *
	 * @throws InvalidArgumentException When it is not a string, or not a capability this app knows.
	 */
	private function capabilityName(mixed $candidate): string {
		if (is_string($candidate) === false) {
			throw new InvalidArgumentException('A capability must be named as a string.');
		}

		$name = strtolower(trim($candidate));
		if ($name === '') {
			return '';
		}

		$this->assertKnownCapability(name: $name);

		return $name;
	}//end capabilityName()

	/**
	 * Refuse a capability this app does not know.
	 *
	 * @param string $name The lower-cased capability name.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not one of AccessLink::CAPABILITIES.
	 */
	private function assertKnownCapability(string $name): void {
		if (in_array($name, AccessLink::CAPABILITIES, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					"'%s' is not a link capability: use %s.",
					$name,
					implode(', ', AccessLink::CAPABILITIES)
				)
			);
		}
	}//end assertKnownCapability()

	/**
	 * The end date a link must carry.
	 *
	 * @param string|null $expiresAt The requested end date.
	 *
	 * @return DateTime The parsed end date.
	 *
	 * @throws InvalidArgumentException When it is absent, unreadable or already past.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	private function requireExpiry(?string $expiresAt): DateTime {
		$raw = trim((string)$expiresAt);
		if ($raw === '') {
			throw new InvalidArgumentException(
				'An access link must carry an expiry: pass expiresAt as a date this instance can read.'
			);
		}

		try {
			$expiry = new DateTime($raw);
		} catch (Exception $unreadable) {
			unset($unreadable);
			throw new InvalidArgumentException(
				sprintf("'%s' is not a date this instance can read: pass expiresAt as an ISO 8601 date.", $raw)
			);
		}

		if ($expiry <= new DateTime()) {
			throw new InvalidArgumentException('An access link must expire in the future.');
		}

		return $expiry;
	}//end requireExpiry()

	/**
	 * The hash of a password, or null when the link carries none.
	 *
	 * @param string|null $password The password, when one was given.
	 *
	 * @return string|null The hash, or null.
	 */
	private function hashedPassword(?string $password): ?string {
		$raw = (string)$password;
		if ($raw === '') {
			return null;
		}

		return $this->secrets->hasher->hash($raw);
	}//end hashedPassword()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param string|null $value The value.
	 *
	 * @return string|null The value, or null.
	 */
	private function trimmedOrNull(?string $value): ?string {
		$trimmed = trim((string)$value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end trimmedOrNull()
}//end class

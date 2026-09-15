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
use OCA\OpenRegister\Db\AccessLinkMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IURLGenerator;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
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
class AccessLinkService {

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
	 * The audit action written when a link serves its subject.
	 *
	 * @var string
	 */
	public const ACT_READ = 'accesslink.read';

	/**
	 * The audit action written when a link leaves a comment.
	 *
	 * @var string
	 */
	public const ACT_COMMENT = 'accesslink.comment';

	/**
	 * The audit action written when a link adds a file.
	 *
	 * @var string
	 */
	public const ACT_UPLOAD = 'accesslink.upload';

	/**
	 * The audit action written when a link is minted.
	 *
	 * @var string
	 */
	public const ACT_MINTED = 'accesslink.minted';

	/**
	 * The audit action written when a link is revoked.
	 *
	 * @var string
	 */
	public const ACT_REVOKED = 'accesslink.revoked';

	/**
	 * Constructor.
	 *
	 * @param AccessLinkMapper $mapper The link store.
	 * @param AuditTrailMapper $auditTrail The audit trail, where every use is written.
	 * @param ISecureRandom $secureRandom Nextcloud's secure RNG, for the anchor.
	 * @param IHasher $hasher Nextcloud's password hasher.
	 * @param IURLGenerator $urlGenerator The URL generator, for the link a holder is given.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly AccessLinkMapper $mapper,
		private readonly AuditTrailMapper $auditTrail,
		private readonly ISecureRandom $secureRandom,
		private readonly IHasher $hasher,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Mint a link over one subject.
	 *
	 * The minting principal is an argument rather than something this service
	 * reaches for, so the identity behind a published link is decided at the
	 * HTTP boundary and is visible there.
	 *
	 * @param string $userId The principal minting the link.
	 * @param string $subjectType `object`, `view` or `file`.
	 * @param string $subjectId The object uuid, view uuid, or file id.
	 * @param array<int, string> $capabilities The declared capabilities; empty means read only.
	 * @param string|null $expiresAt The end date, in any format DateTime parses. Required.
	 * @param string|null $password Optional password, checked at use.
	 * @param string|null $label Optional human label.
	 *
	 * @return array<string, mixed> The link row plus the URL a holder opens.
	 *
	 * @throws InvalidArgumentException When the subject, the capabilities or the expiry are unusable.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function mint(
		string $userId,
		string $subjectType,
		string $subjectId,
		array $capabilities = [],
		?string $expiresAt = null,
		?string $password = null,
		?string $label = null,
	): array {
		$userId = trim($userId);
		if ($userId === '') {
			throw new InvalidArgumentException('A logged-in user is required to mint an access link.');
		}

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

		$subjectId = trim($subjectId);
		if ($subjectId === '') {
			throw new InvalidArgumentException('An access link must name the subject it opens.');
		}

		$declared = $this->normaliseCapabilities(capabilities: $capabilities);
		$expiry = $this->requireExpiry(expiresAt: $expiresAt);

		$link = new AccessLink();
		$link->setUuid(Uuid::v4()->toRfc4122());
		$link->setAnchor($this->secureRandom->generate(self::ANCHOR_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
		$link->setSubjectType($subjectType);
		$link->setSubjectId($subjectId);
		$link->setCapabilities(implode(',', $declared));
		$link->setLabel($this->trimmedOrNull(value: $label));
		$link->setPasswordHash($this->hashedPassword(password: $password));
		$link->setCreatedBy($userId);
		$link->setCreated(new DateTime());
		$link->setExpiresAt($expiry);
		$link->setRevokedAt(null);
		$link->setDisabled(false);
		$link->setLastUsedAt(null);
		$link->setUseCount(0);

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

		return $this->hasher->verify($password, (string)$link->getPasswordHash());
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
	 * Switch a link off, or back on, without revoking it.
	 *
	 * Switching off is the reversible half of revocation, and it is what the
	 * spec means by a link that grants nothing while it is off. A switched-off
	 * link answers the same 404 as a revoked one.
	 *
	 * @param int $id The link row id.
	 * @param string $userId The principal asking.
	 * @param bool $disabled True to switch the link off.
	 *
	 * @return array<string, mixed>|null The updated link, or null when there is none.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function setDisabled(int $id, string $userId, bool $disabled): ?array {
		$link = $this->ownedLink(id: $id, userId: $userId);
		if ($link === null) {
			return null;
		}

		$link->setDisabled($disabled);
		$updated = $this->mapper->update($link);

		return $this->describeForOwner(link: $updated);
	}//end setDisabled()

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
	 * @param string $act The audit action, one of this class's ACT_ constants.
	 * @param ObjectEntity|null $object The object touched, when the subject resolved to one.
	 * @param string|null $ipAddress The calling address.
	 * @param array<string, mixed> $context Anything else worth recording.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function recordUse(
		AccessLink $link,
		string $act,
		?ObjectEntity $object = null,
		?string $ipAddress = null,
		array $context = [],
	): void {
		if ($object !== null) {
			try {
				$this->auditTrail->createAuditTrailEntry(
					object: $object,
					action: $act,
					context: array_merge(
						$context,
						[
							'accessLink' => $link->getUuid(),
							'capabilities' => $link->declaredCapabilities(),
							'subjectType' => $link->getSubjectType(),
							'subjectId' => $link->getSubjectId(),
							'ipAddress' => $ipAddress,
						]
					),
					actorId: $link->principalId(),
					actorName: $link->principalName(),
					ipAddress: $ipAddress
				);
			} catch (Throwable $failure) {
				// An audit write that fails must be visible, and it must not
				// silently turn a recorded act into an unrecorded one.
				$this->logger->error(
					'[AccessLinkService] Could not record a link use: ' . $failure->getMessage(),
					['accessLink' => $link->getUuid(), 'act' => $act]
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
	 * A link row plus the URL it opens, for its owner.
	 *
	 * @param AccessLink $link The link.
	 *
	 * @return array<string, mixed> The serialised link.
	 */
	private function describeForOwner(AccessLink $link): array {
		$data = $link->jsonSerialize();
		$data['url'] = $this->linkUrl(anchor: (string)$link->getAnchor());

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
			if (is_string($candidate) === false) {
				throw new InvalidArgumentException('A capability must be named as a string.');
			}

			$name = strtolower(trim($candidate));
			if ($name === '') {
				continue;
			}

			if (in_array($name, AccessLink::CAPABILITIES, true) === false) {
				throw new InvalidArgumentException(
					sprintf(
						"'%s' is not a link capability: use %s.",
						$name,
						implode(', ', AccessLink::CAPABILITIES)
					)
				);
			}

			if (in_array($name, $declared, true) === false) {
				$declared[] = $name;
			}
		}

		return $declared;
	}//end normaliseCapabilities()

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

		return $this->hasher->hash($raw);
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

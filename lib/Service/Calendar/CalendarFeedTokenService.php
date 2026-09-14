<?php

/**
 * Mints, lists and revokes calendar feed tokens.
 *
 * A calendar client sends no session, so the feed is addressed by a token
 * instead. The token names the principal and nothing more: it grants no read
 * of its own, and the feed it addresses is generated as that principal under
 * the ordinary object rules. A feed URL that leaks is therefore a read of
 * nothing its holder could not already read, and it can be revoked without
 * touching the objects.
 *
 * A revoked or expired token answers nothing at all. Answering a smaller
 * calendar would be worse than answering none, because an empty day is
 * believed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
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
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\CalendarFeedTokenMapper;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * The lifecycle of a calendar feed token.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
class CalendarFeedTokenService {

	/**
	 * The length of the opaque token, in characters.
	 *
	 * @var int
	 */
	private const TOKEN_LENGTH = 43;

	/**
	 * Constructor.
	 *
	 * @param CalendarFeedTokenMapper $mapper The token store.
	 * @param ISecureRandom $secureRandom Nextcloud's secure RNG.
	 * @param IUserSession $userSession The current session.
	 * @param IURLGenerator $urlGenerator The URL generator, for the subscribable link.
	 */
	public function __construct(
		private readonly CalendarFeedTokenMapper $mapper,
		private readonly ISecureRandom $secureRandom,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * Mint a feed token for the calling principal.
	 *
	 * Minting is a write that establishes a durable read surface, so it is
	 * never anonymous, and a caller may only mint a feed for themselves.
	 *
	 * @param string $scopeType `schema` or `view`.
	 * @param string $scopeId The schema id, or the view uuid.
	 * @param string|null $label Optional human label.
	 * @param int|null $ttlSeconds Seconds until the token expires; null never expires.
	 *
	 * @return array<string, mixed> The token row plus its subscribable URL.
	 *
	 * @throws InvalidArgumentException When the scope is unusable or the caller is anonymous.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function mint(
		string $scopeType,
		string $scopeId,
		?string $label = null,
		?int $ttlSeconds = null,
	): array {
		$scopeType = trim($scopeType);
		if (in_array($scopeType, CalendarFeedToken::SCOPE_TYPES, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					"'%s' is not a feed scope: use %s.",
					$scopeType,
					implode(' or ', CalendarFeedToken::SCOPE_TYPES)
				)
			);
		}

		$scopeId = trim($scopeId);
		if ($scopeId === '') {
			throw new InvalidArgumentException('A feed token must name the schema or view it covers.');
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new InvalidArgumentException('A logged-in user is required to mint a calendar feed token.');
		}

		$now = new DateTime();
		$expires = null;
		if ($ttlSeconds !== null && $ttlSeconds > 0) {
			$expires = (clone $now)->modify('+' . $ttlSeconds . ' seconds');
		}

		$entity = new CalendarFeedToken();
		$entity->setToken($this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
		$entity->setUserId($user->getUID());
		$entity->setScopeType($scopeType);
		$entity->setScopeId($scopeId);
		$entity->setLabel($label);
		$entity->setCreatedAt($now);
		$entity->setExpiresAt($expires);
		$entity->setRevokedAt(null);
		$entity->setLastReadAt(null);

		$saved = $this->mapper->insert($entity);

		$data = $saved->jsonSerialize();
		$data['url'] = $this->feedUrl(token: (string)$saved->getToken());

		return $data;
	}//end mint()

	/**
	 * Resolve a token that may still answer, or null.
	 *
	 * Returns null for unknown, revoked and expired alike, so the caller's
	 * uniform 404 is not an enumeration oracle.
	 *
	 * @param string $token The opaque token.
	 *
	 * @return CalendarFeedToken|null The live token, or null.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function resolve(string $token): ?CalendarFeedToken {
		$token = trim($token);
		if ($token === '') {
			return null;
		}

		$row = $this->mapper->findByToken(token: $token);
		if ($row === null || $row->isLive() === false) {
			return null;
		}

		return $row;
	}//end resolve()

	/**
	 * Note that a client read the feed, so a holder can see a dead subscription.
	 *
	 * A failure here never fails the read: the feed is the product, the
	 * timestamp is a convenience.
	 *
	 * @param CalendarFeedToken $token The token that was read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function noteRead(CalendarFeedToken $token): void {
		try {
			$token->setLastReadAt(new DateTime());
			$this->mapper->update($token);
		} catch (\Throwable $failure) {
			unset($failure);
		}
	}//end noteRead()

	/**
	 * Revoke a token the calling principal owns.
	 *
	 * @param int $id The token row id.
	 *
	 * @return bool True when a token was revoked, false when there was none to revoke.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function revoke(int $id): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$row = $this->mapper->findById(id: $id);
		if ($row === null || $row->getUserId() !== $user->getUID()) {
			// A token somebody else owns is, to this caller, a token that does
			// not exist.
			return false;
		}

		if ($row->getRevokedAt() !== null) {
			return true;
		}

		$row->setRevokedAt(new DateTime());
		$this->mapper->update($row);

		return true;
	}//end revoke()

	/**
	 * Every token the calling principal owns.
	 *
	 * @return array<int, array<string, mixed>> The serialised tokens, with their URLs.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function listForCurrentUser(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$rows = [];
		foreach ($this->mapper->findByUser(userId: $user->getUID()) as $row) {
			$data = $row->jsonSerialize();
			$data['url'] = $this->feedUrl(token: (string)$row->getToken());
			$rows[] = $data;
		}

		return $rows;
	}//end listForCurrentUser()

	/**
	 * The absolute URL a calendar client subscribes to.
	 *
	 * @param string $token The opaque token.
	 *
	 * @return string The absolute feed URL.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function feedUrl(string $token): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute(
				'openregister.calendarFeed.feed',
				['token' => $token]
			)
		);
	}//end feedUrl()
}//end class

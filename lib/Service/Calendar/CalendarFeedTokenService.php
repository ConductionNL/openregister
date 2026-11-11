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
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\CalendarFeedTokenMapperInterface;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * The lifecycle of a calendar feed token.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
class CalendarFeedTokenService implements CalendarFeedTokenServiceInterface {

	/**
	 * The length of the opaque token, in characters.
	 *
	 * @var int
	 */
	private const TOKEN_LENGTH = 43;

	/**
	 * Constructor.
	 *
	 * @param CalendarFeedTokenMapperInterface $mapper The token store.
	 * @param ISecureRandom $secureRandom Nextcloud's secure RNG.
	 * @param IURLGenerator $urlGenerator The URL generator, for the subscribable link.
	 */
	public function __construct(
		private readonly CalendarFeedTokenMapperInterface $mapper,
		private readonly ISecureRandom $secureRandom,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * Mint a feed token for the calling principal.
	 *
	 * Minting is a write that establishes a durable read surface, so it is
	 * never anonymous, and a caller may only mint a feed for themselves. The
	 * principal is an argument rather than something this service reaches for,
	 * so the identity the token will carry is decided at the HTTP boundary and
	 * is visible there.
	 *
	 * The caller names what the token covers on an unsaved token: its scope
	 * type and scope id, and optionally a label. Everything else (the secret,
	 * the principal, the moments) is set here.
	 *
	 * @param string            $userId     The principal the token will name.
	 * @param CalendarFeedToken $draft      The unsaved token: scopeType (`schema` or `view`), scopeId and label.
	 * @param int|null          $ttlSeconds Seconds until the token expires; null never expires.
	 *
	 * @return array<string, mixed> The token row plus its subscribable URL.
	 *
	 * @throws InvalidArgumentException When the scope is unusable or the caller is anonymous.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function mint(string $userId, CalendarFeedToken $draft, ?int $ttlSeconds=null): array {
		return $this->mintToken(mint: new FeedTokenMint(userId: $userId, draft: $draft, ttlSeconds: $ttlSeconds));
	}//end mint()

	/**
	 * Mint a feed token for the principal the request names, as mint() does.
	 *
	 * @param FeedTokenMint $mint The principal, the unsaved token and its lifetime.
	 *
	 * @return array<string, mixed> The token row plus its subscribable URL.
	 *
	 * @throws InvalidArgumentException When the scope is unusable or the caller is anonymous.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function mintToken(FeedTokenMint $mint): array {
		$draft = $this->stampedDraft(mint: $mint);

		$saved = $this->mapper->insert($draft);
		$data  = $saved->jsonSerialize();
		$data['url'] = $this->feedUrl(token: (string)$saved->getToken());

		return $data;
	}//end mintToken()

	/**
	 * The mint's draft token, stamped with a fresh secret, its principal and scope, and its lifetime from now.
	 *
	 * @param FeedTokenMint $mint The draft, the principal and the lifetime.
	 *
	 * @return CalendarFeedToken The stamped draft, not yet saved.
	 */
	private function stampedDraft(FeedTokenMint $mint): CalendarFeedToken {
		$draft     = $mint->draft;
		$scopeType = self::scopeTypeOf(draft: $draft);
		$scopeId   = self::scopeIdOf(draft: $draft);
		$userId    = self::principalOf(userId: $mint->userId);
		$now       = new DateTime();
		$draft->setToken($this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
		$draft->setUserId($userId);
		$draft->setScopeType($scopeType);
		$draft->setScopeId($scopeId);
		$draft->setCreatedAt($now);
		$draft->setExpiresAt(self::expiryOf(now: $now, ttlSeconds: $mint->ttlSeconds));
		$draft->setRevokedAt(null);
		$draft->setLastReadAt(null);

		return $draft;
	}//end stampedDraft()

	/**
	 * The draft's scope type, refusing anything but a schema or a view.
	 *
	 * @param CalendarFeedToken $draft The unsaved token.
	 *
	 * @return string The trimmed scope type.
	 *
	 * @throws InvalidArgumentException When the scope type is not a feed scope.
	 */
	private static function scopeTypeOf(CalendarFeedToken $draft): string {
		$scopeType = trim((string)$draft->getScopeType());
		if (in_array($scopeType, CalendarFeedToken::SCOPE_TYPES, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					"'%s' is not a feed scope: use %s.",
					$scopeType,
					implode(' or ', CalendarFeedToken::SCOPE_TYPES)
				)
			);
		}

		return $scopeType;
	}//end scopeTypeOf()

	/**
	 * The draft's scope id, refusing an empty one.
	 *
	 * @param CalendarFeedToken $draft The unsaved token.
	 *
	 * @return string The trimmed scope id.
	 *
	 * @throws InvalidArgumentException When the token names no schema or view.
	 */
	private static function scopeIdOf(CalendarFeedToken $draft): string {
		$scopeId = trim((string)$draft->getScopeId());
		if ($scopeId === '') {
			throw new InvalidArgumentException('A feed token must name the schema or view it covers.');
		}

		return $scopeId;
	}//end scopeIdOf()

	/**
	 * The principal the token will name, refusing an anonymous caller.
	 *
	 * @param string $userId The principal as the caller passed it.
	 *
	 * @return string The trimmed principal.
	 *
	 * @throws InvalidArgumentException When no user is logged in.
	 */
	private static function principalOf(string $userId): string {
		$userId = trim($userId);
		if ($userId === '') {
			throw new InvalidArgumentException('A logged-in user is required to mint a calendar feed token.');
		}

		return $userId;
	}//end principalOf()

	/**
	 * When a token minted now expires.
	 *
	 * @param DateTime $now        The minting moment.
	 * @param int|null $ttlSeconds Seconds until expiry; null or less than one never expires.
	 *
	 * @return DateTime|null The expiry, or null for a token that never expires.
	 */
	private static function expiryOf(DateTime $now, ?int $ttlSeconds): ?DateTime {
		if ($ttlSeconds === null || $ttlSeconds <= 0) {
			return null;
		}

		return (clone $now)->modify('+' . $ttlSeconds . ' seconds');
	}//end expiryOf()

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
	 * @spec openspec/specs/calendar-provider/spec.md
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
	 * @spec openspec/specs/calendar-provider/spec.md
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
	 * @param string $userId The principal asking, compared against the row's owner.
	 *
	 * @return bool True when a token was revoked, false when there was none to revoke.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function revoke(int $id, string $userId): bool {
		if (trim($userId) === '') {
			return false;
		}

		$row = $this->mapper->findById(id: $id);
		if ($row === null || $row->getUserId() !== $userId) {
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
	 * Every token one principal owns.
	 *
	 * @param string $userId The principal whose tokens to list.
	 *
	 * @return array<int, array<string, mixed>> The serialised tokens, with their URLs.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function listForUser(string $userId): array {
		$userId = trim($userId);
		if ($userId === '') {
			return [];
		}

		$rows = [];
		foreach ($this->mapper->findByUser(userId: $userId) as $row) {
			$data = $row->jsonSerialize();
			$data['url'] = $this->feedUrl(token: (string)$row->getToken());
			$rows[] = $data;
		}

		return $rows;
	}//end listForUser()

	/**
	 * The absolute URL a calendar client subscribes to.
	 *
	 * @param string $token The opaque token.
	 *
	 * @return string The absolute feed URL.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
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

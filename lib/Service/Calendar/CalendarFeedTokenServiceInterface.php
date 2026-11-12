<?php

/**
 * CalendarFeedTokenServiceInterface: what callers outside the component use of CalendarFeedTokenService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use InvalidArgumentException;
use OCA\OpenRegister\Db\CalendarFeedToken;

/**
 * The calendar feed tokens the calendar feed endpoints mint, resolve and revoke.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface CalendarFeedTokenServiceInterface {

	/**
	 * Every token one principal owns.
	 *
	 * @param string $userId The principal whose tokens to list.
	 *
	 * @return array<int, array<string, mixed>> The serialised tokens, with their URLs.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function listForUser(string $userId): array;

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
	 * type and scope id, and optionally a label.
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
	public function mint(string $userId, CalendarFeedToken $draft, ?int $ttlSeconds=null): array;

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
	public function mintToken(FeedTokenMint $mint): array;

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
	public function noteRead(CalendarFeedToken $token): void;

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
	public function resolve(string $token): ?CalendarFeedToken;

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
	public function revoke(int $id, string $userId): bool;
}//end interface

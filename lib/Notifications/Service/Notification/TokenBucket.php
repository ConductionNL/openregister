<?php

/**
 * One rate-limit token bucket as read at one moment.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The cache key, capacity and refill rate of one bucket, and the moment it is read at.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class TokenBucket {

	/**
	 * Constructor.
	 *
	 * @param string $key           Cache key.
	 * @param int    $size          Bucket capacity.
	 * @param int    $refillSeconds Seconds per earned token.
	 * @param int    $now           Current unix timestamp.
	 */
	public function __construct(
		public readonly string $key,
		public readonly int $size,
		public readonly int $refillSeconds,
		public readonly int $now,
	) {
	}//end __construct()

	/**
	 * The tokens held after refilling from a stored state.
	 *
	 * One token is earned every `refillSeconds`, capped at the bucket size.
	 * A missing or unreadable state is a full bucket refilled now.
	 *
	 * @param mixed $state The cached JSON state, or null.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function tokensFrom(mixed $state): float {
		$stored = $this->storedState(state: $state);
		return $this->refilled(tokens: $stored[0], lastRefill: $stored[1]);
	}//end tokensFrom()

	/**
	 * The stored (tokens, lastRefill), or a full bucket refilled now when the
	 * state is missing or unreadable.
	 *
	 * @param mixed $state The cached JSON state, or null.
	 *
	 * @return array{0: float, 1: int}
	 */
	private function storedState(mixed $state): array {
		$decoded = null;
		if (is_string($state) === true) {
			$decoded = json_decode($state, true);
		}

		if (is_array($decoded) === true && isset($decoded['tokens'], $decoded['lastRefill']) === true) {
			return [(float)$decoded['tokens'], (int)$decoded['lastRefill']];
		}

		return [(float)$this->size, $this->now];
	}//end storedState()

	/**
	 * The tokens after earning one every `refillSeconds` since the last refill,
	 * capped at the bucket size.
	 *
	 * @param float $tokens     Tokens at the last refill.
	 * @param int   $lastRefill Unix timestamp of the last refill.
	 *
	 * @return float
	 */
	private function refilled(float $tokens, int $lastRefill): float {
		if ($this->refillSeconds > 0 && $this->now > $lastRefill) {
			$earned = (($this->now - $lastRefill) / $this->refillSeconds);
			return min((float)$this->size, ($tokens + $earned));
		}

		return $tokens;
	}//end refilled()
}//end class

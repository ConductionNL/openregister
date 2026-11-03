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

namespace OCA\OpenRegister\Service\Notification;

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
		$tokens = (float)$this->size;
		$lastRefill = $this->now;
		if (is_string($state) === true) {
			$decoded = json_decode($state, true);
			if (is_array($decoded) === true && isset($decoded['tokens'], $decoded['lastRefill']) === true) {
				$tokens = (float)$decoded['tokens'];
				$lastRefill = (int)$decoded['lastRefill'];
			}
		}

		if ($this->refillSeconds > 0 && $this->now > $lastRefill) {
			$earned = (($this->now - $lastRefill) / $this->refillSeconds);
			$tokens = min((float)$this->size, ($tokens + $earned));
		}

		return $tokens;
	}//end tokensFrom()
}//end class

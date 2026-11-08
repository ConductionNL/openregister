<?php

/**
 * OpenRegister ObjectShareLinkThrottle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * Counts a rejected bearer token against the caller's address.
 *
 * A uniform refusal hides WHICH failure happened; it does nothing about how
 * fast a caller may keep trying. This is the other half. A throttler that
 * fails is logged and the refusal it was counting still stands.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/share-links-and-email-invites/spec.md
 */
final class ObjectShareLinkThrottle {
	/**
	 * Hold the throttler and the action it counts.
	 *
	 * @param IThrottler      $throttler Brute-force throttler for rejected tokens.
	 * @param LoggerInterface $logger    Records a throttler that failed.
	 * @param string          $action    The throttled action.
	 * @param string          $label     Prefix of the failure's log line.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/share-links-and-email-invites/spec.md
	 */
	public function __construct(
		public readonly IThrottler $throttler,
		public readonly LoggerInterface $logger,
		public readonly string $action,
		public readonly string $label,
	) {
	}//end __construct()

	/**
	 * Count one rejected token from the given address.
	 *
	 * @param string $address The caller's remote address.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/share-links-and-email-invites/spec.md
	 */
	public function countRejected(string $address): void {
		try {
			$this->throttler->registerAttempt(action: $this->action, ip: $address);
		} catch (\Throwable $failure) {
			$this->logger->warning($this->label.': registerAttempt failed: '.$failure->getMessage());
		}
	}//end countRejected()
}//end class

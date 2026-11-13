<?php

/**
 * OpenRegister ShareLifetime
 *
 * How long a federated share token is served.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Federation;

use DateInterval;
use DateTime;
use OCA\OpenRegister\Db\FederatedShare;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * A share is served for 90 days from its creation or its last renewal.
 *
 * A share without an expiry is not served: every share is created with one, and
 * the upgrade gives each existing share one.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */
final class ShareLifetime {

	/**
	 * The days a share is served from its creation or renewal.
	 *
	 * @var int
	 */
	public const DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function __construct(private readonly ITimeFactory $time) {
	}//end __construct()

	/**
	 * When a share created or renewed now expires.
	 *
	 * @return DateTime
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function newExpiry(): DateTime {
		return $this->now()->add(new DateInterval('P'.self::DAYS.'D'));
	}//end newExpiry()

	/**
	 * Whether the share is past its expiry, or has none.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function hasExpired(FederatedShare $share): bool {
		$expiresAt = $share->getExpiresAt();

		return $expiresAt === null || $expiresAt <= $this->now();
	}//end hasExpired()

	/**
	 * Whether the share expires within the given number of days, or has already.
	 *
	 * @param FederatedShare $share The share.
	 * @param int            $days  The days ahead.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function expiresWithin(FederatedShare $share, int $days): bool {
		$expiresAt = $share->getExpiresAt();

		return $expiresAt === null || $expiresAt <= $this->now()->add(new DateInterval('P'.max(0, $days).'D'));
	}//end expiresWithin()

	/**
	 * The current time.
	 *
	 * @return DateTime
	 */
	private function now(): DateTime {
		return $this->time->getDateTime();
	}//end now()
}//end class

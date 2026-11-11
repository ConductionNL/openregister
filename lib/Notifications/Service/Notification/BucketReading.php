<?php

/**
 * A token bucket together with the tokens it held when read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * The bucket, its refilled token count and the label a drop is logged under.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class BucketReading {

	/**
	 * Constructor.
	 *
	 * @param TokenBucket $bucket The bucket that was read.
	 * @param float       $tokens The refilled token count.
	 * @param string      $label  What a drop log line names, e.g. `rule="r" recipient="u"`.
	 */
	public function __construct(
		public readonly TokenBucket $bucket,
		public readonly float $tokens,
		public readonly string $label,
	) {
	}//end __construct()
}//end class

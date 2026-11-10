<?php

/**
 * The counts of one bulk restore or bulk purge, and the message that reports them.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * How many objects a bulk action handled, how many failed, and how many were not found.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class BatchOutcome {

	/**
	 * Constructor.
	 *
	 * @param int $done     Objects the action handled.
	 * @param int $failed   Objects it could not handle, the not-found ones included.
	 * @param int $notFound Objects asked for that were not found.
	 */
	public function __construct(
		public readonly int $done,
		public readonly int $failed,
		public readonly int $notFound,
	) {
	}//end __construct()

	/**
	 * The message for the response, e.g. "Restored 3 objects, 1 failed (1 not found)".
	 *
	 * @param string $verb The past tense of the action, e.g. "Restored".
	 *
	 * @return string The message.
	 */
	public function message(string $verb): string {
		$message = "{$verb} {$this->done} objects, {$this->failed} failed";
		if ($this->notFound > 0) {
			$message .= " ({$this->notFound} not found)";
		}

		return $message;
	}//end message()
}//end class

<?php

/**
 * OpenRegister GitHub token action failure
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

use Exception;

/**
 * How a failed GitHub token action is logged and answered.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class GitHubTokenFailure {
	/**
	 * Constructor.
	 *
	 * @param string $logged       The logged failure message; the exception message is appended.
	 * @param string $answer       The message the 500 answer carries.
	 * @param bool   $appendReason Whether the answer also carries the exception message.
	 */
	public function __construct(
		public readonly string $logged,
		public readonly string $answer,
		public readonly bool $appendReason = false,
	) {
	}//end __construct()

	/**
	 * The answer for one failure.
	 *
	 * @param Exception $exception What failed.
	 *
	 * @return string The message.
	 */
	public function answerFor(Exception $exception): string {
		if ($this->appendReason === true) {
			return $this->answer . ': ' . $exception->getMessage();
		}

		return $this->answer;
	}//end answerFor()
}//end class

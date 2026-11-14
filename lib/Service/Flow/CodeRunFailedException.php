<?php

/**
 * CodeRunFailedException: the code runner could not run the code, or the code failed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use RuntimeException;

/**
 * A code step call that did not return items.
 *
 * Thrown out of the code node on purpose: the engine reads the step's
 * `onError` policy from a throw, as for any other step failure. The runner's
 * log lines travel with it, so the failure can be read where it happened.
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */
final class CodeRunFailedException extends RuntimeException {

	/**
	 * The runner's log lines for the failed call.
	 *
	 * @var array<int, string>
	 */
	private array $logs = [];

	/**
	 * A failure with the runner's log lines.
	 *
	 * @param string             $message The reason, in words.
	 * @param array<int, string> $logs    The runner's log lines.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public static function withLogs(string $message, array $logs=[]): self {
		$failure = new self($message);
		$failure->logs = array_values(array_map('strval', $logs));

		return $failure;
	}//end withLogs()

	/**
	 * The runner's log lines for the failed call.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function getLogs(): array {
		return $this->logs;
	}//end getLogs()
}//end class

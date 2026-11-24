<?php

/**
 * CodeRunnerClientInterface: the flow-code-runner ExApp as the code node reaches it.
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

/**
 * Runs authored code in the `flow-code-runner` ExApp, never in Nextcloud.
 */
interface CodeRunnerClientInterface {
	/**
	 * The ExApp id of the runner, named in every refusal about its absence.
	 */
	public const APP_ID = 'flow-code-runner';

	/**
	 * Whether the runner is installed, enabled and answers its health check.
	 *
	 * @return bool Whether code steps can run.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-without-the-runner-the-code-step-is-hidden-and-refused
	 */
	public function isAvailable(): bool;

	/**
	 * Run one call in the runner.
	 *
	 * @param CodeRun $run The source, mode, items and limits.
	 *
	 * @return CodeRunAnswer The items the code returned, with the runner's log lines.
	 *
	 * @throws CodeRunFailedException When the runner is absent, refuses, or the code fails.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function run(CodeRun $run): CodeRunAnswer;
}//end interface

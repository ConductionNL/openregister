<?php

/**
 * FlowRunOptions
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * How one run of a flow is started: on which object, with what run-level
 * metadata, whether the caller waits for it, and the trigger recorded on it.
 *
 * Built by {@see FlowService::run()} and {@see FlowService::runForAction()};
 * the trigger is what the version pin reads to decide whether the run may
 * walk a draft.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
final class FlowRunOptions {

	/**
	 * Name the options.
	 *
	 * @param array<string, mixed> $subject `{uuid, register, schema}` of the object.
	 * @param array<string, mixed> $context Run-level metadata.
	 * @param bool                 $sync    Execute inline and return the finished run.
	 * @param string               $trigger The dispatch trigger recorded on the run.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function __construct(
		public readonly array $subject,
		public readonly array $context,
		public readonly bool $sync,
		public readonly string $trigger,
	) {
	}//end __construct()
}//end class

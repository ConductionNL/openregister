<?php

/**
 * Builds the guarded signal seam where none was injected.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCP\IGroupManager;

/**
 * The construction the flow run endpoint made itself, moved beside the seam
 * so the endpoint names only the interface. The seam it builds is the same
 * one as before: no logger, the given group manager, the default access rule.
 */
final class FlowRunSignals {

	/**
	 * Hold what the seam is built over.
	 *
	 * @param FlowRunMapperInterface  $mapper       Resolves a run uuid to the run.
	 * @param FlowRunServiceInterface $runner       Owns the unguarded delivery primitive.
	 * @param IGroupManager|null      $groupManager Resolves group assignment; absent, a group-assigned step refuses.
	 */
	public function __construct(
		private readonly FlowRunMapperInterface $mapper,
		private readonly FlowRunServiceInterface $runner,
		private readonly ?IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Build the seam.
	 *
	 * @return FlowRunSignalInterface The guarded seam.
	 */
	public function seam(): FlowRunSignalInterface {
		return new FlowRunSignalService(mapper: $this->mapper, runner: $this->runner, groupManager: $this->groupManager);
	}//end seam()
}//end class

<?php

/**
 * OpenRegister SystemApproval
 *
 * The app that approved a caller itself, for one object written as the system.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The app that took responsibility for the caller check, and the object it did so for.
 *
 * Handed to {@see LifecycleWriteBoundary::runningAsSystem()}, which marks the
 * write so the audit trail records that OpenRegister's own read and update
 * checks were skipped and which app approved the caller instead. Built only
 * on the path {@see TransitionEngine::transitionAsSystem()} opens.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SystemApproval {

	/**
	 * Name the approval.
	 *
	 * @param string $app  The app id that approved the caller itself.
	 * @param string $uuid The object's uuid.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class

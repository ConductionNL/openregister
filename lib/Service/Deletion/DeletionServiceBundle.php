<?php

/**
 * DeletionServiceBundle — the destruction-pipeline collaborators, wired once.
 *
 * The DeletedController used to take the window, right, scope, recorder and
 * clock services as five separate constructor parameters. They are one
 * cohesive concern — the recorded-destruction pipeline — so they travel
 * together as a single readonly bundle. The controller reads them as
 * `$this->deletion->window`, `$this->deletion->destruction->scope`, and so on; nothing
 * about the individual services changes, and Nextcloud autowires the bundle
 * from its promoted type-hints like any other service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

/**
 * A readonly bundle of the destruction-pipeline collaborators.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
class DeletionServiceBundle {
	/**
	 * Wire the destruction-pipeline collaborators.
	 *
	 * @param DeletionWindowService $window Publishes the recovery window.
	 * @param DestroyRightService $destroyRight Decides whether the caller may destroy.
	 * @param RetentionClockService $clock Reads the AVG and Archiefwet clocks.
	 * @param DestructionServices $destruction Carries out the declared scope and records it.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly DeletionWindowService $window,
		public readonly DestroyRightService $destroyRight,
		public readonly RetentionClockService $clock,
		public readonly DestructionServices $destruction,
	) {
	}//end __construct()
}//end class

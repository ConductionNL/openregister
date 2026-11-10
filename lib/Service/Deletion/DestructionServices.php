<?php

/**
 * DestructionServices — the two collaborators that carry out a destruction and record it.
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
 * The declared scope a destruction carries out and the record that survives it.
 *
 * The guards that decide whether a destruction may happen (the destroy right
 * and the retention clocks) are not in here: they stay direct members of
 * {@see DeletionServiceBundle} so a reader sees them where they are used.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DestructionServices {
	/**
	 * Wire the destruction collaborators.
	 *
	 * @param DestructionScopeService $scope Previews and carries out the declared scope.
	 * @param DestructionRecorder $recorder Writes the record that survives the object.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly DestructionScopeService $scope,
		public readonly DestructionRecorder $recorder,
	) {
	}//end __construct()
}//end class

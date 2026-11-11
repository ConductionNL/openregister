<?php

/**
 * OpenRegister ObjectService write services: save, removal and history
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;


/**
 * ObjectService write services: save, removal and history.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectWriteServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectSaveServices    $save    The save bundle.
	 * @param ObjectRemovalServices $removal The removal bundle.
	 * @param ObjectHistoryServices $history The history bundle.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectSaveServices $save,
		public readonly ObjectRemovalServices $removal,
		public readonly ObjectHistoryServices $history,
	) {
	}//end __construct()
}//end class

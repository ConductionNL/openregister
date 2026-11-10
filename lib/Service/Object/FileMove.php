<?php

/**
 * OpenRegister file move
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The two objects a merge moves files between: each file leaves the source for the target.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class FileMove {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $source The object the files leave.
	 * @param ObjectEntity $target The object the files go to.
	 */
	public function __construct(
		public readonly ObjectEntity $source,
		public readonly ObjectEntity $target,
	) {
	}//end __construct()
}//end class

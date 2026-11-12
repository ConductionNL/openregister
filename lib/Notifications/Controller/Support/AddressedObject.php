<?php

/**
 * OpenRegister addressed object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
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

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object a notes request resolved, with the path the caller addressed it by.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class AddressedObject {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The resolved object.
	 * @param NotePath     $path   The path the caller addressed it by.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly NotePath $path,
	) {
	}//end __construct()
}//end class

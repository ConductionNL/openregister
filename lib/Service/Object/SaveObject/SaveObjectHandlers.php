<?php

/**
 * The property and value handlers of a single-object save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * The property and value handlers of a single-object save.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SaveObjectPropertyHandlers $properties Metadata, file, linked-entity and computed-field handlers.
	 * @param SaveObjectValueHandlers    $values     Translation and field-encryption handlers.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly SaveObjectPropertyHandlers $properties,
		public readonly SaveObjectValueHandlers $values,
	) {
	}//end __construct()
}//end class

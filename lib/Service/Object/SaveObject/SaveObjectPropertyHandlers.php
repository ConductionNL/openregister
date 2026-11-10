<?php

/**
 * The handlers that fill and check an object's properties on save.
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
 * The handlers that fill and check an object's properties on save.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectPropertyHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MetadataHydrationHandler    $metaHydrationHandler Handler for metadata extraction.
	 * @param FilePropertyHandler         $filePropertyHandler  Handler for file property operations.
	 * @param LinkedEntityPropertyHandler $linkedEntityHandler  Linked entity property handler.
	 * @param ComputedFieldHandler        $computedFieldHandler Handler for computed field evaluation.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly MetadataHydrationHandler $metaHydrationHandler,
		public readonly FilePropertyHandler $filePropertyHandler,
		public readonly LinkedEntityPropertyHandler $linkedEntityHandler,
		public readonly ComputedFieldHandler $computedFieldHandler,
	) {
	}//end __construct()
}//end class

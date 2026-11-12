<?php

/**
 * The collaborators of a single-object save, apart from its access guards.
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
 * The collaborators of a single-object save, apart from its access guards.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectServices {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SaveObjectPersistence $persistence Mappers, records and runtime.
	 * @param SaveObjectHandlers    $handlers    Property and value handlers.
	 * @param SaveObjectPlatform    $platform    Session, URLs, logging and events.
	 * @param SaveObjectTenancy     $tenancy     Organisation and admin-bypass readers.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly SaveObjectPersistence $persistence,
		public readonly SaveObjectHandlers $handlers,
		public readonly SaveObjectPlatform $platform,
		public readonly SaveObjectTenancy $tenancy,
	) {
	}//end __construct()
}//end class

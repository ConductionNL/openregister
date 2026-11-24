<?php

/**
 * FilesMapperBindings: the map of the Files area's mapper interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;

/**
 * Interface => mapper map of the Files area, behind FilesMapperRegistrar.
 *
 * Holds the Files interfaces whose mapper lives in lib/Db. An alias resolves
 * to the class's own shared instance, so a caller that injects the interface
 * and one that injects the class by name get the same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class FilesMapperBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		EntityRelationMapperInterface::class => EntityRelationMapper::class,
	];
}//end class

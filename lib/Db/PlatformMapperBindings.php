<?php

/**
 * PlatformMapperBindings: the map of the Platform area's mapper interfaces.
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

/**
 * Interface => mapper map of the Platform area, behind PlatformMapperRegistrar.
 *
 * An alias resolves to the class's own shared instance, so a caller that
 * injects the interface and one that injects the class by name get the
 * same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class PlatformMapperBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		FeedbackMapperInterface::class => FeedbackMapper::class,
	];
}//end class

<?php

/**
 * ConflictReports: looks the conflict report up for the version guard.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Psr\Container\ContainerInterface;

/**
 * Looks the shared conflict report up for callers outside lib/Service.
 *
 * The version guard asked the container for ConflictReport by its class id;
 * this makes the same lookup, under the same id, and hands back what the
 * container returns, so the guard names only ConflictReportInterface.
 */
final class ConflictReports {

	/**
	 * The shared conflict report, exactly as the container returns it.
	 *
	 * @param ContainerInterface $container The container the caller holds.
	 *
	 * @return mixed The shared ConflictReportInterface instance.
	 */
	public static function from(ContainerInterface $container): mixed {
		return $container->get(ConflictReport::class);
	}//end from()
}//end class

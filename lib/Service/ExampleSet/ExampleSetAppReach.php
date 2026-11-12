<?php

/**
 * OpenRegister Example Set App Reach
 *
 * What an example set reaches another Nextcloud app and its accounts with:
 * the container that resolves the app's classes, the app manager that says
 * whether it is installed, and the user manager that resolves its accounts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Reaches the Talk, Collectives and profile apps an example set writes rows into.
 *
 * @spec openspec/specs/example-sets/spec.md
 */
final readonly class ExampleSetAppReach {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ContainerInterface $container Resolves another app's classes.
	 * @param IAppManager        $apps      Says whether that app is installed.
	 * @param IUserManager       $users     Resolves the accounts the rows name.
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function __construct(
		public ContainerInterface $container,
		public IAppManager $apps,
		public IUserManager $users,
	) {
	}//end __construct()
}//end class

<?php

/**
 * The container Deck's classes resolve from and the user lookup for card assignees.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * The container Deck's classes resolve from and the user lookup for card assignees.
 *
 * @spec openspec/specs/integration-deck/spec.md
 */
final class DeckPlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserManager       $userManager User lookup for assignee display names.
	 * @param ContainerInterface $container   Container the Deck app's services are resolved from.
	 *
	 * @spec openspec/specs/integration-deck/spec.md
	 */
	public function __construct(
		public readonly IUserManager $userManager,
		public readonly ContainerInterface $container,
	) {
	}//end __construct()
}//end class

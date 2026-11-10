<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\Share\IManager;

/**
 * One listing of an object's shares by ShareLinkService; never leaves that class.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class ShareListingRun {
	/**
	 * Share ids already listed, so a share seen on two passes is listed once.
	 *
	 * @var array<array-key, bool>
	 */
	public array $seen = [];

	/**
	 * Constructor.
	 *
	 * @param IManager           $manager The share manager.
	 * @param array<int, string> $sharers Whose shares are listed, in order.
	 */
	public function __construct(
		public readonly IManager $manager,
		public readonly array $sharers,
	) {
	}//end __construct()
}//end class

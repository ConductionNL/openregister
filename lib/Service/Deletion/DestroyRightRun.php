<?php

/**
 * One check of the destroy right: the object being destroyed and the caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * The object being destroyed and the signed-in, non-administrator caller
 * whose destroy right DestroyRightService is reading.
 *
 * Built by DestroyRightService::refusalFor() and never handed outside it.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DestroyRightRun {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object being destroyed.
	 * @param IUser        $caller The signed-in caller.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly IUser $caller,
	) {
	}//end __construct()
}//end class

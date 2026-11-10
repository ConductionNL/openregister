<?php

/**
 * OpenRegister validation lookups
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\IUserManager;

/**
 * What a validation looks existing objects and users up in.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class ValidationLookups {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface $objectMapper Object mapper.
	 * @param IUserManager         $userManager  Backend consulted by the `user` string format.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly IUserManager $userManager,
	) {
	}//end __construct()
}//end class

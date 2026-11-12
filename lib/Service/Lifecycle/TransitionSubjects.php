<?php

/**
 * The objects a transition reads and writes, with their schemas and registers, wired by DI autowiring.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The objects a transition reads and writes, with their schemas and registers, wired by DI autowiring.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionSubjects {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objectService Reads and saves the object.
	 * @param SchemaCatalogInterface    $catalog       Resolves the object's schema, its lifecycle and its register.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly SchemaCatalogInterface $catalog,
	) {
	}//end __construct()
}//end class

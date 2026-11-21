<?php

/**
 * The route of a delete or a delete analysis.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\ObjectHandlerAccessInterface;

/**
 * The object, register and schema a delete route names, and the service answering it.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class ObjectDeleteRoute {

	/**
	 * Constructor.
	 *
	 * @param string        $id            The object ID or UUID.
	 * @param string        $register      The register on the route.
	 * @param string        $schema        The schema on the route.
	 * @param ObjectHandlerAccessInterface $objectService The object service.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $register,
		public readonly string $schema,
		public readonly ObjectHandlerAccessInterface $objectService,
	) {
	}//end __construct()
}//end class

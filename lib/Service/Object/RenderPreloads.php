<?php

/**
 * RenderPreloads
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The registers, schemas and objects a caller already loaded for a render, primed into its caches.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderPreloads {
	/**
	 * Constructor.
	 *
	 * @param array|null $registers Preloaded registers
	 * @param array|null $schemas   Preloaded schemas
	 * @param array|null $objects   Preloaded objects
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ?array $registers,
		public readonly ?array $schemas,
		public readonly ?array $objects,
	) {
	}//end __construct()
}//end class

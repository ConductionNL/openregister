<?php

/**
 * ExtendDotRequest
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
 * The data one dot-path extend pass works on, the paths it extends and the links' exposure.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ExtendDotRequest {
	/**
	 * Constructor.
	 *
	 * @param array $data      The data to extend.
	 * @param array $extend    The dot paths to extend.
	 * @param array $exposures The relation descriptors that declare a field set, keyed by the
	 *                         property the link hangs on. Empty for every schema that declares none.
	 */
	public function __construct(
		public readonly array $data,
		public readonly array $extend,
		public readonly array $exposures = [],
	) {
	}//end __construct()
}//end class

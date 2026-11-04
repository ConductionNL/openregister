<?php

/**
 * One keyed entry of a configuration's `components` section.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * A component's key in the configuration (its slug unless the data names one),
 * its data and, once rejected, why.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportComponent
{

	/**
	 * Why the component was rejected, once it was.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $error = null;

	/**
	 * Constructor.
	 *
	 * @param int|string           $key  The component's key.
	 * @param array<string, mixed> $data The component's data.
	 */
	public function __construct(
		public readonly int|string $key,
		public array $data,
	) {
	}//end __construct()
}//end class

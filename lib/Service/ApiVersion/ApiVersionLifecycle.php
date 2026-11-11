<?php

/**
 * Where one declared API version stands in its life: its status and the
 * dates and successor that belong to that status.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiVersion;

/**
 * The declared status of a version with its deprecation date, sunset date and
 * successor, as declared and before {@see ApiVersion} validates them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
final readonly class ApiVersionLifecycle {

	/**
	 * Constructor.
	 *
	 * @param string $status One of ApiVersion::STATUSES.
	 * @param string|null $deprecatedOn ISO 8601 date the deprecation took effect.
	 * @param string|null $sunset ISO 8601 date the version stops answering.
	 * @param string|null $successor Identifier of the version to move to.
	 *
	 * @return void
	 */
	public function __construct(
		public string $status,
		public ?string $deprecatedOn = null,
		public ?string $sunset = null,
		public ?string $successor = null,
	) {

	}//end __construct()
}//end class

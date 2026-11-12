<?php

/**
 * Where a schema declares its selectielijst category and the property a record overrides it through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The schema's archive block and its configuration, whose
 * `x-openregister-archival` annotation may name the override property.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class CategoryDeclaration {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $archive       The schema's archive block.
	 * @param array<string, mixed> $configuration The schema's configuration.
	 */
	public function __construct(
		public readonly array $archive,
		public readonly array $configuration = [],
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister organisation edit
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
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

namespace OCA\OpenRegister\Service\Organisation;

/**
 * The edited fields of an organisation as they were and as they are now, by name.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class OrganisationEdit {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $before The edited fields as they were, by name.
	 * @param array<string, mixed> $after  The same fields as they are now.
	 */
	public function __construct(
		public readonly array $before,
		public readonly array $after,
	) {
	}//end __construct()
}//end class

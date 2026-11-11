<?php

/**
 * OpenRegister ImportOptions
 *
 * How imported rows are saved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * How imported rows are saved (ADR-011 Rule 2 options object).
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportOptions {
	/**
	 * Build the options.
	 *
	 * @param bool $validation Whether to validate objects against the schema.
	 * @param bool $events     Whether to dispatch object lifecycle events.
	 * @param bool $enrich     Whether to enrich objects with metadata.
	 * @param bool $publish    DEPRECATED no-op; publication is RBAC-driven. Logged when true.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly bool $validation = false,
		public readonly bool $events = false,
		public readonly bool $enrich = true,
		public readonly bool $publish = false,
	) {
	}//end __construct()
}//end class

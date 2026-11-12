<?php

/**
 * One audited change of an organisation: the typed action and what changed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

/**
 * One audited change of an organisation ({@see OrganisationLifecycleAuditor}).
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class OrganisationChange {
	/**
	 * Constructor.
	 *
	 * @param string               $action  The typed action, such as `organisation.updated`.
	 * @param array<string, mixed> $changed What changed, by field: `{old, new}`.
	 */
	public function __construct(
		public readonly string $action,
		public readonly array $changed,
	) {
	}//end __construct()
}//end class

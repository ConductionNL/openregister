<?php

/**
 * One part of a schema taken from upstream, as the audit trail records it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * A part taken from the shipped definition: which part, which kind of decision, and what decided it.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class UpstreamDecision {

	/**
	 * Constructor.
	 *
	 * @param string $path The part.
	 * @param string $kind `accept-shipped` for a conflict an administrator took, `applied-shipped` for a block only the app changed.
	 * @param string $via  What decided it, when not the acting user alone (an occ re-import); empty for none.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $kind = 'accept-shipped',
		public readonly string $via = '',
	) {
	}//end __construct()
}//end class

<?php

/**
 * The question an app asks before it works for an organisation: is this app,
 * or this node type of it, halted there?
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Oversight
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Oversight;

/**
 * Which organisation, app and, optionally, node type a halt is looked up for.
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */
final class HaltLookup {
	/**
	 * Describe the lookup.
	 *
	 * @param string      $organisation The organisation uuid.
	 * @param string      $app          The app.
	 * @param string|null $nodeType     The node type about to run, to match the prefix; null matches any halt of the app.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly string $organisation,
		public readonly string $app,
		public readonly ?string $nodeType = null,
	) {
	}//end __construct()
}//end class

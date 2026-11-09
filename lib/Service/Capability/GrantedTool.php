<?php

/**
 * Granted Tool
 *
 * The tool one grant in a {@see ToolGrantSet} hands out, with its argument constraints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Capability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/structured-tool-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Capability;

/**
 * A tool id as dispatch uses it, optionally narrowed to particular argument values.
 *
 * @spec openspec/specs/structured-tool-grants/spec.md#scenario-two-constrained-grants-for-one-tool-both-survive
 */
final class GrantedTool {
	/**
	 * Build the grant target.
	 *
	 * @param string               $toolId The tool id dispatch uses.
	 * @param array<string, mixed> $args   Argument constraints; empty means every target.
	 */
	public function __construct(
		public readonly string $toolId,
		public readonly array $args = [],
	) {
	}//end __construct()
}//end class

<?php

/**
 * Grant Coordinates
 *
 * Where one grant sits in a {@see ToolGrantSet}: app, subject and action.
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
 * The app => subject => action address of a grant.
 *
 * ⚠️ Coordinates are for the human and the UI; the tool id dispatch uses is
 * stored beside them ({@see GrantedTool}), never derived from them.
 *
 * @spec openspec/specs/structured-tool-grants/spec.md
 */
final class GrantCoordinates {
	/**
	 * Build the address.
	 *
	 * @param string $app     The owning app.
	 * @param string $subject The thing acted on.
	 * @param string $action  The verb.
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $subject,
		public readonly string $action,
	) {
	}//end __construct()
}//end class

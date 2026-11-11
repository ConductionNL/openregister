<?php

/**
 * Where one tool grant sits in a ToolGrantSet.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Capability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Capability;

/**
 * The app, subject and action that address one grant: app → subject → action.
 *
 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
 */
final class ToolGrantCoordinates
{

	/**
	 * Constructor.
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

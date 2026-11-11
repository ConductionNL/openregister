<?php

/**
 * OpenRegister portal case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

/**
 * A case object as the portal resolves a party against it: its uuid, which
 * a refusal names, and its data.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
 */
final class PortalCase {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data The case object's data.
	 * @param string               $uuid The case, for the refusal message; '' when unknown.
	 */
	public function __construct(
		public readonly array $data,
		public readonly string $uuid = '',
	) {
	}//end __construct()
}//end class

<?php

/**
 * The identity of the stored flow a steps action runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * The name (action key plus a hash of the lowered document) and the declaring
 * schema that together identify a steps action's stored flow.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
 */
final readonly class StepsFlowIdentity {

	/**
	 * Describe the identity.
	 *
	 * @param string $name       The flow name.
	 * @param string $schemaSlug The declaring schema.
	 */
	public function __construct(
		public string $name,
		public string $schemaSlug,
	) {
	}//end __construct()
}//end class

<?php

/**
 * What a dry run looks up before it walks: the flow, its document and the object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * What a dry run looks up before it walks: the flow, its document and the object.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowDryRunLookups {
	/**
	 * Bundle the services.
	 *
	 * @param FlowService                    $flows    Finds the flow within the caller's organisation.
	 * @param FlowLocator                    $locator  Builds the flow document the engine walks.
	 * @param ObjectOperationsInterface      $objects  Reads the object with the caller's access.
	 */
	public function __construct(
		public readonly FlowService $flows,
		public readonly FlowLocator $locator,
		public readonly ObjectOperationsInterface $objects,
	) {
	}//end __construct()
}//end class

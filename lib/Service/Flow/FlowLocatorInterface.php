<?php

/**
 * FlowLocatorInterface: what callers outside the Service component ask of FlowLocator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Resolving a flow id to its document, as the flow-run controllers and the
 * run worker use it. Only the method those callers call.
 */
interface FlowLocatorInterface {

	/**
	 * Load a flow document by its id.
	 *
	 * @param string $flowId The flow's uuid.
	 *
	 * @return array|null The flow document, or null when there is no such flow.
	 */
	public function resolveFlow(string $flowId): ?array;
}//end interface

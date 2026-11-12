<?php

/**
 * FlowPublishedGraphInterface: what callers outside the component use of FlowPublishedGraph.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Declares only what callers outside the component use of FlowPublishedGraph; the rest
 * of the class stays its own.
 */
interface FlowPublishedGraphInterface {

	/**
	 * The published graph of a flow, or null when it has no published version.
	 *
	 * @param string $flowId The flow.
	 *
	 * @return array<string, mixed>|null The published graph, or null.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function graphOf(string $flowId): ?array;
}//end interface

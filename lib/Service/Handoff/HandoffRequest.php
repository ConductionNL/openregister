<?php

/**
 * OpenRegister HandoffRequest
 *
 * One request to execute a declared handoff on a source object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * One request to execute a declared handoff on a source object.
 *
 * Built by {@see HandoffService::execute()} from its arguments; the
 * correlation id is already resolved (reused on a queue drain, minted
 * otherwise).
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */
final class HandoffRequest {

	/**
	 * Name the request.
	 *
	 * @param HandoffSourceRef $source        The source object.
	 * @param string           $handoffId     The declared handoff entry id.
	 * @param bool             $deferred      True when draining a parked queue entry.
	 * @param string           $correlationId The correlation id of this execution.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function __construct(
		public readonly HandoffSourceRef $source,
		public readonly string $handoffId,
		public readonly bool $deferred,
		public readonly string $correlationId,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister HandoffAuditFacts
 *
 * The facts both audit rows of one executed handoff carry.
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
 * The facts both audit rows of one executed handoff carry.
 *
 * Passed between the write helpers of {@see HandoffService}.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */
final class HandoffAuditFacts {

	/**
	 * Name the facts.
	 *
	 * @param string $kindUri       The kind URI.
	 * @param string $handoffId     The handoff entry id.
	 * @param string $correlationId The execution correlation id.
	 * @param bool   $deferred      Whether this execution drained a parked entry.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function __construct(
		public readonly string $kindUri,
		public readonly string $handoffId,
		public readonly string $correlationId,
		public readonly bool $deferred,
	) {
	}//end __construct()
}//end class

<?php

/**
 * Whose run this is: the flow being queued, the caller's uid and what started it.
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

/**
 * Whose run this is, as {@see FlowRunAttribution::resolve()} answers it.
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */
final class RunAttributionQuery {
	/**
	 * Constructor.
	 *
	 * @param string      $flowId  The flow being queued.
	 * @param string|null $user    The caller's uid, when there is a session.
	 * @param string      $trigger What started the run: `manual`, `schedule`, an event id, `sub-flow`, `nc-flow`.
	 */
	public function __construct(
		public readonly string $flowId,
		public readonly ?string $user,
		public readonly string $trigger,
	) {
	}//end __construct()
}//end class

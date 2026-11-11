<?php

/**
 * OpenRegister rule replay request
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * What a replay preview is asked for: the selection, the reason, the register and the acting user.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleReplayRequest {
	/**
	 * Describe the replay.
	 *
	 * @param array<string, mixed> $selection     The objects the replay runs over.
	 * @param string|null          $justification Why the operator replays the rule.
	 * @param int|null             $registerId    The register the selection is narrowed to.
	 * @param string               $actorUid      The actor the replay runs as.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly array $selection,
		public readonly ?string $justification,
		public readonly ?int $registerId,
		public readonly string $actorUid,
	) {
	}//end __construct()
}//end class

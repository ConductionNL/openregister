<?php

/**
 * One declared approval chain being judged against a lifecycle transition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

/**
 * The chain, its compiled template and the tiers frozen for this attempt.
 *
 * @spec openspec/specs/approval-workflow/spec.md#req-007
 */
final class ChainGateRun {
	/**
	 * Constructor.
	 *
	 * @param LifecycleEdit                         $edit          The update attempting the gated transition.
	 * @param string                                $chainKey      Declarative chain key.
	 * @param array<string, mixed>                  $template      The compiled template.
	 * @param array<int, array<string, mixed>>|null $tierPositions The tier(s), or null for no routing.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	public function __construct(
		public readonly LifecycleEdit $edit,
		public readonly string $chainKey,
		public readonly array $template,
		public readonly ?array $tierPositions,
	) {
	}//end __construct()
}//end class

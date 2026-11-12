<?php

/**
 * OpenRegister rules domain
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

use OCA\OpenRegister\Service\Rules\RuleReplayServiceInterface;

/**
 * The domain services of the rules API.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RulesReplay {
	/**
	 * Bundle the services.
	 *
	 * @param RuleReplayServiceInterface $replays Previews a replay over existing objects.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly RuleReplayServiceInterface $replays,
	) {
	}//end __construct()
}//end class

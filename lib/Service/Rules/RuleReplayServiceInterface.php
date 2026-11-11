<?php

/**
 * RuleReplayServiceInterface: what callers outside the Service component ask of RuleReplayService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

/**
 * Previewing a rule replay over a selection, as the rules controller uses it.
 * Only the method that caller calls.
 */
interface RuleReplayServiceInterface {

	/**
	 * Preview replaying a rule over a selection of objects.
	 *
	 * @param RuleReplayRequest   $request   The rule, who asks and why.
	 * @param RuleReplaySelection $selection The objects, and the schema and register they are scoped to.
	 *
	 * @return array<string, mixed> The previewed job, or the refusal.
	 *
	 * @throws RuleCeilingException When the selection is larger than the rule's ceiling.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function previewReplay(RuleReplayRequest $request, RuleReplaySelection $selection): array;
}//end interface

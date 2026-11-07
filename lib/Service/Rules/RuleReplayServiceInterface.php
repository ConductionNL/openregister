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

use OCA\OpenRegister\Db\Schema;

/**
 * Previewing a rule replay over a selection, as the rules controller uses it.
 * Only the method that caller calls.
 */
interface RuleReplayServiceInterface {

	/**
	 * Preview replaying a rule over a selection of objects.
	 *
	 * @param Schema               $schema        The schema the rule is declared on.
	 * @param string               $ruleId        The derived rule id.
	 * @param array<string, mixed> $selection     Either `{"ids": [...]}` or `{"query": {...}}`.
	 * @param string               $actorUid      The actor's uid.
	 * @param string|null          $justification The reason the operator typed.
	 * @param integer|null         $registerId    The register the selection lives in.
	 *
	 * @return array<string, mixed> The previewed job, or the refusal.
	 *
	 * @throws RuleCeilingException When the selection is larger than the rule's ceiling.
	 */
	public function preview(
		Schema $schema,
		string $ruleId,
		array $selection,
		string $actorUid,
		?string $justification = null,
		?int $registerId = null,
	): array;
}//end interface

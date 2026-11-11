<?php

/**
 * RuleEnablementServiceInterface: what callers outside the Service component ask of RuleEnablementService.
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
 * Switching a rule on or off, as the rules controller uses it. Only the method
 * and the constant that caller uses.
 */
interface RuleEnablementServiceInterface {

	/**
	 * The refusal when the rule id names nothing the schema declares.
	 *
	 * @var string
	 */
	public const CODE_UNKNOWN_RULE = 'rule-unknown';

	/**
	 * Switch one rule on or off.
	 *
	 * @param RuleSwitch $switch The schema the rule is declared on, the derived rule id and what to switch it to.
	 *
	 * @return array{ok: bool, rule?: array<string, mixed>, audit?: array<string, mixed>, error?: array{code: string, message: string}} The outcome.
	 */
	public function setEnabled(RuleSwitch $switch): array;
}//end interface

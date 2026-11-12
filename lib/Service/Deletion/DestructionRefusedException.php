<?php

/**
 * DestructionRefusedException — a refusal that names the rule that refused.
 *
 * Every way a destruction can be refused answers on the same terms: a status
 * code, a machine-readable rule and a sentence a person can act on. "Forbidden"
 * with no rule is the shape that makes an operator guess, and guessing about a
 * destructive act is how the wrong thing gets destroyed next time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Exception\RuleRefusedException;

/**
 * A destruction that was refused, carrying the rule that refused it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
class DestructionRefusedException extends RuleRefusedException {
	/**
	 * The fields that name the refused act.
	 *
	 * @return array<string, string> The labels.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	protected function responseLabels(): array {
		return ['error' => 'DESTRUCTION_REFUSED'];
	}//end responseLabels()
}//end class

<?php

/**
 * ExportRefusedException — a refused export that names the verb, not the record.
 *
 * "Forbidden" on an export tells an operator nothing they can act on. They do
 * not know whether the register is closed to them, whether one row was out of
 * scope, or whether they simply may not take data off the instance. The verb is
 * the answer to all three, so the refusal carries it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Exception\RuleRefusedException;

/**
 * An export that was refused, carrying the rule and the verb that refused it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
class ExportRefusedException extends RuleRefusedException {
	/**
	 * The fields that name the refused act.
	 *
	 * The body names the verb in its own field. A client that wants to hide an
	 * export button reads `verb`, not the sentence, so the sentence stays free
	 * to be rewritten without breaking anybody.
	 *
	 * @return array<string, string> The labels.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	protected function responseLabels(): array {
		return ['error' => 'EXPORT_REFUSED', 'verb' => ExportRightService::ACTION];
	}//end responseLabels()
}//end class

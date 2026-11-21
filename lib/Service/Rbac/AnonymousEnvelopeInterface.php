<?php

/**
 * AnonymousEnvelopeInterface: AnonymousEnvelope as the anonymous envelope middleware reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

/**
 * AnonymousEnvelope, typed for callers outside the Service component.
 *
 * Its outside caller, the anonymous envelope middleware, calls redact() and
 * redactConfiguration(), so that is the whole contract. Binding it in ContractBindings lets that caller resolve
 * AnonymousEnvelope without naming the class.
 */
interface AnonymousEnvelopeInterface {
	/**
	 * The output as an anonymous caller may read it, at any depth.
	 *
	 * @param mixed $data The output.
	 *
	 * @return mixed The output without the withheld `@self` fields.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function redact(mixed $data): mixed;

	/**
	 * A register or schema answer as an anonymous caller may read it.
	 *
	 * @param mixed $data The answer: one row or a `results` list of rows.
	 *
	 * @return mixed The answer without the rows' owner and authorization.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function redactConfiguration(mixed $data): mixed;
}//end interface

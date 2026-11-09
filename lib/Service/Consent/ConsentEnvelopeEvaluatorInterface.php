<?php

/**
 * ConsentEnvelopeEvaluatorInterface: the consent envelope evaluator as the consent on-save listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consent
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

namespace OCA\OpenRegister\Service\Consent;

/**
 * The consent envelope evaluator, typed for callers outside the Service component.
 *
 * Its outside caller, the consent on-save listener, calls evaluate(), so that is the
 * whole contract. The listener obtains one through
 * {@see ConsentEnvelopeEvaluators::evaluator()}, so it never names the class.
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */
interface ConsentEnvelopeEvaluatorInterface {
	/**
	 * Evaluate one consent-shaped property's write.
	 *
	 * @param string $name The property name (used only in the refusal message).
	 * @param array<string, mixed> $annotation The property's `x-openregister-consent` declaration.
	 * @param mixed $incoming The caller-submitted value for this property.
	 * @param mixed $persisted The previously persisted value for this property.
	 * @param string|null $actingIdentity The resolved acting identity ("by"), already resolved by the caller.
	 * @param string|null $ipAddress The caller's remote address, already resolved by the caller.
	 * @param string|null $userAgent The caller's User-Agent header, already resolved by the caller.
	 *
	 * @return array{refused: bool, value: array<int, array<string, mixed>>, message: string|null}
	 *     `refused` is true when an existing entry was mutated or dropped — `value` is then the
	 *     normalised-but-unfilled incoming array and `message` names the violation. Otherwise
	 *     `value` is the array to persist (with evidence filled on every newly appended entry)
	 *     and `message` is null.
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	public function evaluate(
		string $name,
		array $annotation,
		mixed $incoming,
		mixed $persisted,
		?string $actingIdentity,
		?string $ipAddress,
		?string $userAgent
	): array;
}//end interface

<?php

/**
 * AggregateReferenceResolverInterface: what callers outside the component use of AggregateReferenceResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Resolve the aggregates a calculation reads into its payload.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AggregateReferenceResolverInterface {

	/**
	 * As resolveAll(), and also which references could not be resolved and why.
	 *
	 * A failed reference resolves to null in `values` (the save path's rule);
	 * a caller that must not read that null as a real value (the rematerialise
	 * command: live pass O9 counted 205 rows "unchanged") reads `failed`.
	 *
	 * @param array<string, mixed> $payload    The object payload.
	 * @param array<string, mixed> $aggregates The declared aggregate-references.
	 * @param mixed                $registerRef The saving object's register.
	 *
	 * @return array{values: array<string, mixed>, failed: array<string, string>} Values and failures by name.
	 *
	 * @spec openspec/changes/rematerialise-writes-calculated-values/specs/computed-fields/spec.md
	 */
	public function resolveAllWithOutcome(array $payload, array $aggregates, mixed $registerRef = null): array;
}//end interface

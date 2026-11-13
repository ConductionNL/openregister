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
	 * @param ReferringObject      $referrer   The saving object: payload and register.
	 * @param array<string, mixed> $aggregates The declared aggregate-references.
	 *
	 * @return array{values: array<string, mixed>, failed: array<string, string>} Values and failures by name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function resolveOutcome(ReferringObject $referrer, array $aggregates): array;
}//end interface

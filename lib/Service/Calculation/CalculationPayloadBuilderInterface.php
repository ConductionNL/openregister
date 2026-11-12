<?php

/**
 * CalculationPayloadBuilderInterface: what callers outside the component use of CalculationPayloadBuilder.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Build the evaluation payload a calculation reads, and strip it again before a write.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface CalculationPayloadBuilderInterface {

	/**
	 * Enrich an object's data with `@self`, `@ref` and `@aggregate` blocks.
	 *
	 * Callers MUST strip the synthetic keys before persisting
	 * ({@see self::stripSyntheticKeys()}).
	 *
	 * @param ObjectEntity $object The object whose data to enrich.
	 * @param Schema $schema The object's schema (declares the reference blocks).
	 *
	 * @return array<string, mixed> The enriched evaluation payload.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 */
	public function build(ObjectEntity $object, Schema $schema): array;

	/**
	 * Strip the synthetic evaluation-only keys from a payload.
	 *
	 * @param array<string, mixed> $data The enriched payload.
	 *
	 * @return array<string, mixed> The payload without `@self` / `@ref` / `@aggregate` / the unresolved list.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 */
	public function stripSyntheticKeys(array $data): array;

	/**
	 * The unresolved references a calculation expression reads.
	 *
	 * @param array<string, mixed> $payload The payload {@see self::build()} returned.
	 * @param mixed $expression The calculation's JSON-AST expression.
	 *
	 * @return list<string> The unresolved reference names the expression reads, in first-read order.
	 *
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function unresolvedReferencesUsedBy(array $payload, mixed $expression): array;
}//end interface

<?php

/**
 * Resolves a golden record from linked source records, as the recompute listener asks for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

use DateTimeImmutable;

/**
 * Resolves a golden record from linked source records, as the recompute listener asks for it.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see SurvivorshipResolver} in ContractBindings.
 */
interface SurvivorshipResolverInterface {

	/**
	 * Resolve the golden record + attribute provenance for a set of linked
	 * source records.
	 *
	 * Never throws: a malformed source record (non-array, or one that raises
	 * while being processed) is simply skipped so one bad record cannot
	 * abort the whole resolution. Likewise a malformed override map (or
	 * malformed override entry) is skipped without throwing.
	 *
	 * @param string $entityType Entity type (passed through to the trust lookup; never hardcoded).
	 * @param array<int, array<string, mixed>> $sourceRecords Linked source records.
	 * @param array<string, mixed> $config The `x-openregister-survivorship` annotation.
	 * @param array<int, array<string, mixed>> $trustRows Candidate trust-configuration rows.
	 * @param TrustTierResolver $trustResolver Pure trust-tier lookup + decay engine.
	 * @param DateTimeImmutable $asOf Reference instant for effectiveFrom + decay.
	 * @param mixed $overrides Optional per-object attribute-override map
	 *                         (`attribute => {value, overriddenBy, rationale?}`
	 *                         or a bare `attribute => value` shorthand);
	 *                         untrusted input, validated defensively.
	 *
	 * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>}
	 *
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.1
	 */
	public function resolveGoldenRecord(
		string $entityType,
		array $sourceRecords,
		array $config,
		array $trustRows,
		TrustTierResolver $trustResolver,
		DateTimeImmutable $asOf,
		mixed $overrides = null,
	): array;
}//end interface

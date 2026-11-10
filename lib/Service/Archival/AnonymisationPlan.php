<?php

/**
 * One record's anonymisation, decided before anything is written.
 *
 * Carries the record, the profile that applies to it, the payload before and
 * after, and the salt fingerprint the pseudonyms were derived under. Every
 * target gets the same plan, so the search index and the audit trail cannot
 * disagree with the payload about what was removed.
 *
 * 🔴 THE SALT FINGERPRINT IS ON THE PLAN AND ON THE RECORD AFTERWARDS. A
 * pseudonym is stable only under one salt. If a run is interrupted and resumed
 * after the instance salt has rotated, the second half of the record would
 * carry tokens that no longer join the first half's, and nothing would say so:
 * the record would look anonymised and its statistics would be quietly wrong.
 * The fingerprint is what lets a resume refuse instead.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * What one anonymisation will do, before it does any of it.
 */
final class AnonymisationPlan {

	/**
	 * The payload as it is now.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $before;

	/**
	 * The payload as it will be.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $after;

	/**
	 * The properties this touches.
	 *
	 * @var string[]
	 */
	public readonly array $changedProperties;

	/**
	 * The properties deliberately kept.
	 *
	 * @var string[]
	 */
	public readonly array $keptProperties;

	/**
	 * Hold the plan.
	 *
	 * @param string            $objectUuid      The record.
	 * @param string            $profileName     The profile applied.
	 * @param AnonymisedPayload $payload         The payload before and after, and what that touches.
	 * @param string            $saltFingerprint Which salt the pseudonyms came from.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $profileName,
		AnonymisedPayload $payload,
		public readonly string $saltFingerprint,
	) {
		$this->before = $payload->before;
		$this->after = $payload->after;
		$this->changedProperties = $payload->changedProperties;
		$this->keptProperties = $payload->keptProperties;
	}//end __construct()

	/**
	 * Whether this plan would change anything at all.
	 *
	 * @return bool True when it touches at least one property.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function touchesAnything(): bool {
		return ($this->changedProperties !== []);
	}//end touchesAnything()
}//end class

<?php

/**
 * DedupCreatePolicyInterface: what callers outside the Search component use of DedupCreatePolicy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Quality
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

namespace OCA\OpenRegister\Search\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\DuplicateBlockedException;

/**
 * The part of DedupCreatePolicy that code outside the Search component uses.
 */
interface DedupCreatePolicyInterface {

	/**
	 * The payload key a caller sets to save through a blocking match.
	 *
	 * `SaveObject` strips it from the body before anything reads the body as
	 * data, so it never reaches schema validation, never lands in the stored
	 * object, and never has to be declared as a schema property. The constant
	 * lives here, beside the policy it belongs to, so the two spellings cannot
	 * drift apart.
	 *
	 * @var string
	 */
	public const OVERRIDE_KEY = '_dedupOverride';

	/**
	 * Audit action recorded when an override is exercised.
	 *
	 * @var string
	 */
	public const OVERRIDE_ACTION = 'dedup.overridden';

	/**
	 * Enforce `onCreate` for one create.
	 *
	 * Returns the matches an override was exercised over, so the caller can
	 * put them on the audit trail of the object it goes on to write, and an
	 * empty array in every other case. Throws when the create is refused.
	 *
	 * A match returned by {@see DuplicateDetectionService::checkCandidate()}
	 * is ALREADY at or above the schema's threshold — the scorer drops
	 * everything below it — so "there is a match" and "there is a strong
	 * match" are the same statement here, and re-testing the score against
	 * the threshold a second time would only invite the two to drift.
	 *
	 * @param CreateCandidate $candidate The register and schema, the body (override flag already removed),
	 *                                   and whether the caller asked to save anyway.
	 *
	 * @return array<int, array<string, mixed>> Matches overridden, for the audit entry; empty otherwise.
	 *
	 * @throws DuplicateBlockedException When the schema blocks and the caller may not override.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function guardCreate(CreateCandidate $candidate): array;

	/**
	 * Record on the created object that a blocking match was overridden.
	 *
	 * Best-effort: the object is already written, and losing the audit row
	 * must not undo a save that succeeded. It is logged at warning level
	 * instead, because an override that left no trace is the thing an
	 * administrator would want to know about.
	 *
	 * @param ObjectEntity $object The object that was created anyway.
	 * @param array<int, array<string, mixed>> $matches The matches it was created over.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function recordOverride(ObjectEntity $object, array $matches): void;
}//end interface

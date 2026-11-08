<?php

/**
 * DuplicateDetectionServiceInterface: the public surface of DuplicateDetectionService, for callers to type against.
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


/**
 * Every public method of DuplicateDetectionService. Callers depend on this interface, not on the class.
 */
interface DuplicateDetectionServiceInterface {

	/**
	 * Find duplicate-candidate pairs within a register/schema.
	 *
	 * @param int|string $register Register id, uuid or slug.
	 * @param int|string $schema Schema id, uuid or slug.
	 * @param array<int, mixed>|null $matchRules Optional caller-supplied match rules; when
	 *                                           null the schema's x-openregister-dedup rules apply.
	 * @param float|null $threshold Optional score cut-off; defaults to the
	 *                              annotation threshold or {@see DEFAULT_THRESHOLD}.
	 *
	 * @return array<int, array{
	 *   objectA: string,
	 *   objectB: string,
	 *   score: float,
	 *   matchedOn: array<int, string>
	 * }> Scored candidate pairs, highest score first. Empty when nothing reaches the threshold.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
	 */
	public function findDuplicates($register, $schema, ?array $matchRules = null, ?float $threshold = null): array;

	/**
	 * Score an UNSAVED candidate body against the stored objects of a
	 * register/schema, using the same rules, the same normalisation and the
	 * same cut-off {@see findDuplicates()} uses on stored pairs.
	 *
	 * Nothing is written. The candidate never becomes an object, is never
	 * given a uuid, and is never handed to `ObjectService`: it is compared in
	 * memory against what a read returns.
	 *
	 * BOUNDED like the sweep (design D-3): the same capped read, and then the
	 * same blocking, so the two can never disagree about which objects were
	 * even eligible to pair. The blocking runs HERE rather than as object
	 * filters on the read, and that is deliberate: a blocking token is
	 * NORMALISED ({@see SimilarityCalculator::blockingToken()}) while an
	 * object filter matches the stored value exactly, so pushing the
	 * candidate's raw value down as a filter would quietly drop every
	 * duplicate whose casing or spacing differs — which is most of them, and
	 * exactly the ones this exists to find.
	 *
	 * A candidate that cannot form a token (a blocking field it leaves empty)
	 * has no bucket to sit in and therefore no match — the same treatment
	 * {@see partition()} gives a stored object with an empty token.
	 *
	 * @param int|string $register Register id, uuid or slug — the boundary the schema resolves inside.
	 * @param int|string $schema Schema id, uuid or slug.
	 * @param array<string, mixed> $candidate The unsaved body.
	 * @param float|null $threshold Optional cut-off; the annotation's, then {@see DEFAULT_THRESHOLD}, when null.
	 *
	 * @return array<int, array{
	 *   uuid: string,
	 *   score: float,
	 *   matchedOn: array<int, string>,
	 *   matchedRules: array<int, array{field: string, method: string, similarity: float}>
	 * }> Matches, highest score first. Empty when the schema declares no usable rules.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function checkCandidate($register, $schema, array $candidate, ?float $threshold = null): array;

	/**
	 * The cut-off a register/schema applies, so a caller that needs to know
	 * whether a match is "strong" asks the same question the scorer answered.
	 *
	 * @param int|string $register Register reference.
	 * @param int|string $schema Schema reference.
	 * @param float|null $threshold Caller override, when supplied.
	 *
	 * @return float The effective threshold.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function effectiveThreshold($register, $schema, ?float $threshold = null): float;

	/**
	 * Read the `x-openregister-dedup` annotation of a register/schema pair.
	 *
	 * Public because the create policy needs the DECLARATION (`onCreate`,
	 * `overrideGroups`) and not the scoring, and reading the schema a second
	 * time in a second place is how two readers of one annotation drift apart.
	 *
	 * @param int|string $register Register reference — the boundary.
	 * @param int|string $schema Schema reference.
	 *
	 * @return array<string, mixed> The annotation, empty when absent or unresolvable.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function dedupAnnotation($register, $schema): array;

	/**
	 * The fingerprint of one pair: what the compared values looked like at the
	 * moment somebody judged them.
	 *
	 * Built from the NORMALISED values of the rule fields, in canonical order,
	 * for both objects. Normalised rather than raw so that re-saving a record
	 * with different spacing does not resurrect a dismissal somebody already
	 * made, and canonical so that the same pair fingerprints the same whichever
	 * way round it was reviewed.
	 *
	 * Public because the dismissal route has to store the fingerprint of the
	 * pair it is dismissing, and it must be the SAME function that the scorer
	 * later compares against. Two implementations of "what was compared" is how
	 * a dismissal silently stops matching itself.
	 *
	 * @param array<string, mixed> $dataA One object's payload.
	 * @param array<string, mixed> $dataB The other object's payload.
	 * @param array<int, array<string, mixed>> $rules The match rules that were compared.
	 *
	 * @return string A hex digest, or an empty string when there is nothing to fingerprint.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function pairFingerprint(array $dataA, array $dataB, array $rules): string;

	/**
	 * The rules a register/schema compares on, so a caller dismissing a pair
	 * fingerprints exactly what the scorer compared.
	 *
	 * @param int|string $register Register reference.
	 * @param int|string $schema Schema reference.
	 *
	 * @return array<int, array<string, mixed>> The effective match rules, empty when none are usable.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function effectiveRules($register, $schema): array;
}//end interface

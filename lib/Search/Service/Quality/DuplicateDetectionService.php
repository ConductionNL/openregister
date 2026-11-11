<?php

/**
 * OpenRegister DuplicateDetectionService
 *
 * Foundational, DI-resolvable duplicate-detection service. Consuming apps
 * call findDuplicates(register, schema[, matchRules]) to find candidate
 * duplicate objects via declarative match rules (blocking key + per-field
 * similarity: exact / normalized / levenshtein) and receive scored candidate
 * pairs. When match rules are omitted, the schema's `x-openregister-dedup`
 * annotation supplies them, so detection is declarative by default.
 *
 * The candidate set is fetched through ObjectService::findAll, so it is
 * RBAC- and tenant-scoped under the calling user's session. Comparison is
 * O(n^2) within a blocking bucket only; with declared blocking keys the
 * buckets are small. Without blocking keys the whole (RBAC-scoped) set is
 * compared pairwise — callers should declare blocking keys for large registers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
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
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolverInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolvers;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds scored duplicate-candidate pairs in a register/schema, and scores an
 * unsaved candidate against the stored set through the same rules.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
 *
 * TWO entry points over ONE rule engine: a sweep over stored pairs and a
 * check of an unsaved candidate. They are not split into two services: a
 * warning shown at intake and a duplicate found by a later sweep agree on what
 * a duplicate is only while they share one {@see DedupRuleResolver} and one
 * {@see PayloadComparator}, which this class builds and both entry points use.
 */
class DuplicateDetectionService implements DuplicateDetectionServiceInterface {
	/**
	 * Upper bound on the candidate set pulled per detection run.
	 *
	 * @var int
	 */
	private const MAX_CANDIDATES = 1000;

	/**
	 * The shared rule resolver, over the register-scoped schema resolver.
	 *
	 * Built here rather than injected: it is a stateless collaborator over the
	 * `RegisterMapper` + `SchemaMapper` this class already holds, so constructing
	 * it directly keeps every existing unit test — all of which mock those two
	 * mappers — exercising the REAL resolution path instead of a mock of the very
	 * thing under test.
	 *
	 * @var DedupRuleResolver
	 */
	private readonly DedupRuleResolver $ruleResolver;

	/**
	 * The shared payload comparator, built here for the same reason.
	 *
	 * @var PayloadComparator
	 */
	private readonly PayloadComparator $comparator;

	/**
	 * Wire collaborators.
	 *
	 * @param ObjectOperationsInterface $objectService Object query path (RBAC + tenant scoped).
	 * @param DedupScoringSources $sources Schema and register lookup and the similarity primitives.
	 * @param DismissedPairStore $dismissals Pairs a person has already ruled out.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		DedupScoringSources $sources,
		private readonly DismissedPairStore $dismissals,
		private readonly LoggerInterface $logger,
	) {
		$this->ruleResolver = self::ruleResolverOver(sources: $sources);
		$this->comparator = new PayloadComparator(similarity: $sources->similarity);
	}//end __construct()

	/**
	 * The rule resolver over the register-scoped schema lookup of the scoring sources.
	 *
	 * @param DedupScoringSources $sources Schema and register lookup and the similarity primitives.
	 *
	 * @return DedupRuleResolver
	 */
	private static function ruleResolverOver(DedupScoringSources $sources): DedupRuleResolver {
		return new DedupRuleResolver(
			scopedSchemaResolver: RegisterScopedSchemaResolvers::over(registerMapper: $sources->registerMapper, schemaMapper: $sources->schemaMapper)
		);
	}//end ruleResolverOver()


	/**
	 * Find duplicate-candidate pairs within a register/schema.
	 *
	 * @param DedupRun $request The register and schema (id, uuid or slug), optional caller-supplied
	 *                          match rules (when null the schema's x-openregister-dedup rules apply)
	 *                          and an optional score cut-off (defaults to the annotation threshold or
	 *                          {@see DedupRuleResolver::DEFAULT_THRESHOLD}).
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
	public function findDuplicates(DedupRun $request): array {
		return $this->duplicatesFor(request: $request);
	}//end findDuplicates()

	/**
	 * Scan a schema's objects for duplicate pairs; see findDuplicates().
	 *
	 * @param DedupRun $request The register, schema, optional rules and optional cut-off, unresolved.
	 *
	 * @return array<int, array<string, mixed>> Pairs, highest score first.
	 */
	private function duplicatesFor(DedupRun $request): array {
		$run = $this->ruleResolver->resolve(request: $request);
		if ($run === null) {
			return [];
		}

		$run = $run->withObjects(objects: $this->loadCandidates(run: $run));
		if (count($run->getObjects()) < 2) {
			return [];
		}

		return $this->byScoreDescending(rows: $this->withoutDismissedPairs(pairs: $this->scorePartitions(run: $run), run: $run));
	}//end duplicatesFor()

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
	 * {@see partition()} gives a stored object with an empty token. The token
	 * of a candidate is empty also when no blocking keys are declared, and
	 * then every stored object is compared.
	 *
	 * @param DedupRun             $request   The register (id, uuid or slug: the boundary the schema resolves inside),
	 *                                        the schema (id, uuid or slug) and the optional cut-off (the annotation's,
	 *                                        then {@see DedupRuleResolver::DEFAULT_THRESHOLD}, when null), unresolved.
	 * @param array<string, mixed> $candidate The unsaved body.
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
	public function checkCandidate(DedupRun $request, array $candidate): array {
		$run = $this->ruleResolver->resolve(request: $request);
		if ($run === null) {
			return [];
		}

		$run = $this->candidateRun(run: $run, candidate: $candidate);
		if ($run === null) {
			return [];
		}

		return $this->byScoreDescending(rows: $this->candidateMatches(candidate: $candidate, run: $run));
	}//end checkCandidate()

	/**
	 * The run narrowed to the stored objects in the candidate's blocking bucket.
	 *
	 * @param DedupRun             $run       The resolved run.
	 * @param array<string, mixed> $candidate The candidate's data.
	 *
	 * @return DedupRun|null The narrowed run, or null when blocking keys are declared and the candidate has no token.
	 */
	private function candidateRun(DedupRun $run, array $candidate): ?DedupRun {
		$token = $this->comparator->blockingToken(data: $candidate, keys: $run->getBlockingKeys());
		if ($token === '' && count($run->getBlockingKeys()) > 0) {
			return null;
		}

		$run = $run->withObjects(objects: $this->loadCandidates(run: $run));
		return $run->withObjects(objects: $this->inBucket(token: $token, run: $run));
	}//end candidateRun()

	/**
	 * The objects that share the candidate's blocking token; all of them when nothing blocks.
	 *
	 * @param string   $token The candidate's blocking token, empty when no keys are declared.
	 * @param DedupRun $run   The run.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function inBucket(string $token, DedupRun $run): array {
		if ($token === '') {
			return $run->getObjects();
		}

		return array_values(
			array_filter(
				$run->getObjects(),
				fn (ObjectEntity $object): bool => $this->comparator->blockingToken(
					data: ($object->getObject() ?? []),
					keys: $run->getBlockingKeys()
				) === $token
			)
		);
	}//end inBucket()

	/**
	 * Score an unsaved candidate against each object of the run, keeping those at or above the cut-off.
	 *
	 * @param array<string, mixed> $candidate The unsaved body.
	 * @param DedupRun             $run       The run.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function candidateMatches(array $candidate, DedupRun $run): array {
		$matches = [];
		foreach ($run->getObjects() as $object) {
			$result = $this->comparator->score(payloads: [$candidate, ($object->getObject() ?? [])], rules: $run->getRules());
			if ($result['score'] >= $run->getCutOff()) {
				$matches[] = (['uuid' => (string) $object->getUuid()] + $result);
			}
		}

		return $matches;
	}//end candidateMatches()

	/**
	 * Sort scored rows highest score first.
	 *
	 * @param array<int, array<string, mixed>> $rows Scored rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function byScoreDescending(array $rows): array {
		usort($rows, static fn (array $left, array $right) => $right['score'] <=> $left['score']);

		return $rows;
	}//end byScoreDescending()

	/**
	 * The cut-off a register/schema applies, so a caller that needs to know
	 * whether a match is "strong" asks the same question the scorer answered.
	 *
	 * @param DedupRun $request The register and schema reference and the caller override, when supplied.
	 *
	 * @return float The effective threshold.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function effectiveThreshold(DedupRun $request): float {
		$run = $this->ruleResolver->resolve(request: $request);
		return $run?->getCutOff() ?? DedupRuleResolver::DEFAULT_THRESHOLD;
	}//end effectiveThreshold()

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
	public function dedupAnnotation($register, $schema): array {
		return $this->ruleResolver->annotation(register: $register, schema: $schema);
	}//end dedupAnnotation()

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
	 * @param array{0: array<string, mixed>, 1: array<string, mixed>} $payloads The two objects' payloads.
	 * @param array<int, array<string, mixed>>                         $rules    The match rules that were compared.
	 *
	 * @return string A hex digest, or an empty string when there is nothing to fingerprint.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function pairFingerprint(array $payloads, array $rules): string {
		return $this->fingerprintOf(payloads: $payloads, rules: $this->ruleResolver->sanitise(rules: $rules));
	}//end pairFingerprint()

	/**
	 * The order-independent fingerprint of two payloads under sanitised rules.
	 *
	 * @param array{0: array<string, mixed>, 1: array<string, mixed>} $payloads The two payloads.
	 * @param array<int, array<string, mixed>>                         $rules    The sanitised match rules.
	 *
	 * @return string A hex digest, or an empty string when no rule survives sanitising.
	 */
	private function fingerprintOf(array $payloads, array $rules): string {
		if (count($rules) === 0) {
			return '';
		}

		$sides = [
			$this->comparator->fingerprintSide(data: $payloads[0], rules: $rules),
			$this->comparator->fingerprintSide(data: $payloads[1], rules: $rules),
		];
		sort($sides, SORT_STRING);

		return hash('sha256', implode('||', $sides));
	}//end fingerprintOf()

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
	public function effectiveRules($register, $schema): array {
		$run = $this->ruleResolver->resolve(request: new DedupRun(register: $register, schema: $schema));
		if ($run === null) {
			return [];
		}

		return $run->getRules();
	}//end effectiveRules()

	/**
	 * Drop the pairs a person has already ruled out, and only while their
	 * judgement still describes the data.
	 *
	 * A dismissal is compared by FINGERPRINT, not by pair alone. Two objects
	 * somebody looked at last month and called different people are not the
	 * same question once one of them changes a compared value, and a
	 * dismissal that outlived its evidence would hide a real duplicate
	 * forever — which is the failure mode of every "don't show me this again"
	 * button that stores only the pair.
	 *
	 * @param array<int, array<string, mixed>> $pairs The scored pairs.
	 * @param DedupRun $run The run, to read values and rules back from.
	 *
	 * @return array<int, array<string, mixed>> The pairs still worth offering.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	private function withoutDismissedPairs(array $pairs, DedupRun $run): array {
		$dismissals = $this->activeDismissals(pairs: $pairs, run: $run);
		if (count($dismissals) === 0) {
			return $pairs;
		}

		$offered = function (array $pair) use ($dismissals, $run): bool {
			$print = $this->dismissedFingerprint(pair: $pair, dismissals: $dismissals);
			return $print === null || $this->currentFingerprint(pair: $pair, run: $run) !== $print;
		};

		return array_values(array_filter($pairs, $offered));
	}//end withoutDismissedPairs()

	/**
	 * The fingerprint a pair was dismissed with, or null when it was not dismissed.
	 *
	 * A pair whose values moved since the judgement is a new question, and is offered again.
	 *
	 * @param array<string, mixed>                $pair       The scored pair.
	 * @param array<string, array<string, mixed>> $dismissals Dismissal rows by pair key.
	 *
	 * @return string|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `DismissedPairStore::key()` is a pure
	 *   function of two uuids with nothing to inject, and it has to be the SAME
	 *   function the store writes with: a second implementation of the canonical
	 *   order is a dismissal looked up under a key nothing ever stored it at.
	 */
	private function dismissedFingerprint(array $pair, array $dismissals): ?string {
		$dismissal = ($dismissals[DismissedPairStore::key(first: (string) $pair['objectA'], second: (string) $pair['objectB'])] ?? null);
		if ($dismissal === null) {
			return null;
		}

		return (string) ($dismissal['fingerprint'] ?? '');
	}//end dismissedFingerprint()

	/**
	 * The active dismissals of the run's register/schema; none are looked up when there are no pairs.
	 *
	 * @param array<int, array<string, mixed>> $pairs The scored pairs.
	 * @param DedupRun                         $run   The run.
	 *
	 * @return array<string, array<string, mixed>> Dismissal rows by pair key.
	 */
	private function activeDismissals(array $pairs, DedupRun $run): array {
		if (count($pairs) === 0) {
			return [];
		}

		return $this->dismissals->activeFor(registerSlug: (string) $run->register, schemaSlug: (string) $run->schema);
	}//end activeDismissals()

	/**
	 * The fingerprint of a scored pair as its objects read now.
	 *
	 * @param array<string, mixed> $pair The scored pair.
	 * @param DedupRun             $run  The run, with the payloads and rules.
	 *
	 * @return string
	 */
	private function currentFingerprint(array $pair, DedupRun $run): string {
		return $this->pairFingerprint(
			payloads: [($run->getPayloads()[$pair['objectA']] ?? []), ($run->getPayloads()[$pair['objectB']] ?? [])],
			rules: $run->getRules()
		);
	}//end currentFingerprint()

	/**
	 * Load the candidate object set, RBAC + tenant scoped.
	 *
	 * @param DedupRun $run The register/schema to read.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function loadCandidates(DedupRun $run): array {
		try {
			$objects = $this->objectService->findAll(
				[
					'filters' => ['register' => $run->register, 'schema' => $run->schema],
					'limit' => self::MAX_CANDIDATES,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Duplicate detection candidate load failed: ' . $e->getMessage());
			return [];
		}

		return array_values(array_filter($objects, static fn ($object): bool => $object instanceof ObjectEntity));
	}//end loadCandidates()

	/**
	 * Partition objects into comparison buckets by blocking key.
	 *
	 * Objects whose blocking-key token is empty are dropped from blocking
	 * (they would otherwise dominate a single bucket). With no blocking keys
	 * declared, every object lands in one bucket.
	 *
	 * @param array<int, ObjectEntity> $objects Candidate objects.
	 * @param array<int, string> $blockingKeys Field names to block on.
	 *
	 * @return array<string, array<int, ObjectEntity>>
	 */
	private function partition(array $objects, array $blockingKeys): array {
		if (count($blockingKeys) === 0) {
			return ['*' => $objects];
		}

		// Drop singleton buckets — a bucket of one has no pair to compare.
		return array_filter(
			$this->bucketsOf(objects: $objects, blockingKeys: $blockingKeys),
			static fn (array $bucket) => count($bucket) > 1
		);
	}//end partition()

	/**
	 * Group objects by their blocking-key token, leaving out those with an empty token.
	 *
	 * @param array<int, ObjectEntity> $objects      Candidate objects.
	 * @param array<int, string>       $blockingKeys Field names to block on.
	 *
	 * @return array<string, array<int, ObjectEntity>>
	 */
	private function bucketsOf(array $objects, array $blockingKeys): array {
		$buckets = [];
		foreach ($objects as $object) {
			$token = $this->comparator->blockingToken(data: ($object->getObject() ?? []), keys: $blockingKeys);
			if ($token !== '') {
				$buckets[$token][] = $object;
			}
		}

		return $buckets;
	}//end bucketsOf()

	/**
	 * Score every pair within each blocking bucket of the run.
	 *
	 * @param DedupRun $run The run.
	 *
	 * @return array<int, array<string, mixed>> The pairs at or above the cut-off.
	 */
	private function scorePartitions(DedupRun $run): array {
		$pairs = [];
		foreach ($this->partition(objects: $run->getObjects(), blockingKeys: $run->getBlockingKeys()) as $bucket) {
			array_push($pairs, ...$this->scoreBucket(bucket: $bucket, run: $run));
		}

		return $pairs;
	}//end scorePartitions()

	/**
	 * Score every pair within a bucket and collect those above the cut-off.
	 *
	 * @param array<int, ObjectEntity> $bucket Objects in one block.
	 * @param DedupRun $run The run, with the rules and cut-off.
	 *
	 * @return array<int, array<string, mixed>> The pairs at or above the cut-off.
	 */
	private function scoreBucket(array $bucket, DedupRun $run): array {
		$bucket = array_values($bucket);
		$count = count($bucket);
		$pairs = [];
		for ($i = 0; $i < $count; $i++) {
			for ($j = ($i + 1); $j < $count; $j++) {
				$result = $this->scorePair(pair: [$bucket[$i], $bucket[$j]], rules: $run->getRules());
				if ($result['score'] >= $run->getCutOff()) {
					$pairs[] = $result;
				}
			}
		}

		return $pairs;
	}//end scoreBucket()

	/**
	 * Score one pair of objects against the match rules.
	 *
	 * @param array{0: ObjectEntity, 1: ObjectEntity} $pair The two objects.
	 * @param array<int, array<string, mixed>> $rules Match rules.
	 *
	 * @return array{objectA: string, objectB: string, score: float, matchedOn: array<int, string>}
	 *
	 * @spec openspec/changes/mdm-dedup-nested-paths/tasks.md#task-2
	 */
	private function scorePair(array $pair, array $rules): array {
		[$a, $b] = $pair;
		$result = $this->comparator->score(payloads: [($a->getObject() ?? []), ($b->getObject() ?? [])], rules: $rules);

		return [
			'objectA' => (string) $a->getUuid(),
			'objectB' => (string) $b->getUuid(),
			'score' => $result['score'],
			'matchedOn' => $result['matchedOn'],
		];
	}//end scorePair()
}//end class

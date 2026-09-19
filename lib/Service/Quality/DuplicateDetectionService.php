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

namespace OCA\OpenRegister\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds scored duplicate-candidate pairs in a register/schema, and scores an
 * unsaved candidate against the stored set through the same rules.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) 61 against a threshold of 50,
 *   and the eleven points are the price of the thing this class exists to
 *   guarantee. It now has TWO entry points over ONE rule engine: a sweep over
 *   stored pairs and a check of an unsaved candidate. Splitting them into two
 *   services is the obvious way to get under the threshold and the wrong one:
 *   the whole point is that a warning shown at intake and a duplicate found by
 *   a later sweep agree on what a duplicate is, and they can only agree while
 *   they share `resolveConfig()`, `blockingTokenFor()`, `resolvePath()` and
 *   `scoreAgainstRules()`. Two classes would have two copies of that agreement
 *   and no way to notice when they drifted. Same reasoning MergeService records
 *   for the same rule.
 */
class DuplicateDetectionService {
	/**
	 * Default similarity threshold a pair must reach to be reported.
	 *
	 * @var float
	 */
	private const DEFAULT_THRESHOLD = 0.85;

	/**
	 * Upper bound on the candidate set pulled per detection run.
	 *
	 * @var int
	 */
	private const MAX_CANDIDATES = 1000;

	/**
	 * Per-field similarity at or above which the field itself counts as matched.
	 *
	 * Named because two readers now depend on it: the pair list's `matchedOn`
	 * and the check's `matchedRules`. A literal in two places is a literal
	 * that drifts.
	 *
	 * @var float
	 */
	private const FIELD_MATCH_SIMILARITY = 0.9;

	/**
	 * The shared register-scoped schema resolver.
	 *
	 * Built here rather than injected: it is a stateless collaborator over the
	 * `RegisterMapper` + `SchemaMapper` this class already holds, so constructing
	 * it directly keeps every existing unit test — all of which mock those two
	 * mappers — exercising the REAL resolution path instead of a mock of the very
	 * thing under test.
	 *
	 * @var RegisterScopedSchemaResolver
	 */
	private readonly RegisterScopedSchemaResolver $scopedSchemaResolver;

	/**
	 * Wire collaborators.
	 *
	 * @param ObjectService $objectService Object query path (RBAC + tenant scoped).
	 * @param SchemaMapper $schemaMapper Schema lookup for the dedup annotation.
	 * @param RegisterMapper $registerMapper Register lookup — the boundary the schema resolves inside.
	 * @param SimilarityCalculator $similarity Pure field-similarity primitives.
	 * @param DismissedPairStore $dismissals Pairs a person has already ruled out.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		SchemaMapper $schemaMapper,
		RegisterMapper $registerMapper,
		private readonly SimilarityCalculator $similarity,
		private readonly DismissedPairStore $dismissals,
		private readonly LoggerInterface $logger,
	) {
		$this->scopedSchemaResolver = new RegisterScopedSchemaResolver(
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper
		);
	}//end __construct()


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
	public function findDuplicates($register, $schema, ?array $matchRules = null, ?float $threshold = null): array {
		$config = $this->resolveConfig(register: $register, schema: $schema, matchRules: $matchRules, threshold: $threshold);
		if ($config === null) {
			return [];
		}

		[$rules, $blockingKeys, $cutOff] = $config;

		$objects = $this->loadCandidates(register: $register, schema: $schema);
		if (count($objects) < 2) {
			return [];
		}

		$blocks = $this->partition(objects: $objects, blockingKeys: $blockingKeys);

		$pairs = [];
		foreach ($blocks as $bucket) {
			$this->scoreBucket(bucket: $bucket, rules: $rules, cutOff: $cutOff, pairs: $pairs);
		}

		$pairs = $this->withoutDismissedPairs(
			pairs: $pairs,
			objects: $objects,
			rules: $rules,
			register: $register,
			schema: $schema
		);

		usort($pairs, static fn (array $left, array $right) => $right['score'] <=> $left['score']);

		return $pairs;
	}//end findDuplicates()

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
	 * @param array<int, mixed>|null $matchRules Optional caller-supplied rules; the annotation's when null.
	 * @param float|null $threshold Optional cut-off; the annotation's, then {@see DEFAULT_THRESHOLD}, when null.
	 *
	 * @return array<int, array{
	 *   uuid: string,
	 *   score: float,
	 *   matchedOn: array<int, string>,
	 *   matchedRules: array<int, array{field: string, method: string, similarity: float}>
	 * }> Matches, highest score first. Empty when the schema declares no usable rules.
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function checkCandidate($register, $schema, array $candidate, ?array $matchRules = null, ?float $threshold = null): array {
		$config = $this->resolveConfig(register: $register, schema: $schema, matchRules: $matchRules, threshold: $threshold);
		if ($config === null) {
			return [];
		}

		[$rules, $blockingKeys, $cutOff] = $config;

		$candidateToken = '';
		if (count($blockingKeys) > 0) {
			$candidateToken = $this->blockingTokenFor(data: $candidate, keys: $blockingKeys);
			if ($candidateToken === '') {
				// The candidate leaves a blocking field empty, so it belongs to
				// no bucket. Returning [] is the same judgement partition()
				// makes about a stored object with an empty token.
				return [];
			}
		}

		$objects = $this->loadCandidates(register: $register, schema: $schema);

		$matches = [];
		foreach ($objects as $object) {
			if ($candidateToken !== '') {
				$storedToken = $this->blockingTokenFor(data: ($object->getObject() ?? []), keys: $blockingKeys);
				if ($storedToken !== $candidateToken) {
					continue;
				}
			}

			$result = $this->scoreAgainstRules(
				dataA: $candidate,
				dataB: ($object->getObject() ?? []),
				rules: $rules
			);

			if ($result['score'] < $cutOff) {
				continue;
			}

			$matches[] = [
				'uuid' => (string)$object->getUuid(),
				'score' => $result['score'],
				'matchedOn' => $result['matchedOn'],
				'matchedRules' => $result['matchedRules'],
			];
		}

		usort($matches, static fn (array $left, array $right) => $right['score'] <=> $left['score']);

		return $matches;
	}//end checkCandidate()

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
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function effectiveThreshold($register, $schema, ?float $threshold = null): float {
		$config = $this->resolveConfig(register: $register, schema: $schema, matchRules: null, threshold: $threshold);
		if ($config === null) {
			return self::DEFAULT_THRESHOLD;
		}

		return $config[2];
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
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function dedupAnnotation($register, $schema): array {
		return $this->loadAnnotation(register: $register, schema: $schema);
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
	 * @param array<string, mixed> $dataA One object's payload.
	 * @param array<string, mixed> $dataB The other object's payload.
	 * @param array<int, array<string, mixed>> $rules The match rules that were compared.
	 *
	 * @return string A hex digest, or an empty string when there is nothing to fingerprint.
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function pairFingerprint(array $dataA, array $dataB, array $rules): string {
		$rules = $this->sanitiseRules(rules: $rules);
		if (count($rules) === 0) {
			return '';
		}

		$sides = [
			$this->fingerprintSide(data: $dataA, rules: $rules),
			$this->fingerprintSide(data: $dataB, rules: $rules),
		];
		sort($sides, SORT_STRING);

		return hash('sha256', implode('||', $sides));
	}//end pairFingerprint()

	/**
	 * One object's contribution to a pair fingerprint.
	 *
	 * @param array<string, mixed> $data The object's payload.
	 * @param array<int, array<string, mixed>> $rules The sanitised match rules.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	private function fingerprintSide(array $data, array $rules): string {
		$parts = [];
		foreach ($rules as $rule) {
			$field = (string)($rule['field'] ?? '');
			if ($field === '') {
				continue;
			}

			$parts[] = $field . '=' . $this->similarity->blockingToken(
				'normalized',
				$this->resolvePath(data: $data, path: $field)
			);
		}

		sort($parts, SORT_STRING);

		return implode('|', $parts);
	}//end fingerprintSide()

	/**
	 * The rules a register/schema compares on, so a caller dismissing a pair
	 * fingerprints exactly what the scorer compared.
	 *
	 * @param int|string $register Register reference.
	 * @param int|string $schema Schema reference.
	 *
	 * @return array<int, array<string, mixed>> The effective match rules, empty when none are usable.
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function effectiveRules($register, $schema): array {
		$config = $this->resolveConfig(register: $register, schema: $schema, matchRules: null, threshold: null);
		if ($config === null) {
			return [];
		}

		return $config[0];
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
	 * @param array<int, ObjectEntity> $objects The candidate objects, to read values back from.
	 * @param array<int, array<string, mixed>> $rules The match rules that were compared.
	 * @param int|string $register Register reference.
	 * @param int|string $schema Schema reference.
	 *
	 * @return array<int, array<string, mixed>> The pairs still worth offering.
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `DismissedPairStore::key()` is a pure
	 *   function of two uuids with nothing to inject, and it has to be the SAME
	 *   function the store writes with: a second implementation of the canonical
	 *   order is a dismissal looked up under a key nothing ever stored it at.
	 */
	private function withoutDismissedPairs(array $pairs, array $objects, array $rules, $register, $schema): array {
		// @SuppressWarnings below covers DismissedPairStore::key(): it is a pure
		// function of two uuids with no state to inject, and it MUST be the same
		// function the store writes with, or a dismissal would be looked up
		// under a key nothing ever stored it at.
		if (count($pairs) === 0) {
			return $pairs;
		}

		$dismissals = $this->dismissals->activeFor(
			registerSlug: (string)$register,
			schemaSlug: (string)$schema
		);
		if (count($dismissals) === 0) {
			return $pairs;
		}

		$payloads = [];
		foreach ($objects as $object) {
			$payloads[(string)$object->getUuid()] = ($object->getObject() ?? []);
		}

		$kept = [];
		foreach ($pairs as $pair) {
			$key = DismissedPairStore::key(
				first: (string)$pair['objectA'],
				second: (string)$pair['objectB']
			);

			$dismissal = ($dismissals[$key] ?? null);
			if ($dismissal === null) {
				$kept[] = $pair;
				continue;
			}

			$current = $this->pairFingerprint(
				dataA: ($payloads[$pair['objectA']] ?? []),
				dataB: ($payloads[$pair['objectB']] ?? []),
				rules: $rules
			);

			if ($current !== (string)($dismissal['fingerprint'] ?? '')) {
				// The values behind the judgement moved, so this is a new
				// question and the pair is offered again.
				$kept[] = $pair;
			}
		}//end foreach

		return $kept;
	}//end withoutDismissedPairs()

	/**
	 * Resolve effective rules, blocking keys and threshold.
	 *
	 * Returns `[rules, blockingKeys, threshold]`, or null when no usable rules exist.
	 *
	 * @param int|string $register Register reference — the boundary.
	 * @param int|string $schema Schema reference.
	 * @param array<int, mixed>|null $matchRules Caller-supplied rules, or null.
	 * @param float|null $threshold Caller-supplied threshold, or null.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>, 2: float}|null
	 */
	private function resolveConfig($register, $schema, ?array $matchRules, ?float $threshold): ?array {
		$annotation = $this->loadAnnotation(register: $register, schema: $schema);

		$rules = $matchRules;
		if ($rules === null) {
			$rules = ($annotation['matchRules'] ?? null);
		}

		$rules = $this->sanitiseRules(rules: $rules);
		if (count($rules) === 0) {
			return null;
		}

		$blockingKeys = [];
		$declaredKeys = ($annotation['blockingKeys'] ?? []);
		if (is_array($declaredKeys) === true) {
			foreach ($declaredKeys as $key) {
				if (is_string($key) === true && $key !== '') {
					$blockingKeys[] = $key;
				}
			}
		}

		$cutOff = $threshold;
		if ($cutOff === null) {
			$declared = ($annotation['threshold'] ?? null);
			$cutOff = self::DEFAULT_THRESHOLD;
			if (is_numeric($declared) === true) {
				$cutOff = (float)$declared;
			}
		}

		return [$rules, $blockingKeys, $cutOff];
	}//end resolveConfig()

	/**
	 * Filter a raw rule list down to well-formed match rules.
	 *
	 * @param mixed $rules Candidate rule list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitiseRules($rules): array {
		if (is_array($rules) === false) {
			return [];
		}

		$clean = [];
		foreach ($rules as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$field = (string)($rule['field'] ?? '');
			$method = (string)($rule['method'] ?? '');
			if ($field === '' || $method === '') {
				continue;
			}

			$clean[] = $rule;
		}

		return $clean;
	}//end sanitiseRules()

	/**
	 * Read the `x-openregister-dedup` annotation off a schema.
	 *
	 * REGISTER-SCOPED. `GET /api/objects/duplicates/{register}/{schema}` has always
	 * carried a register, and this lookup used to ignore it and resolve the slug
	 * globally — so on an instance where two apps share a schema slug, ANOTHER
	 * app's match rules and threshold decided which of THIS register's rows count
	 * as duplicates. Match rules name payload fields, so the wrong annotation
	 * silently compares fields that may not even exist here.
	 *
	 * A miss still degrades to `[]` rather than throwing, matching this method's
	 * pre-existing contract: an absent dedup annotation is a normal state and the
	 * caller falls back to its own rules.
	 *
	 * @param int|string $register Register reference — the boundary.
	 * @param int|string $schema Schema reference.
	 *
	 * @return array<string, mixed> Annotation (empty array when absent / unresolvable).
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	private function loadAnnotation($register, $schema): array {
		try {
			$entity = $this->scopedSchemaResolver->resolvePair(
				registerRef: $register,
				schemaRef: $schema
			)['schema'];
		} catch (Throwable $e) {
			return [];
		}

		$config = ($entity->getConfiguration() ?? []);
		$annotation = ($config['x-openregister-dedup'] ?? null);
		if (is_array($annotation) === true) {
			return $annotation;
		}

		return [];
	}//end loadAnnotation()

	/**
	 * Load the candidate object set, RBAC + tenant scoped.
	 *
	 * @param int|string $register Register reference.
	 * @param int|string $schema Schema reference.
	 * @return array<int, ObjectEntity>
	 */
	private function loadCandidates($register, $schema): array {
		try {
			$objects = $this->objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => self::MAX_CANDIDATES,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Duplicate detection candidate load failed: ' . $e->getMessage());
			return [];
		}

		$entities = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				$entities[] = $object;
			}
		}

		return $entities;
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

		$buckets = [];
		foreach ($objects as $object) {
			$data = ($object->getObject() ?? []);
			$token = $this->blockingTokenFor(data: $data, keys: $blockingKeys);
			if ($token === '') {
				continue;
			}

			$buckets[$token][] = $object;
		}

		// Drop singleton buckets — a bucket of one has no pair to compare.
		return array_filter($buckets, static fn (array $bucket) => count($bucket) > 1);
	}//end partition()

	/**
	 * Build a composite blocking token across the declared keys.
	 *
	 * @param array<string, mixed> $data Object payload.
	 * @param array<int, string> $keys Blocking field names (plain or dotted paths).
	 *
	 * @return string Composite token, or empty when any key is absent.
	 *
	 * @spec openspec/changes/mdm-dedup-nested-paths/tasks.md#task-2
	 */
	private function blockingTokenFor(array $data, array $keys): string {
		$parts = [];
		foreach ($keys as $key) {
			$token = $this->similarity->blockingToken('normalized', $this->resolvePath(data: $data, path: $key));
			if ($token === '') {
				return '';
			}

			$parts[] = $token;
		}

		return implode('|', $parts);
	}//end blockingTokenFor()

	/**
	 * Resolve a dotted-path field value from an object payload.
	 *
	 * A plain, dot-free field resolves exactly as a direct top-level array
	 * read. A dotted path (e.g. `goldenRecord.email`) traverses each
	 * segment in order and yields `null` — never throws — as soon as any
	 * segment is missing or its container is not an array. Mirrors the
	 * dot-path idiom used by {@see QualityScorer::fieldValue()}.
	 *
	 * @param array<string, mixed> $data Object payload.
	 * @param string $path Field name or dotted path.
	 *
	 * @return mixed The resolved value, or null when the path is missing.
	 *
	 * @spec openspec/changes/mdm-dedup-nested-paths/tasks.md#task-1
	 */
	private function resolvePath(array $data, string $path) {
		if ($path === '') {
			return null;
		}

		$segments = explode('.', $path);
		$cursor = $data;
		foreach ($segments as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end resolvePath()

	/**
	 * Score every pair within a bucket and collect those above the cut-off.
	 *
	 * @param array<int, ObjectEntity> $bucket Objects in one block.
	 * @param array<int, array<string, mixed>> $rules Match rules.
	 * @param float $cutOff Threshold.
	 * @param array<int, array<string, mixed>> $pairs Accumulator (by reference).
	 *
	 * @return void
	 */
	private function scoreBucket(array $bucket, array $rules, float $cutOff, array &$pairs): void {
		$bucket = array_values($bucket);
		$count = count($bucket);

		for ($i = 0; $i < $count; $i++) {
			for ($j = ($i + 1); $j < $count; $j++) {
				$result = $this->scorePair(a: $bucket[$i], b: $bucket[$j], rules: $rules);
				if ($result['score'] >= $cutOff) {
					$pairs[] = $result;
				}
			}
		}
	}//end scoreBucket()

	/**
	 * Score one pair of objects against the match rules.
	 *
	 * @param ObjectEntity $a First object.
	 * @param ObjectEntity $b Second object.
	 * @param array<int, array<string, mixed>> $rules Match rules.
	 *
	 * @return array{objectA: string, objectB: string, score: float, matchedOn: array<int, string>}
	 *
	 * @spec openspec/changes/mdm-dedup-nested-paths/tasks.md#task-2
	 */
	private function scorePair(ObjectEntity $a, ObjectEntity $b, array $rules): array {
		$result = $this->scoreAgainstRules(
			dataA: ($a->getObject() ?? []),
			dataB: ($b->getObject() ?? []),
			rules: $rules
		);

		return [
			'objectA' => (string)$a->getUuid(),
			'objectB' => (string)$b->getUuid(),
			'score' => $result['score'],
			'matchedOn' => $result['matchedOn'],
		];
	}//end scorePair()

	/**
	 * Score two PAYLOADS against the match rules.
	 *
	 * The one scorer both entry points run: {@see scorePair()} for two stored
	 * objects, {@see checkCandidate()} for an unsaved body against a stored
	 * one. Neither knows anything about the other's input, which is the point
	 * — a warning shown at intake and a duplicate found by a later sweep have
	 * to agree on what a duplicate is, and they can only agree if there is
	 * one definition.
	 *
	 * @param array<string, mixed> $dataA First payload.
	 * @param array<string, mixed> $dataB Second payload.
	 * @param array<int, array<string, mixed>> $rules Match rules.
	 *
	 * @return array{
	 *   score: float,
	 *   matchedOn: array<int, string>,
	 *   matchedRules: array<int, array{field: string, method: string, similarity: float}>
	 * }
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	private function scoreAgainstRules(array $dataA, array $dataB, array $rules): array {
		$weightedSum = 0.0;
		$totalWeight = 0.0;
		$matchedOn = [];
		$matchedRules = [];

		foreach ($rules as $rule) {
			$field = (string)($rule['field'] ?? '');
			$method = (string)($rule['method'] ?? '');
			$weight = 1.0;
			if (is_numeric($rule['weight'] ?? null) === true) {
				$weight = (float)$rule['weight'];
			}

			if ($weight <= 0.0) {
				continue;
			}

			$sim = $this->similarity->similarity(
				$method,
				$this->resolvePath(data: $dataA, path: $field),
				$this->resolvePath(data: $dataB, path: $field)
			);

			$weightedSum += ($sim * $weight);
			$totalWeight += $weight;

			if ($sim >= self::FIELD_MATCH_SIMILARITY) {
				$matchedOn[] = $field;
				$matchedRules[] = [
					'field' => $field,
					'method' => $method,
					'similarity' => round($sim, 4),
				];
			}
		}//end foreach

		$score = 0.0;
		if ($totalWeight > 0.0) {
			$score = round(($weightedSum / $totalWeight), 4);
		}

		return [
			'score' => $score,
			'matchedOn' => array_values(array_unique($matchedOn)),
			'matchedRules' => $matchedRules,
		];
	}//end scoreAgainstRules()
}//end class

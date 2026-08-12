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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds scored duplicate-candidate pairs in a register/schema.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
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
	 * Wire collaborators.
	 *
	 * @param ObjectService $objectService Object query path (RBAC + tenant scoped).
	 * @param SchemaMapper $schemaMapper Schema lookup for the dedup annotation.
	 * @param SimilarityCalculator $similarity Pure field-similarity primitives.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SchemaMapper $schemaMapper,
		private readonly SimilarityCalculator $similarity,
		private readonly LoggerInterface $logger,
	) {
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
		$config = $this->resolveConfig(schema: $schema, matchRules: $matchRules, threshold: $threshold);
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

		usort($pairs, static fn (array $left, array $right) => $right['score'] <=> $left['score']);

		return $pairs;
	}//end findDuplicates()

	/**
	 * Resolve effective rules, blocking keys and threshold.
	 *
	 * @param int|string $schema Schema reference.
	 * @param array<int, mixed>|null $matchRules Caller-supplied rules, or null.
	 * @param float|null $threshold Caller-supplied threshold, or null.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>, 2: float}|null
	 *                                                                                          [rules, blockingKeys, threshold] or null when no usable rules exist.
	 */
	private function resolveConfig($schema, ?array $matchRules, ?float $threshold): ?array {
		$annotation = $this->loadAnnotation(schema: $schema);

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
	 * @param int|string $schema Schema reference.
	 *
	 * @return array<string, mixed> Annotation (empty array when absent / unresolvable).
	 */
	private function loadAnnotation($schema): array {
		try {
			$entity = $this->schemaMapper->find($schema, _multitenancy: false);
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
	 *
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
		$dataA = ($a->getObject() ?? []);
		$dataB = ($b->getObject() ?? []);

		$weightedSum = 0.0;
		$totalWeight = 0.0;
		$matchedOn = [];

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

			if ($sim >= 0.9) {
				$matchedOn[] = $field;
			}
		}//end foreach

		$score = 0.0;
		if ($totalWeight > 0.0) {
			$score = round(($weightedSum / $totalWeight), 4);
		}

		return [
			'objectA' => (string)$a->getUuid(),
			'objectB' => (string)$b->getUuid(),
			'score' => $score,
			'matchedOn' => array_values(array_unique($matchedOn)),
		];
	}//end scorePair()
}//end class

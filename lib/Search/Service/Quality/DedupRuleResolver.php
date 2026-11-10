<?php

/**
 * Resolves the match rules, blocking keys and cut-off of a duplicate detection run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\Quality
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

use OCA\OpenRegister\Service\RegisterScopedSchemaResolverInterface;
use Throwable;

/**
 * Reads the `x-openregister-dedup` annotation and applies a caller's overrides.
 *
 * Both duplicate detection entry points resolve their configuration here, so
 * a sweep and a candidate check apply the same rules and the same cut-off.
 */
class DedupRuleResolver {
	/**
	 * Default similarity threshold a pair must reach to be reported.
	 *
	 * @var float
	 */
	public const DEFAULT_THRESHOLD = 0.85;

	/**
	 * Constructor.
	 *
	 * @param RegisterScopedSchemaResolverInterface $scopedSchemaResolver Resolves a schema inside its register.
	 */
	public function __construct(
		private readonly RegisterScopedSchemaResolverInterface $scopedSchemaResolver,
	) {
	}//end __construct()

	/**
	 * Resolve effective rules, blocking keys and threshold.
	 *
	 * @param DedupRun $request The register/schema and the caller's overrides.
	 *
	 * @return DedupRun|null The run with its rules, or null when no usable rules exist.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md
	 */
	public function resolve(DedupRun $request): ?DedupRun {
		$annotation = $this->annotation(register: $request->register, schema: $request->schema);
		$rules = $this->sanitise(rules: ($request->matchRules ?? ($annotation['matchRules'] ?? null)));
		if (count($rules) === 0) {
			return null;
		}

		return $request->withConfig(
			config: new DedupConfig(
				rules: $rules,
				blockingKeys: $this->blockingKeys(annotation: $annotation),
				cutOff: $this->cutOff(threshold: $request->threshold, annotation: $annotation)
			)
		);
	}//end resolve()

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
	 * A miss still degrades to `[]` rather than throwing: an absent dedup
	 * annotation is a normal state and the caller falls back to its own rules.
	 *
	 * @param mixed $register Register reference — the boundary.
	 * @param mixed $schema   Schema reference.
	 *
	 * @return array<string, mixed> Annotation (empty array when absent / unresolvable).
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function annotation(mixed $register, mixed $schema): array {
		try {
			$entity = $this->scopedSchemaResolver->resolvePair(registerRef: $register, schemaRef: $schema)['schema'];
		} catch (Throwable $e) {
			return [];
		}

		$annotation = (($entity->getConfiguration() ?? [])['x-openregister-dedup'] ?? null);
		if (is_array($annotation) === true) {
			return $annotation;
		}

		return [];
	}//end annotation()

	/**
	 * Filter a raw rule list down to well-formed match rules.
	 *
	 * @param mixed $rules Candidate rule list.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md
	 */
	public function sanitise(mixed $rules): array {
		if (is_array($rules) === false) {
			return [];
		}

		return array_values(array_filter($rules, fn ($rule): bool => $this->isWellFormed(rule: $rule)));
	}//end sanitise()

	/**
	 * Whether a rule is an array with a non-empty field and method.
	 *
	 * @param mixed $rule Candidate rule.
	 *
	 * @return bool
	 */
	private function isWellFormed(mixed $rule): bool {
		if (is_array($rule) === false) {
			return false;
		}

		return (string) ($rule['field'] ?? '') !== '' && (string) ($rule['method'] ?? '') !== '';
	}//end isWellFormed()

	/**
	 * The declared blocking keys that are non-empty strings.
	 *
	 * @param array<string, mixed> $annotation The dedup annotation.
	 *
	 * @return array<int, string>
	 */
	private function blockingKeys(array $annotation): array {
		$declared = ($annotation['blockingKeys'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		return array_values(array_filter($declared, static fn ($key): bool => is_string($key) === true && $key !== ''));
	}//end blockingKeys()

	/**
	 * The caller's threshold, else a numeric declared one, else the default.
	 *
	 * @param float|null           $threshold  Caller-supplied threshold, or null.
	 * @param array<string, mixed> $annotation The dedup annotation.
	 *
	 * @return float
	 */
	private function cutOff(?float $threshold, array $annotation): float {
		if ($threshold !== null) {
			return $threshold;
		}

		$declared = ($annotation['threshold'] ?? null);
		if (is_numeric($declared) === true) {
			return (float) $declared;
		}

		return self::DEFAULT_THRESHOLD;
	}//end cutOff()
}//end class

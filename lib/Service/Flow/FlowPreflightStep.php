<?php

/**
 * One typed step of a flow document, as the preflight reads it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-preflight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A node (or, in an un-migrated document, an edge) that names a step type.
 *
 * Every finding the preflight reports about a step carries the same four
 * leading fields — type, owning app, step id, reason — in that order. This
 * value builds them in one place so no check can spell them differently.
 *
 * It also answers the dialect half of the config check: given the node's
 * declared vocabulary ({@see IFlowNodeConfigKeys}), which of the step's keys
 * the node will never read. A node only sees the keys it looks for; the ones
 * it does not look for are invisible to it BY CONSTRUCTION.
 *
 * @spec openspec/specs/flow-preflight/spec.md
 */
class FlowPreflightStep {

	/**
	 * The `onError` policy the engine applies when an edge declares none.
	 *
	 * Mirrors `FlowEngine::ON_ERROR_STOP`, not imported from it: this class
	 * must not gain a dependency on the engine to know one default string.
	 */
	public const DEFAULT_ONERROR = 'stop';

	/**
	 * The key the engine reads from the EDGE and never from `config`.
	 */
	private const EDGE_LEVEL_ONERROR = 'onError';

	/**
	 * A report with nothing in it.
	 *
	 * @var array{blocking: array, warnings: array}
	 */
	private const NO_FINDINGS = [
		'blocking' => [],
		'warnings' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param array  $entry  The raw document entry declaring the step.
	 * @param string $type   The step type, trimmed and non-empty.
	 * @param string $stepId The entry's id, name or list position.
	 */
	public function __construct(
		public readonly array $entry,
		public readonly string $type,
		public readonly string $stepId,
	) {

	}//end __construct()

	/**
	 * The step's configuration as authored, which need not be an array.
	 *
	 * @return mixed The `config` value, or an empty array when absent.
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	public function config(): mixed {
		return ($this->entry['config'] ?? []);

	}//end config()

	/**
	 * The app a namespaced type belongs to.
	 *
	 * @return string The app id, or an empty string when the type is not namespaced.
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	public function app(): string {
		$separator = strpos($this->type, '.');
		if ($separator === false || $separator === 0) {
			return '';
		}

		return substr($this->type, 0, $separator);
	}//end app()

	/**
	 * A finding about this step.
	 *
	 * @param string      $reason The finding's reason constant.
	 * @param string|null $detail The detail, or null for a finding without one.
	 *
	 * @return array<string, string> The finding.
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	public function finding(string $reason, ?string $detail=null): array {
		$finding = [
			'type' => $this->type,
			'app' => $this->app(),
			'step' => $this->stepId,
			'reason' => $reason,
		];
		if ($detail !== null) {
			$finding['detail'] = $detail;
		}

		return $finding;
	}//end finding()

	/**
	 * The findings about a non-empty config checked against a vocabulary.
	 *
	 * Only asked once the config is known to be a non-empty array.
	 *
	 * One unknown-key finding per STEP, not per key. A step authored in the
	 * wrong dialect is wrong once — listing its four stray keys as four
	 * separate refusals reads like four separate mistakes.
	 *
	 * @param array<int, string> $known The node's declared vocabulary.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	public function dialectFindings(array $known): array {
		$strays = $this->strayKeys(config: $this->config(), known: $known);
		$report = $this->onErrorReport(misplaced: $strays['onError']);
		if ($strays['unknown'] !== []) {
			$finding = $this->finding(reason: FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY, detail: implode(', ', $strays['unknown']));
			$report['blocking'][] = ($finding + ['reads' => implode(', ', $known)]);
		}

		return $report;
	}//end dialectFindings()

	/**
	 * Describe an `onError` policy that was written one level too deep.
	 *
	 * It only warns when its value is the default the edge-level policy would
	 * give anyway: then the run behaves the same.
	 *
	 * @param boolean $misplaced Whether the config carries `onError`.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	private function onErrorReport(bool $misplaced): array {
		$report = self::NO_FINDINGS;
		if ($misplaced === true) {
			$detail = (string)($this->config()[self::EDGE_LEVEL_ONERROR] ?? '');
			$finding = $this->finding(reason: FlowNodePreflight::REASON_CONFIG_ONERROR_MISPLACED, detail: $detail);
			$report[$this->onErrorSeverity(detail: $detail)][] = $finding;
		}

		return $report;
	}//end onErrorReport()

	/**
	 * Which list a misplaced `onError` belongs in.
	 *
	 * @param string $detail The misplaced policy's value.
	 *
	 * @return string `warnings` when it is the default anyway, else `blocking`.
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	private function onErrorSeverity(string $detail): string {
		if ($detail === self::DEFAULT_ONERROR) {
			return 'warnings';
		}

		return 'blocking';
	}//end onErrorSeverity()

	/**
	 * Split a step's config keys into the ones nothing will read.
	 *
	 * @param array $config The step configuration.
	 * @param array<int, string> $known The node's declared vocabulary.
	 *
	 * @return array{unknown: array<int, string>, onError: bool}
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	private function strayKeys(array $config, array $known): array {
		$keys = array_map(static fn ($key): string => (string)$key, array_keys($config));
		$unknown = array_filter($keys, fn (string $key): bool => $this->isUnreadKey(key: $key, known: $known));

		return [
			'unknown' => array_values($unknown),
			'onError' => in_array(self::EDGE_LEVEL_ONERROR, $keys, true),
		];

	}//end strayKeys()

	/**
	 * Whether a config key is neither an annotation, nor `onError`, nor read.
	 *
	 * @param string             $key   The config key.
	 * @param array<int, string> $known The node's declared vocabulary.
	 *
	 * @return boolean True when nothing will ever read the key.
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	private function isUnreadKey(string $key, array $known): bool {
		return (str_starts_with($key, IFlowNodeConfigKeys::ANNOTATION_PREFIX) === false
			&& $key !== self::EDGE_LEVEL_ONERROR
			&& in_array($key, $known, true) === false);
	}//end isUnreadKey()
}//end class

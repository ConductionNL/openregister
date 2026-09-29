<?php

/**
 * OpenRegister ExpressionValueSources
 *
 * The one door from openregister's condition evaluation to integriq's
 * allowlisted value sources (`env:NAME` and other prefixed references).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/expression-value-sources/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves `{"source": "<prefix>:<key>"}` nodes through integriq's registry.
 *
 * Openregister never reads the environment itself: integriq holds the one
 * allowlist and the one audit trail. Without integriq, or when its registry
 * refuses a reference, the reference is unresolved and the caller fails closed.
 * A value is never logged, only the reference.
 */
class ExpressionValueSources {

	/**
	 * Integriq's registry, looked up by class name so openregister does not depend on integriq.
	 */
	public const REGISTRY_CLASS = 'OCA\\Integriq\\Expression\\ExpressionValueSourceRegistry';

	/**
	 * The node key that names a value source.
	 */
	public const NODE_KEY = 'source';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The server container.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a node, anywhere in it, names a value source.
	 *
	 * @param mixed $node The condition node.
	 *
	 * @return bool True when a source node is present.
	 *
	 * @spec openspec/changes/expression-value-sources/specs/flow-engine/spec.md
	 */
	public function mentionsSource(mixed $node): bool {
		if (is_array($node) === false) {
			return false;
		}

		if ($this->referenceOf(node: $node) !== null) {
			return true;
		}

		foreach ($node as $child) {
			if ($this->mentionsSource(node: $child) === true) {
				return true;
			}
		}

		return false;
	}//end mentionsSource()

	/**
	 * Replace every source node by its value.
	 *
	 * @param mixed $node The condition node.
	 *
	 * @return array{resolved: bool, node: mixed} The node with values in place,
	 *                                            and false when any reference stayed unresolved.
	 *
	 * @spec openspec/changes/expression-value-sources/specs/flow-engine/spec.md
	 */
	public function substitute(mixed $node): array {
		if (is_array($node) === false) {
			return ['resolved' => true, 'node' => $node];
		}

		$reference = $this->referenceOf(node: $node);
		if ($reference !== null) {
			return $this->resolve(reference: $reference);
		}

		foreach ($node as $key => $child) {
			$result = $this->substitute(node: $child);
			if ($result['resolved'] === false) {
				return ['resolved' => false, 'node' => null];
			}

			$node[$key] = $result['node'];
		}

		return ['resolved' => true, 'node' => $node];
	}//end substitute()

	/**
	 * The reference a node names, when it is a source node.
	 *
	 * @param array<mixed> $node The node.
	 *
	 * @return string|null The reference, or null when the node is not a source node.
	 */
	private function referenceOf(array $node): ?string {
		if (count($node) !== 1 || array_key_exists(self::NODE_KEY, $node) === false) {
			return null;
		}

		$reference = $node[self::NODE_KEY];
		if (is_string($reference) === false || str_contains($reference, ':') === false) {
			return null;
		}

		return $reference;
	}//end referenceOf()

	/**
	 * Resolve one reference through the registry.
	 *
	 * @param string $reference The reference, such as `env:SMTP_HOST`.
	 *
	 * @return array{resolved: bool, node: mixed} The value, or unresolved.
	 */
	private function resolve(string $reference): array {
		$registry = $this->registry();
		if ($registry === null) {
			$this->logger->warning(
				'[ExpressionValueSources] Value source {reference} cannot resolve: integriq is not installed; the condition does not hold.',
				['reference' => $reference]
			);
			return ['resolved' => false, 'node' => null];
		}

		try {
			return ['resolved' => true, 'node' => $registry->resolve($reference)];
		} catch (Throwable $e) {
			// The exception text is integriq's; it names the reference, never a value.
			$this->logger->warning(
				'[ExpressionValueSources] Value source {reference} was refused; the condition does not hold.',
				['reference' => $reference, 'refusal' => get_class($e)]
			);
			return ['resolved' => false, 'node' => null];
		}
	}//end resolve()

	/**
	 * Integriq's registry, when integriq is installed.
	 *
	 * @return object|null The registry.
	 */
	private function registry(): ?object {
		try {
			if ($this->container->has(self::REGISTRY_CLASS) === false) {
				return null;
			}

			$registry = $this->container->get(self::REGISTRY_CLASS);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($registry) === false || method_exists($registry, 'resolve') === false) {
			return null;
		}

		return $registry;
	}//end registry()
}//end class

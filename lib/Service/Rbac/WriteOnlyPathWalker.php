<?php

/**
 * Walks declared write-only dot-paths through an object body.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use Psr\Log\LoggerInterface;

/**
 * Strips, probes, reads and restores one declared write-only dot-path.
 *
 * PropertyRbacHandler decides WHICH paths are write-only and when; this class
 * only knows how to walk one of them through an array.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class WriteOnlyPathWalker {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for the debug trail of every strip and restore.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Remove one declared write-only dot-path from an outgoing array.
	 *
	 * This handles BOTH shapes OpenRegister renders a write-only value in,
	 * because the same declaration has to cover both and every caller of
	 * stripWriteOnlyProperties() passes one or the other:
	 *
	 * 1. The object BODY, where `configuration.authentication.client_secret`
	 *    is a nested structure to descend into and unset.
	 * 2. The `relations` search-index MIRROR, where SaveObject::scanForRelations()
	 *    flattens nested values into LITERAL dot-path keys — the mirror map is
	 *    already keyed `configuration.authentication.client_secret`. jsonSerialize()
	 *    surfaces that map as `@self.relations`, so a nested secret leaks there
	 *    even after the body strip, exactly as top-level writeOnly did in #429.
	 *
	 * Both operations are applied unconditionally and are mutually inert: a body
	 * has no literal dotted key (dots are not used in property names), and a flat
	 * mirror map has nothing to descend. That keeps this method total over every
	 * input the render path hands it, rather than relying on the caller to know
	 * which shape it holds.
	 *
	 * A declared path removes the value at that location AND its whole sub-tree —
	 * declaring `configuration.authentication` strips every key beneath it, and in
	 * the mirror that means every flattened key prefixed `configuration.authentication.`
	 * (this is what covers `configuration.authentication.keys.<apiKey>`, whose leaf
	 * segments are attacker-supplied and therefore cannot be enumerated in advance).
	 *
	 * @param string $path   The declared dot-path.
	 * @param array  $object The object body or relations mirror.
	 *
	 * @return array The array with the path removed.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-nested-writeonly-paths-must-never-be-returned-on-any-read
	 */
	public function strip(string $path, array $object): array {
		$object = $this->stripMirror(path: $path, object: $object);

		return $this->stripBody(segments: explode('.', $path), node: $object, path: $path);
	}//end strip()

	/**
	 * Whether a dot-path resolves to an existing key in an array.
	 *
	 * Uses array_key_exists rather than isset at every segment, because a location
	 * present with an explicit null MUST report true — that is precisely the "client
	 * deliberately cleared the secret" signal that isset() would silently misreport as
	 * absent, re-preserving the value the operator just asked to delete.
	 *
	 * @param string $path   The dot-path to probe.
	 * @param array  $object The array to probe.
	 *
	 * @return bool True when the path resolves to an existing key.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	public function exists(string $path, array $object): bool {
		$cursor = $object;

		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return false;
			}

			$cursor = $cursor[$segment];
		}

		return true;
	}//end exists()

	/**
	 * Read the value at a dot-path. Callers MUST have confirmed exists() first.
	 *
	 * @param string $path   The dot-path to read.
	 * @param array  $object The array to read from.
	 *
	 * @return mixed The value at that location (may legitimately be null).
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	public function read(string $path, array $object): mixed {
		$cursor = $object;

		foreach (explode('.', $path) as $segment) {
			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end read()

	/**
	 * Write one stored write-only value back into the payload at its declared path.
	 *
	 * The mirror of strip()'s body walk, inverted: that one descends and unsets,
	 * this one descends and sets. It differs in exactly one deliberate respect —
	 * where the strip BAILS on a missing intermediate segment (never create keys on
	 * the way out), this one CREATES it. A payload that dropped the whole
	 * `authentication` block still has to get its secret back, and the parent has to
	 * exist to hold it.
	 *
	 * Bails only when an intermediate segment exists but holds a NON-array: the client
	 * replaced that sub-tree with a scalar, so there is nowhere to put the leaf. Forcing
	 * it would silently discard the value the client actually sent.
	 *
	 * @param string $path     The declared dot-path.
	 * @param array  $prepared The payload to restore into.
	 * @param mixed  $value    The stored value to write, verbatim.
	 *
	 * @return array The payload with the value restored.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
	 */
	public function restore(string $path, array $prepared, mixed $value): array {
		$segments = explode('.', $path);
		if ($this->canHold(parents: array_slice($segments, 0, -1), node: $prepared) === false) {
			// The client sent a scalar where this path expects a sub-tree.
			return $prepared;
		}

		// Deliberately never logs the value — only that a restore happened.
		$this->logPath(message: '[PropertyRbacHandler] Preserved omitted write-only value on save', path: $path);

		return $this->setPath(segments: $segments, node: $prepared, value: $value);
	}//end restore()

	/**
	 * Shape 2: the flattened relations mirror. Remove an exact dot-path key and every key beneath it.
	 *
	 * @param string $path   The declared dot-path.
	 * @param array  $object The relations mirror (or a body, on which this is inert).
	 *
	 * @return array The array without the matching keys.
	 */
	private function stripMirror(string $path, array $object): array {
		$prefix = $path . '.';
		foreach (array_keys($object) as $key) {
			$key = (string) $key;
			if ($key === $path || str_starts_with($key, $prefix) === true) {
				unset($object[$key]);
				$this->logPath(message: '[PropertyRbacHandler] Stripped write-only path from relations mirror', path: $key);
			}
		}

		return $object;
	}//end stripMirror()

	/**
	 * Shape 1: the nested object body. Descend the segments and unset the final one.
	 *
	 * Bails out the moment the path stops resolving — a declared path that is absent
	 * from this object is normal (the value was never set) and must not create keys
	 * on the way down.
	 *
	 * @param array<int, string> $segments The remaining path segments, at least one.
	 * @param array              $node     The sub-tree the first segment is read from.
	 * @param string             $path     The whole declared path, for the log.
	 *
	 * @return array The sub-tree with the leaf removed.
	 */
	private function stripBody(array $segments, array $node, string $path): array {
		$head = array_shift($segments);
		if (array_key_exists($head, $node) === false) {
			return $node;
		}

		if ($segments === []) {
			unset($node[$head]);
			$this->logPath(message: '[PropertyRbacHandler] Stripped write-only path', path: $path);
			return $node;
		}

		if (is_array($node[$head]) === true) {
			$node[$head] = $this->stripBody(segments: $segments, node: $node[$head], path: $path);
		}

		return $node;
	}//end stripBody()

	/**
	 * Whether every parent on the path is absent, null or an array, so the leaf has somewhere to go.
	 *
	 * @param array<int, string> $parents The path segments above the leaf.
	 * @param array              $node    The payload.
	 *
	 * @return bool False when a parent holds a non-array.
	 */
	private function canHold(array $parents, array $node): bool {
		foreach ($parents as $segment) {
			$node = ($node[$segment] ?? []);
			if (is_array($node) === false) {
				return false;
			}
		}

		return true;
	}//end canHold()

	/**
	 * Descend the segments, creating an absent or null parent, and set the leaf.
	 *
	 * Only called once canHold() said every parent can hold a child.
	 *
	 * @param array<int, string> $segments The remaining path segments, at least one.
	 * @param array              $node     The sub-tree the first segment is written into.
	 * @param mixed              $value    The value for the leaf.
	 *
	 * @return array The sub-tree with the leaf set.
	 */
	private function setPath(array $segments, array $node, mixed $value): array {
		$head = array_shift($segments);
		if ($segments !== []) {
			$value = $this->setPath(segments: $segments, node: ($node[$head] ?? []), value: $value);
		}

		$node[$head] = $value;
		return $node;
	}//end setPath()

	/**
	 * Debug-log one path; never the value at it.
	 *
	 * @param string $message The message.
	 * @param string $path    The path or mirror key.
	 *
	 * @return void
	 */
	private function logPath(string $message, string $path): void {
		$this->logger->debug(message: $message, context: ['file' => __FILE__, 'line' => __LINE__, 'path' => $path]);
	}//end logPath()
}//end class

<?php

/**
 * OpenRegister NodeWriteTarget
 *
 * The node a file operation is about to change, with the path it was reached by.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCP\Files\Node;
use Throwable;

/**
 * A node about to change and the path it was reached by.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */
final class NodeWriteTarget {

	/**
	 * Constructor.
	 *
	 * @param Node   $node The node about to change.
	 * @param string $path The node's path, read once before any check.
	 */
	public function __construct(
		public readonly Node $node,
		public readonly string $path,
	) {
	}//end __construct()

	/**
	 * The log context of a refusal on this node.
	 *
	 * The node id is read only here, when a refusal is logged: a node that
	 * does not exist yet has none.
	 *
	 * @param Throwable $failure Why the owner could not be checked.
	 *
	 * @return array{node: int|null, path: string, exception: Throwable} The context.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
	 */
	public function logContext(Throwable $failure): array {
		return ['node' => $this->nodeId(), 'path' => $this->path, 'exception' => $failure];
	}//end logContext()

	/**
	 * The node's id for the log, or null when it has none yet.
	 *
	 * @return int|null The id.
	 */
	private function nodeId(): ?int {
		try {
			return $this->node->getId();
		} catch (Throwable $e) {
			return null;
		}
	}//end nodeId()
}//end class

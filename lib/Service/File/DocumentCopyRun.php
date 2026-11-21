<?php

/**
 * The state of copying one register's tree.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\DocumentMove;
use OCP\Files\Node;

/**
 * The mapping rows, the old-to-new ids and the node pairs of one register's copy.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
final class DocumentCopyRun {

	/**
	 * Old node id => new node id, files and folders.
	 *
	 * @var array<int, int>
	 */
	public array $moved = [];

	/**
	 * Old node id => `file` or `folder`.
	 *
	 * @var array<int, string>
	 */
	public array $kinds = [];

	/**
	 * Old node => its copy.
	 *
	 * @var list<array{0: Node, 1: Node}>
	 */
	public array $pairs = [];

	/**
	 * Old file id => old file id, for files copied without their earlier versions.
	 *
	 * @var array<int, int>
	 */
	public array $withoutVersions = [];

	/**
	 * Constructor.
	 *
	 * @param int                         $registerId           The register.
	 * @param array<string, DocumentMove> $rows                 Its mapping rows by key().
	 * @param bool                        $keepsVersions        Whether the target keeps earlier versions.
	 * @param bool                        $allowWithoutVersions Whether a versioned file may move without its versions.
	 * @param DocumentMoveOutcome         $outcome              Counts and refusals.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly int $registerId,
		public array $rows,
		public readonly bool $keepsVersions,
		public readonly bool $allowWithoutVersions,
		public readonly DocumentMoveOutcome $outcome,
	) {
	}//end __construct()

	/**
	 * Record that a node was copied.
	 *
	 * @param Node   $old  The source node.
	 * @param Node   $new  Its copy.
	 * @param string $kind `file` or `folder`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function map(Node $old, Node $new, string $kind): void {
		$this->moved[(int)$old->getId()] = (int)$new->getId();
		$this->kinds[(int)$old->getId()] = $kind;
		$this->pairs[] = [$old, $new];
	}//end map()

	/**
	 * The key of a mapping row: kind, old id and, for a version, its time.
	 *
	 * @param string   $kind  `file`, `folder` or `version`.
	 * @param int      $oldId The old node id.
	 * @param int|null $time  A version's original time.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public static function key(string $kind, int $oldId, ?int $time = null): string {
		return sprintf('%s:%d:%d', $kind, $oldId, (int)$time);
	}//end key()
}//end class

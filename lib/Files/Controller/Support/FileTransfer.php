<?php

/**
 * OpenRegister file transfer
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller\Support;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One copy or move of a file from the object a route names to another object.
 *
 * Carries the route and file, whether the file leaves its source, and the
 * source and target objects once they are resolved.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileTransfer {

	/**
	 * True when the file leaves the source object.
	 *
	 * @var boolean
	 */
	private bool $move = false;

	/**
	 * The object the file comes from, once resolved.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $source = null;

	/**
	 * The object the file goes to, once resolved.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $target = null;

	/**
	 * The register of the target object.
	 *
	 * @var string
	 */
	public string $targetRegister = '';

	/**
	 * The schema of the target object.
	 *
	 * @var string
	 */
	public string $targetSchema = '';

	/**
	 * A copy of the file named by the route.
	 *
	 * @param FileObjectRoute $route  The source object, as the route names it.
	 * @param int             $fileId The file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly FileObjectRoute $route,
		public readonly int $fileId,
	) {
	}//end __construct()

	/**
	 * The same transfer as a move: the file leaves the source object.
	 *
	 * @return self The move.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function asMove(): self {
		$move       = clone $this;
		$move->move = true;
		return $move;
	}//end asMove()

	/**
	 * Whether the file leaves the source object.
	 *
	 * @return bool True for a move.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function isMove(): bool {
		return $this->move;
	}//end isMove()

	/**
	 * The audit action on the source object; the target's is this plus `_in`.
	 *
	 * @return string `file.moved` or `file.copied`.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function auditAction(): string {
		if ($this->move === true) {
			return 'file.moved';
		}

		return 'file.copied';
	}//end auditAction()

	/**
	 * The status of a successful transfer: a copy creates a file, a move does not.
	 *
	 * @return int 201 for a copy, 200 for a move.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function successStatus(): int {
		if ($this->move === true) {
			return 200;
		}

		return 201;
	}//end successStatus()

	/**
	 * The status of a failed transfer, read from the failure's message.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return int 404 when something was not found, 423 when a moved file is locked, 400 otherwise.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function failureStatus(Exception $e): int {
		if (str_contains($e->getMessage(), 'not found') === true) {
			return 404;
		}

		if ($this->move === true && str_contains($e->getMessage(), 'locked') === true) {
			return 423;
		}

		return 400;
	}//end failureStatus()
}//end class

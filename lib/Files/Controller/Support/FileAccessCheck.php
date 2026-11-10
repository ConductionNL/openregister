<?php

/**
 * OpenRegister file access check
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller\Support
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

use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;

/**
 * What a file action needs from the object rule before it runs.
 *
 * Without further word it is a change that adds files, for a signed-in caller.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileAccessCheck {

	/**
	 * True when an anonymous caller may pass; publication gates them instead.
	 *
	 * @var boolean
	 */
	public bool $allowAnonymous = false;

	/**
	 * True for an action that changes files, which needs update on the object.
	 *
	 * @var boolean
	 */
	public bool $change = true;

	/**
	 * The file verb a change needs when the schema names file verbs.
	 *
	 * @var string
	 */
	public string $verb = ObjectFileAccess::ADD_FILES;

	/**
	 * The file the action is on, when it is on one.
	 *
	 * @var integer|null
	 */
	public ?int $fileId = null;

	/**
	 * A check on the object a route names.
	 *
	 * @param FileObjectRoute $route The object, as the route names it.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(public readonly FileObjectRoute $route) {
	}//end __construct()

	/**
	 * Make this a check for reading: read on the object is enough.
	 *
	 * @return self This check.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function forReading(): self {
		$this->change = false;
		return $this;
	}//end forReading()

	/**
	 * Let an anonymous caller through; the action gates them on publication.
	 *
	 * @return self This check.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function forAnyone(): self {
		$this->allowAnonymous = true;
		return $this;
	}//end forAnyone()

	/**
	 * Make this a check for removing a file from the object.
	 *
	 * @return self This check.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function forRemoving(): self {
		$this->verb = ObjectFileAccess::DELETE_FILES;
		return $this;
	}//end forRemoving()

	/**
	 * Name the file the action is on; a restricted file answers as if it were not there.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return self This check.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function onFile(int $fileId): self {
		$this->fileId = $fileId;
		return $this;
	}//end onFile()
}//end class

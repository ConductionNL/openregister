<?php

/**
 * FileUploadRun
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One create or save of an uploaded file onto an object, inside CreateFileHandler.
 *
 * The object starts as given (an entity or a uuid) and is replaced by the
 * resolved entity; the content starts unset and holds the decoded bytes once
 * they are known.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileUploadRun {

	/**
	 * The object the file belongs to: an entity, or the uuid it was asked for by.
	 *
	 * @var ObjectEntity|string|null
	 */
	public ObjectEntity|string|null $object = null;

	/**
	 * The decoded content, or a stream.
	 *
	 * @var mixed
	 */
	public mixed $content = null;

	/**
	 * Start a run.
	 *
	 * @param string     $action What the run does: `create` or `save`.
	 * @param FileUpload $upload The file name, content, share toggle and tags.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly string $action,
		public readonly FileUpload $upload,
	) {
	}//end __construct()
}//end class

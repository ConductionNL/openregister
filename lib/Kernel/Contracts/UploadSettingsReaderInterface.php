<?php

/**
 * UploadSettingsReaderInterface: the upload-in-parts settings of the instance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The part size and session lifetime of an upload in parts, and their bounds.
 *
 * The port the Files component reads the file settings' `upload` key through,
 * instead of the Configuration FileSettingsHandler that stores them. Bound to
 * Service\Settings\FileSettingsHandler.
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-3
 */
interface UploadSettingsReaderInterface {
	/**
	 * The default part size of an upload in parts: 10 MiB.
	 *
	 * @var int
	 */
	public const DEFAULT_PART_SIZE = 10485760;

	/**
	 * The smallest part size an organisation may set: 1 MiB.
	 *
	 * @var int
	 */
	public const MIN_PART_SIZE = 1048576;

	/**
	 * The largest part size an organisation may set: 100 MiB.
	 *
	 * @var int
	 */
	public const MAX_PART_SIZE = 104857600;

	/**
	 * How long an unfinished upload session lives after its last part, as an ISO 8601 duration.
	 *
	 * @var string
	 */
	public const DEFAULT_SESSION_TTL = 'PT24H';

	/**
	 * The upload-in-parts settings: the part size and the session lifetime, each
	 * its default when nothing usable is stored.
	 *
	 * @return array{partSizeBytes: int, sessionTtl: string}
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-3
	 */
	public function uploadSettings(): array;
}//end interface

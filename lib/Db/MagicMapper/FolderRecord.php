<?php

/**
 * FolderRecord
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The folder an object's files live in, as made or found by the file service, and the value read before it was made.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class FolderRecord {

	/**
	 * Constructor.
	 *
	 * @param ?string $expected The folder value read before the folder was made: null or '' for none.
	 * @param string  $folderId The node id of the folder the file service made or found.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(
		public readonly ?string $expected,
		public readonly string $folderId,
	) {
	}//end __construct()
}//end class

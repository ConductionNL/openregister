<?php

/**
 * Blob rows of one register and schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

/**
 * The blob rows that share a register and schema, with the ids read from their group key.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class BlobGroup {

	/**
	 * Register id, as written in the group key.
	 *
	 * @var string
	 */
	public readonly string $registerId;

	/**
	 * Schema id, as written in the group key.
	 *
	 * @var string
	 */
	public readonly string $schemaId;

	/**
	 * Constructor
	 *
	 * @param string                           $key  Group key `<registerId>_<schemaId>`
	 * @param array<int, array<string, mixed>> $rows Blob rows of the group
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(string $key, public readonly array $rows) {
		[$this->registerId, $this->schemaId] = explode('_', $key);
	}//end __construct()
}//end class

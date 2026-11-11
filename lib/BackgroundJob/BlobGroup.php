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
		$parts            = explode('_', $key);
		$this->registerId = $parts[0];
		$this->schemaId   = $parts[1];
	}//end __construct()

	/**
	 * The register and schema ids, keyed for a log context.
	 *
	 * @return array{registerId: string, schemaId: string}
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function ids(): array {
		return ['registerId' => $this->registerId, 'schemaId' => $this->schemaId];
	}//end ids()
}//end class

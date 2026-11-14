<?php

/**
 * OpenRegister safeguard batch run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;

/**
 * One batch of bulk rows going through the Wave-12 safeguards: the rows, the batch defaults,
 * the caller's flags, and the result the refused rows land in.
 *
 * No strict_types: SaveObjects declares none, and these values arrive from it.
 * Built by SaveObjects for one batch and never leaves SaveObjects.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SafeguardBatchRun {

	/**
	 * Whether the rows went through the safeguard screen (it is skipped without its dependencies).
	 *
	 * @var bool
	 */
	public bool $screened = false;

	/**
	 * Uuids of the passed rows that update a stored object, for the bulk quota.
	 *
	 * @var array<string, bool>
	 */
	public array $storedUuids = [];

	/**
	 * Constructor.
	 *
	 * @param array<int|string, array<string, mixed>> $objects  The rows.
	 * @param BulkBatchDefaults                       $defaults Default register and schema of the batch.
	 * @param array<string, bool>                     $flags    The caller's rbac, validation and multitenancy flags.
	 * @param array                                   $result   Result accumulator; refused rows are added to it.
	 */
	public function __construct(
		public readonly array $objects,
		public readonly BulkBatchDefaults $defaults,
		public readonly array $flags,
		public array $result,
	) {
	}//end __construct()
}//end class

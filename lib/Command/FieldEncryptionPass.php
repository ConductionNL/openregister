<?php

/**
 * One magic table and the column an encryption run works on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

/**
 * Carries the table, its column and the run through the steps of one table.
 *
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-existing-plaintext-values-can-be-migrated-to-encrypted
 */
final class FieldEncryptionPass {

	/**
	 * The column of the property.
	 *
	 * @var string
	 */
	public string $column = '';

	/**
	 * Values found in this table.
	 *
	 * @var integer
	 */
	public int $scanned = 0;

	/**
	 * Values encrypted in this table, or that a dry run would encrypt.
	 *
	 * @var integer
	 */
	public int $encrypted = 0;

	/**
	 * Values that failed in this table.
	 *
	 * @var integer
	 */
	public int $failed = 0;

	/**
	 * Constructor.
	 *
	 * @param MagicTableTarget $target The table
	 * @param MagicTableRun $run The run
	 */
	public function __construct(
		public readonly MagicTableTarget $target,
		public readonly MagicTableRun $run,
	) {
	}//end __construct()
}//end class

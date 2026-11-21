<?php

/**
 * OpenRegister Filled Row
 *
 * One example-set row with its placeholders filled, and the accounts the set creates or adopts, as a row check reads them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The filled fields of one row and the account ids the set creates or adopts, which its principals must be among.
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
 */
final class FilledRow {

	/**
	 * The row's fields, placeholders filled.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $data;

	/**
	 * The account ids the set creates or adopts.
	 *
	 * @var array<int, string>
	 */
	public readonly array $users;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data  The row's fields, placeholders filled.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 */
	public function __construct(array $data, array $users) {
		$this->data  = $data;
		$this->users = $users;
	}//end __construct()
}//end class

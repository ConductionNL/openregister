<?php

/**
 * OpenRegister Row Install Step
 *
 * One declared row of a row install, as the install handles it.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The row install run and one declared row: its record name, whether the record holds it, what it holds and where it is.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final class RowInstallStep {

	/**
	 * The row install run.
	 *
	 * @var RowInstallRun
	 */
	public readonly RowInstallRun $run;

	/**
	 * The declared row as the install handles it.
	 *
	 * @var array{entry: array<string, mixed>, name: string, held: bool, earlier: array<string, mixed>, where: string}
	 */
	public readonly array $row;

	/**
	 * Constructor.
	 *
	 * @param RowInstallRun        $run The row install run.
	 * @param array<string, mixed> $row The declared row as the install handles it: entry, name, held, earlier, where.
	 */
	public function __construct(RowInstallRun $run, array $row) {
		$this->run = $run;
		$this->row = $row;
	}//end __construct()
}//end class

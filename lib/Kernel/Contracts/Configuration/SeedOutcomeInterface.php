<?php

/**
 * SeedOutcomeInterface: what one seed run reports.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Configuration;

/**
 * The outcome of one seed run, as the repair step reports it.
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */
interface SeedOutcomeInterface {

	/**
	 * Nothing failed.
	 */
	public const STATUS_SUCCESS = 'success';

	/**
	 * The run's status: success, partial or failed.
	 *
	 * @return string The status.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public function status(): string;

	/**
	 * One line saying what the run did.
	 *
	 * @return string The summary.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public function summary(): string;

	/**
	 * Every failure of the run, named.
	 *
	 * @return list<array{file: string, entity: string, slug: string, reason: string}>
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
	 */
	public function failures(): array;
}//end interface

<?php

/**
 * OpenRegister Example Set Remover Rows
 *
 * The remover's accounts and engine rows: what an install made beyond objects and settings.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The remover's accounts and engine rows: what an install made beyond objects and settings.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
final readonly class ExampleSetRemoverRows {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ExampleSetAccounts         $accounts   Accounts and groups.
	 * @param ExampleSetEngineKinds|null $engineRows Deletes the tasks and saved views the set wrote; null names them as failed.
	 *
	 * @return void
	 */
	public function __construct(
		public ExampleSetAccounts $accounts,
		public ?ExampleSetEngineKinds $engineRows=null,
	) {
	}//end __construct()
}//end class

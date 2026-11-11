<?php

/**
 * OpenRegister ExampleSetInstallerSetup
 *
 * The installer's set-up collaborators: accounts, prerequisites, app config and user preferences.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The installer's set-up collaborators: accounts, prerequisites, app config and user preferences.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
final readonly class ExampleSetInstallerSetup {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ExampleSetPeople          $people          The cast's and the service accounts' accounts and groups.
	 * @param ExampleSetPrerequisites   $prerequisites   Registers and organisations before the check, and the base context.
	 * @param ExampleSetAppConfig       $appConfig       Writes a fragment's own app config.
	 * @param ExampleSetUserPreferences $userPreferences Writes a fragment's own app's preferences of the set's accounts.
	 *
	 * @return void
	 */
	public function __construct(
		public ExampleSetPeople $people,
		public ExampleSetPrerequisites $prerequisites,
		public ExampleSetAppConfig $appConfig,
		public ExampleSetUserPreferences $userPreferences,
	) {
	}//end __construct()
}//end class

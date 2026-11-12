<?php

/**
 * OpenRegister Example Set Remover Settings
 *
 * The collaborators the remover puts back a set's settings with: app config, user preferences, register imports and organisations.
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
 * What the remover puts back a set's settings, imports and organisations with.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
final readonly class ExampleSetRemoverSettings {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ExampleSetAppConfig       $appConfig       Restores the app config the set wrote.
	 * @param ExampleSetUserPreferences $userPreferences Restores the user preferences the set wrote.
	 * @param ExampleSetRegisters       $registers       Removes the register imports the set made.
	 * @param ExampleSetInstance        $instance        Deletes the OpenRegister organisations the set created.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function __construct(
		public ExampleSetAppConfig $appConfig,
		public ExampleSetUserPreferences $userPreferences,
		public ExampleSetRegisters $registers,
		public ExampleSetInstance $instance,
	) {
	}//end __construct()
}//end class

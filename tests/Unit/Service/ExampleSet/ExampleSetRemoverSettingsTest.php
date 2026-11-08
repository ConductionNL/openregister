<?php

/**
 * Unit test for ExampleSetRemoverSettings: the collaborators it bundles for the remover.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
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

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverSettings;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverSettings
 */
class ExampleSetRemoverSettingsTest extends TestCase {
	/**
	 * Each collaborator is held as given.
	 *
	 * @return void
	 */
	public function testHoldsEachCollaborator(): void {
		$appConfig = $this->createMock(ExampleSetAppConfig::class);
		$userPreferences = $this->createMock(ExampleSetUserPreferences::class);
		$registers = $this->createMock(ExampleSetRegisters::class);
		$instance = $this->createMock(ExampleSetInstance::class);
		$bundle = new ExampleSetRemoverSettings(appConfig: $appConfig, userPreferences: $userPreferences, registers: $registers, instance: $instance);
		$this->assertSame($appConfig, $bundle->appConfig);
		$this->assertSame($userPreferences, $bundle->userPreferences);
		$this->assertSame($registers, $bundle->registers);
		$this->assertSame($instance, $bundle->instance);
	}//end testHoldsEachCollaborator()
}//end class

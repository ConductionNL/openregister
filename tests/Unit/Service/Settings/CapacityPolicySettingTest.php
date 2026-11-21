<?php

/**
 * The capacity policy and officials source settings (REQ-DKP-001): the
 * shipped default, a stored policy, a save that does not name them, and the
 * two officials source shapes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Anonymisation\CapacityPolicy;
use OCA\OpenRegister\Service\Anonymisation\OfficialsSource;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The capacity settings.
 */
#[CoversClass(FileSettingsHandler::class)]
#[CoversClass(OfficialsSource::class)]
#[UsesClass(CapacityPolicy::class)]
class CapacityPolicySettingTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	/**
	 * The stored file settings, as JSON.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * Build the app config over $stored, recording writes into it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->stored = '';
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === 'fileManagement' && $this->stored !== '' ? $this->stored : $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === 'fileManagement') {
					$this->stored = $value;
				}

				return true;
			}
		);
	}//end setUp()

	/**
	 * With nothing stored the policy is the shipped default and there is no officials source.
	 *
	 * @return void
	 */
	public function testTheShippedDefault(): void {
		$handler = new FileSettingsHandler($this->appConfig);

		$this->assertSame(
			[
				'public-office' => ['decision' => 'release', 'ground' => null],
				'professional' => ['decision' => 'withhold', 'ground' => '5.1.2e'],
				'private' => ['decision' => 'withhold', 'ground' => '5.1.2e'],
				'unknown' => ['decision' => 'withhold', 'ground' => '5.1.2e'],
			],
			$handler->capacityPolicy()->rules()
		);
		$this->assertNull($handler->officialsSource());
		$anonymisation = $handler->getFileSettingsOnly()['anonymisation'];
		$this->assertSame(CapacityPolicy::DEFAULT, $anonymisation['capacityPolicy']);
		$this->assertNull($anonymisation['officialsSource']);
	}//end testTheShippedDefault()

	/**
	 * A saved policy and source are read back normalised; an unknown decision keeps the default rule.
	 *
	 * @return void
	 */
	public function testASavedPolicyIsReadBack(): void {
		$handler = new FileSettingsHandler($this->appConfig);

		$handler->updateFileSettingsOnly(
			[
				'anonymisation' => [
					'capacityPolicy' => ['professional' => ['decision' => 'release'], 'private' => ['decision' => 'publish']],
					'officialsSource' => ['type' => 'schema', 'register' => 'gemeente', 'schema' => 'officials'],
				],
			]
		);

		$rules = $handler->capacityPolicy()->rules();
		$this->assertSame(['decision' => 'release', 'ground' => null], $rules['professional']);
		$this->assertSame(['decision' => 'withhold', 'ground' => '5.1.2e'], $rules['private']);
		$this->assertSame(
			['type' => 'schema', 'register' => 'gemeente', 'schema' => 'officials', 'nameProperty' => 'name', 'capacityProperty' => 'capacity'],
			$handler->officialsSource()
		);
		$this->assertSame(0.5, $handler->confidenceThreshold());
	}//end testASavedPolicyIsReadBack()

	/**
	 * A save that names only the threshold keeps the stored policy and source.
	 *
	 * @return void
	 */
	public function testASaveWithoutThePolicyKeepsTheStoredOne(): void {
		$handler = new FileSettingsHandler($this->appConfig);
		$handler->updateFileSettingsOnly(
			[
				'anonymisation' => [
					'capacityPolicy' => ['unknown' => ['decision' => 'withhold', 'ground' => '5.1.2d']],
					'officialsSource' => ['type' => 'groups', 'groups' => ['wethouders' => 'public-office']],
				],
			]
		);

		$handler->updateFileSettingsOnly(['anonymisation' => ['confidenceThreshold' => 0.8]]);
		$handler->updateFileSettingsOnly(['batchSize' => 20]);

		$this->assertSame(['decision' => 'withhold', 'ground' => '5.1.2d'], $handler->capacityPolicy()->ruleFor(capacity: 'unknown'));
		$this->assertSame(['type' => 'groups', 'groups' => ['wethouders' => 'public-office']], $handler->officialsSource());
		$this->assertSame(0.8, $handler->confidenceThreshold());
	}//end testASaveWithoutThePolicyKeepsTheStoredOne()

	/**
	 * Only the two source shapes are kept: a schema needs a register and a schema, a group needs a known capacity.
	 *
	 * @return void
	 */
	public function testOnlyTheTwoSourceShapesAreKept(): void {
		$this->assertNull(OfficialsSource::normalise(value: null));
		$this->assertNull(OfficialsSource::normalise(value: ['type' => 'ldap']));
		$this->assertNull(OfficialsSource::normalise(value: ['type' => 'schema', 'register' => 'gemeente']));
		$this->assertSame(
			['type' => 'schema', 'register' => 'r', 'schema' => 's', 'nameProperty' => 'fullName', 'capacityProperty' => 'role'],
			OfficialsSource::normalise(value: ['type' => 'schema', 'register' => ' r ', 'schema' => 's', 'nameProperty' => 'fullName', 'capacityProperty' => 'role'])
		);
		$this->assertNull(OfficialsSource::normalise(value: ['type' => 'groups', 'groups' => ['raad' => 'alderman', '' => 'private']]));
		$this->assertSame(
			['type' => 'groups', 'groups' => ['raad' => 'public-office', 'ambtenaren' => 'professional']],
			OfficialsSource::normalise(value: ['type' => 'groups', 'groups' => ['raad' => 'public-office', 'ambtenaren' => 'professional', 'x' => 'boss']])
		);
	}//end testOnlyTheTwoSourceShapesAreKept()
}//end class

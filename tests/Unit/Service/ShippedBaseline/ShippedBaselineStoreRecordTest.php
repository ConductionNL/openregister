<?php

/**
 * ShippedBaselineStoreRecordTest — what recording a shipped baseline writes.
 *
 * The baseline is stored at the subject layer under the subject reference,
 * with the definition, app and app version, and no deployment or actor: it
 * is the app's word, not a person's change. A failing write is swallowed and
 * reported as not recorded, because an upgrade must not fail on it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore
 * @uses \OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore
 * @uses \OCA\OpenRegister\Db\ConfigurationValue
 * @uses \OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress
 * @uses \OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationWrite
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition
 */
final class ShippedBaselineStoreRecordTest extends TestCase {

	/**
	 * A new baseline is created at the subject address with the app's facts.
	 *
	 * @return void
	 */
	public function testABaselineIsWrittenAtTheSubjectAddress(): void {
		$created = [];
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(null);
		$mapper->expects($this->once())->method('createFromArray')->willReturnCallback(
			function (array $row) use (&$created) {
				$created = $row;
				return new \OCA\OpenRegister\Db\ConfigurationValue();
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueString');

		$store = new ShippedBaselineStore(
			new ConfigurationValueStore($mapper, $appConfig, 'openregister'),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertTrue($store->record('schema:zaak', new ShippedDefinition(['title' => 'Zaak'], 'zaakafhandelapp', '1.2.3')));

		$this->assertSame(ConfigurationLayer::SUBJECT, $created['layer']);
		$this->assertSame('schema:zaak', $created['layerRef']);
		$this->assertSame(ShippedBaselineStore::KEY_SCHEMA, $created['configKey']);
		$this->assertNull($created['deploymentUuid']);
		$this->assertNull($created['updatedBy']);
		$value = $created['configValue']['value'];
		$this->assertSame(['definition', 'app', 'appVersion', 'recordedAt'], array_keys($value));
		$this->assertSame(['title' => 'Zaak'], $value['definition']);
		$this->assertSame('zaakafhandelapp', $value['app']);
		$this->assertSame('1.2.3', $value['appVersion']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value['recordedAt']);
	}//end testABaselineIsWrittenAtTheSubjectAddress()

	/**
	 * The config key names which baseline is kept.
	 *
	 * @return void
	 */
	public function testTheConfigKeyNamesTheBaseline(): void {
		$created = [];
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(null);
		$mapper->method('createFromArray')->willReturnCallback(
			function (array $row) use (&$created) {
				$created = $row;
				return new \OCA\OpenRegister\Db\ConfigurationValue();
			}
		);

		$store = new ShippedBaselineStore(
			new ConfigurationValueStore($mapper, $this->createMock(IAppConfig::class), 'openregister'),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertTrue($store->recordAt(new ConfigurationAddress(ConfigurationLayer::SUBJECT, 'schema:zaak', 'shipped.other'), new ShippedDefinition([], 'app', '1')));
		$this->assertSame('shipped.other', $created['configKey']);
	}//end testTheConfigKeyNamesTheBaseline()

	/**
	 * A failing write is logged and reported as not recorded, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsNotRecordedAndDoesNotThrow(): void {
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willThrowException(new RuntimeException('db gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('baseline not recorded for schema:zaak: db gone'));

		$store = new ShippedBaselineStore(
			new ConfigurationValueStore($mapper, $this->createMock(IAppConfig::class), 'openregister'),
			$logger
		);

		$this->assertFalse($store->record('schema:zaak', new ShippedDefinition([], 'app', '1')));
	}//end testAFailingWriteIsNotRecordedAndDoesNotThrow()
}//end class

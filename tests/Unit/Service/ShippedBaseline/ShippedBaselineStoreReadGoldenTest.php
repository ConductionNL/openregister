<?php

/**
 * Golden test for ShippedBaselineStore::read().
 *
 * Pins the four answers: no baseline (null), an unreadable one (null, warned),
 * a malformed one (null) and a recorded one (the whole baseline), and that the
 * read is made at the subject address.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every answer of a baseline read, pinned.
 */
final class ShippedBaselineStoreReadGoldenTest extends TestCase {

	/**
	 * Warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A store whose value store answers this snapshot (or throws), asserting the address read.
	 *
	 * @param ConfigurationSnapshot|RuntimeException $answer The snapshot, or the failure.
	 *
	 * @return ShippedBaselineStore The store.
	 */
	private function store(ConfigurationSnapshot|RuntimeException $answer): ShippedBaselineStore {
		$values = $this->createMock(ConfigurationValueStore::class);
		$read = $values->expects($this->once())->method('read')->with(ConfigurationLayer::SUBJECT, 'schema:zaak', 'baseline-key');
		if ($answer instanceof RuntimeException) {
			$read->willThrowException($answer);
		} else {
			$read->willReturn($answer);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});

		return new ShippedBaselineStore($values, $logger);
	}//end store()

	/**
	 * A present snapshot holding a value.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return ConfigurationSnapshot The snapshot.
	 */
	private static function holding(mixed $value): ConfigurationSnapshot {
		return new ConfigurationSnapshot(
			address: new ConfigurationAddress(layer: ConfigurationLayer::SUBJECT, layerRef: 'schema:zaak', configKey: 'baseline-key'),
			value: $value,
			present: true
		);
	}//end holding()

	public function testARecordedBaselineIsReadWhole(): void {
		$this->assertSame(
			['definition' => ['title' => 'Zaak'], 'app' => 'zaakafhandelapp', 'appVersion' => '1.2.3', 'recordedAt' => ''],
			$this->store(self::holding(['definition' => ['title' => 'Zaak'], 'app' => 'zaakafhandelapp', 'appVersion' => '1.2.3', 'extra' => 1]))
				->read(subject: 'schema:zaak', configKey: 'baseline-key')
		);
		$this->assertSame([], $this->warnings);
	}//end testARecordedBaselineIsReadWhole()

	public function testAbsentMalformedAndUnreadableBaselinesAreNone(): void {
		$absent = ConfigurationSnapshot::absent(address: new ConfigurationAddress(layer: ConfigurationLayer::SUBJECT, layerRef: 'schema:zaak', configKey: 'baseline-key'));
		$this->assertNull($this->store($absent)->read(subject: 'schema:zaak', configKey: 'baseline-key'));
		$this->assertNull($this->store(self::holding('text'))->read(subject: 'schema:zaak', configKey: 'baseline-key'));
		$this->assertNull($this->store(self::holding(['definition' => 'x']))->read(subject: 'schema:zaak', configKey: 'baseline-key'));
		$this->assertSame([], $this->warnings);

		$this->assertNull($this->store(new RuntimeException('db gone'))->read(subject: 'schema:zaak', configKey: 'baseline-key'));
		$this->assertSame(['[ShippedBaselineStore] baseline unreadable for schema:zaak: db gone'], $this->warnings);
	}//end testAbsentMalformedAndUnreadableBaselinesAreNone()
}//end class

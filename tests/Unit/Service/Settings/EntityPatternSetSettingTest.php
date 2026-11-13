<?php

/**
 * The entityPatternSets file setting: its phone-region default, its validation,
 * and how a save and a read through FileSettingsHandler treat it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\UnknownPatternSetException;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\JurisdictionPatternSets;
use OCA\OpenRegister\Service\Settings\EntityPatternSetSetting;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityPatternSetSetting::class)]
#[CoversClass(UnknownPatternSetException::class)]
#[UsesClass(JurisdictionPatternSets::class)]
#[UsesClass(FileSettingsHandler::class)]
final class EntityPatternSetSettingTest extends TestCase {

	private function setting(string $region): EntityPatternSetSetting {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('default_phone_region', '')->willReturn($region);

		return new EntityPatternSetSetting($config);
	}

	/**
	 * A handler over a stored fileManagement value, recording what it stores.
	 *
	 * @param array<string, mixed>|null $stored
	 * @param array<int, string>        $written
	 */
	private function handler(?array $stored, string $region, array &$written): FileSettingsHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $key === 'fileManagement' && $stored !== null ? (string) json_encode($stored) : ''
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				$written[] = $value;
				return true;
			}
		);

		return new FileSettingsHandler($appConfig, null, $this->setting($region));
	}

	public function testANetherlandsInstanceDefaultsToTheDutchSet(): void {
		$this->assertSame(['nl'], $this->setting('NL')->defaultCodes());
		$this->assertSame(['nl'], $this->setting('nl')->read([]));
	}

	public function testAnotherRegionDefaultsToNoSet(): void {
		$this->assertSame([], $this->setting('BE')->defaultCodes());
		$this->assertSame([], $this->setting('')->defaultCodes());
		$this->assertSame([], (new EntityPatternSetSetting())->defaultCodes(), 'no system config, no default set');
	}

	public function testASavedListIsReadAsSavedEvenWhenEmpty(): void {
		$this->assertSame([], $this->setting('NL')->read(['entityPatternSets' => []]));
		$this->assertSame(['nl'], $this->setting('BE')->read(['entityPatternSets' => ['nl']]));
	}

	public function testAnUnknownCodeIsRefusedNamingIt(): void {
		$this->expectException(UnknownPatternSetException::class);
		$this->expectExceptionMessage('Unknown pattern set: xx');

		$this->setting('NL')->submitted(['nl', 'xx']);
	}

	public function testANonListIsRefused(): void {
		$this->expectException(UnknownPatternSetException::class);

		$this->setting('NL')->submitted('nl');
	}

	public function testASubmittedListIsNormalised(): void {
		$this->assertSame(['nl'], $this->setting('BE')->submitted([' NL ', 'nl']));
	}

	public function testTheHandlerReadsTheRegionDefaultUntilAListIsSaved(): void {
		$written = [];

		$this->assertSame(['nl'], $this->handler(null, 'NL', $written)->getFileSettingsOnly()['entityPatternSets']);
		$this->assertSame(['nl'], $this->handler(['chunkSize' => 5], 'NL', $written)->getFileSettingsOnly()['entityPatternSets']);
		$this->assertSame([], $this->handler(['entityPatternSets' => []], 'NL', $written)->getFileSettingsOnly()['entityPatternSets']);
		$this->assertSame([], $this->handler(null, 'BE', $written)->getFileSettingsOnly()['entityPatternSets']);
	}

	public function testTheHandlerStoresASubmittedListAndKeepsASavedOne(): void {
		$written = [];
		$this->handler(null, 'NL', $written)->updateFileSettingsOnly(['entityPatternSets' => []]);
		$this->assertSame([], json_decode($written[0], true)['entityPatternSets']);

		$written = [];
		$this->handler(['entityPatternSets' => []], 'NL', $written)->updateFileSettingsOnly(['chunkSize' => 9]);
		$this->assertSame([], json_decode($written[0], true)['entityPatternSets'], 'a save without the key keeps the saved list');

		$written = [];
		$this->handler(null, 'NL', $written)->updateFileSettingsOnly(['chunkSize' => 9]);
		$this->assertArrayNotHasKey('entityPatternSets', json_decode($written[0], true), 'never saved: it keeps following the region');
	}

	public function testTheHandlerRefusesAnUnknownCodeWithoutStoringAnything(): void {
		$written = [];
		$handler = $this->handler(null, 'NL', $written);

		try {
			$handler->updateFileSettingsOnly(['entityPatternSets' => ['xx']]);
			$this->fail('An unknown code was stored.');
		} catch (UnknownPatternSetException $e) {
			$this->assertStringContainsString('xx', $e->getMessage());
		}

		$this->assertSame([], $written);
	}
}

<?php

/**
 * Tests for the OpenRegister activity settings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Activity
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Activity;

use OCA\OpenRegister\Activity\Setting\ObjectSetting;
use OCA\OpenRegister\Activity\Setting\RegisterSetting;
use OCA\OpenRegister\Activity\Setting\SchemaSetting;
use OCP\Activity\ActivitySettings;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Each activity setting answers its identifier, name, group, priority and stream/mail defaults.
 *
 * @covers \OCA\OpenRegister\Activity\Setting\OpenRegisterActivitySetting
 * @covers \OCA\OpenRegister\Activity\Setting\ObjectSetting
 * @covers \OCA\OpenRegister\Activity\Setting\RegisterSetting
 * @covers \OCA\OpenRegister\Activity\Setting\SchemaSetting
 */
class ActivitySettingsTest extends TestCase {

	/**
	 * The three settings and what each must answer.
	 *
	 * @return array<string, array{0: class-string, 1: array<int, mixed>}>
	 */
	public static function settings(): array {
		$tail = ['openregister', 't:Open Register'];
		$flags = [true, true, true, false];
		return [
			'object' => [ObjectSetting::class, ['openregister_objects', 't:Object changes', ...$tail, 51, ...$flags]],
			'register' => [RegisterSetting::class, ['openregister_registers', 't:Register changes', ...$tail, 52, ...$flags]],
			'schema' => [SchemaSetting::class, ['openregister_schemas', 't:Schema changes', ...$tail, 53, ...$flags]],
		];
	}

	/**
	 * The setting answers as recorded.
	 *
	 * @dataProvider settings
	 *
	 * @param class-string $class The setting class.
	 * @param array<int, mixed> $expected Identifier, name, group id, group name, priority and the four flags.
	 *
	 * @return void
	 */
	public function testTheSettingAnswersAsRecorded(string $class, array $expected): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => 't:' . $text);
		$setting = new $class($l);
		$this->assertInstanceOf(ActivitySettings::class, $setting);
		$this->assertSame($expected, [
			$setting->getIdentifier(), $setting->getName(), $setting->getGroupIdentifier(), $setting->getGroupName(),
			$setting->getPriority(), $setting->canChangeStream(), $setting->isDefaultEnabledStream(),
			$setting->canChangeMail(), $setting->isDefaultEnabledMail(),
		]);
	}
}

<?php

/**
 * Unit test for PreferencesUserValues.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\PreferenceSlot;
use OCA\OpenRegister\Controller\Support\PreferencesUserValues;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A user preference resolves to a sanitised per-user slot that reads, writes and clears.
 *
 * @covers \OCA\OpenRegister\Controller\Support\PreferencesUserValues
 * @uses \OCA\OpenRegister\Controller\Support\PreferenceSlot
 */
class PreferencesUserValuesTest extends TestCase {
	/**
	 * The config under test or its double.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * The values under test or its double.
	 *
	 * @var PreferencesUserValues
	 */
	private PreferencesUserValues $values;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->values = new PreferencesUserValues(config: $this->config, appName: 'openregister');
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	public function testNoUserIsA401(): void {
		$result = $this->values->slotFor(null, 'theme');

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(401, $result->getStatus());
	}

	public function testAKeyWithNothingSafeLeftIsA400(): void {
		$result = $this->values->slotFor($this->user('ann'), '!!@@ __');

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(400, $result->getStatus());
	}

	public function testTheKeyIsLowercasedStrippedAndPrefixed(): void {
		$slot = $this->values->slotFor($this->user('ann'), 'Table_Columns-V2!');

		$this->assertInstanceOf(PreferenceSlot::class, $slot);
		$this->assertSame('ann', $slot->userId);
		$this->assertSame('pref_tablecolumns-v2', $slot->storedKey);
	}

	public function testTheKeyIsCutAt64Characters(): void {
		$slot = $this->values->slotFor($this->user('ann'), str_repeat('a', 100));

		$this->assertSame('pref_' . str_repeat('a', 64), $slot->storedKey);
	}

	public function testReadReturnsTheStoredValueOrNullWhenEmpty(): void {
		$slot = new PreferenceSlot(userId: 'ann', storedKey: 'pref_k');
		$this->config->method('getUserValue')->willReturnMap(
			[['ann', 'openregister', 'pref_k', '', 'dark']]
		);

		$this->assertSame('dark', $this->values->read($slot));
		$this->assertNull($this->values->read(new PreferenceSlot(userId: 'ann', storedKey: 'pref_other')));
	}

	public function testWritingAValueStoresIt(): void {
		$slot = new PreferenceSlot(userId: 'ann', storedKey: 'pref_k');
		$this->config->expects($this->once())->method('setUserValue')->with('ann', 'openregister', 'pref_k', 'v1');
		$this->config->expects($this->never())->method('deleteUserValue');

		$this->assertSame('v1', $this->values->write($slot, 'v1'));
	}

	public function testWritingTheEmptyStringDeletesTheValue(): void {
		$slot = new PreferenceSlot(userId: 'ann', storedKey: 'pref_k');
		$this->config->expects($this->once())->method('deleteUserValue')->with('ann', 'openregister', 'pref_k');
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertNull($this->values->write($slot, ''));
	}
}

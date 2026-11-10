<?php

/**
 * Behaviour tests for ExampleSetConfigShape: which app-config values and user
 * preferences a fragment may write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigShape;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the app-config and user-preference rows a fragment may declare.
 */
class ExampleSetConfigShapeBehaviourTest extends TestCase {

	private static function shape(): ExampleSetConfigShape {
		return new ExampleSetConfigShape(app: 'alpha', file: 'alpha/demo.json');
	}//end shape()

	private function expectRefusal(string $message): void {
		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage($message);
	}//end expectRefusal()

	public function testAPlainStringConfigIsShapedWithDefaults(): void {
		$this->assertSame(
			['key' => 'theme', 'value' => 'dark', 'generate' => false, 'type' => 'string', 'atLeast' => false],
			self::shape()->appConfig(0, ['key' => 'theme', 'value' => 'dark', 'app' => 'alpha'])
		);
	}

	public function testAGeneratedValueNeedsNoValue(): void {
		$this->assertSame(
			['key' => 'secret', 'value' => '', 'generate' => true, 'type' => 'int', 'atLeast' => false],
			self::shape()->appConfig(0, ['key' => 'secret', 'generate' => true, 'type' => 'int'])
		);
	}

	public function testAnIntFloorIsAccepted(): void {
		$shaped = self::shape()->appConfig(0, ['key' => 'max', 'value' => '-5', 'type' => 'int', 'atLeast' => true]);

		$this->assertTrue($shaped['atLeast']);
		$this->assertSame('int', $shaped['type']);
	}

	public function testAnIntMayBeAPlaceholder(): void {
		$this->assertSame('int', self::shape()->appConfig(0, ['key' => 'n', 'value' => '{{cast:sanne.age}}', 'type' => 'int'])['type']);
	}

	public function testAnotherAppsConfigIsRefused(): void {
		$this->expectRefusal('alpha/demo.json: app config 3 is for "beta"; a fragment sets only its own app\'s config.');

		self::shape()->appConfig(3, ['app' => 'beta', 'key' => 'k', 'value' => 'v']);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function unwritable(): array {
		return [
			'no key' => [['value' => 'v']],
			'empty key' => [['key' => '', 'value' => 'v']],
			'no value' => [['key' => 'k']],
			'array value' => [['key' => 'k', 'value' => ['v']]],
			'generate not literally true' => [['key' => 'k', 'generate' => 'yes']],
		];
	}//end unwritable()

	/**
	 * @dataProvider unwritable
	 */
	public function testAConfigNeedsAKeyAndAValueOrGenerate(array $entry): void {
		$this->expectRefusal('alpha/demo.json: app config 0 needs a key and a value, or generate: true.');

		self::shape()->appConfig(0, $entry);
	}

	public function testAnUnsupportedTypeIsRefused(): void {
		$this->expectRefusal('alpha/demo.json: app config 0 has type "bool"; a set writes string or int.');

		self::shape()->appConfig(0, ['key' => 'k', 'value' => '1', 'type' => 'bool']);
	}

	public function testANonIntegerValueOfAnIntIsRefused(): void {
		$this->expectRefusal('alpha/demo.json: app config 0 ("k") is an integer; its value is not.');

		self::shape()->appConfig(0, ['key' => 'k', 'value' => '1.5', 'type' => 'int']);
	}

	public function testAFloorOnAStringIsRefused(): void {
		$this->expectRefusal('alpha/demo.json: app config 0 ("k") is atLeast; only a written int can be a floor.');

		self::shape()->appConfig(0, ['key' => 'k', 'value' => '1', 'atLeast' => true]);
	}

	public function testAFloorOnAGeneratedIntIsRefused(): void {
		$this->expectRefusal('is atLeast; only a written int can be a floor.');

		self::shape()->appConfig(0, ['key' => 'k', 'generate' => true, 'type' => 'int', 'atLeast' => true]);
	}

	public function testAUserPreferenceIsShapedWithAStringValue(): void {
		$this->assertSame(
			['user' => 'sanne', 'key' => 'pageSize', 'value' => '25'],
			self::shape()->userPreference(0, ['user' => 'sanne', 'key' => 'pageSize', 'value' => 25])
		);
	}

	public function testAnotherAppsPreferenceIsRefused(): void {
		$this->expectRefusal('alpha/demo.json: user preference 1 is for "beta"; a fragment sets only its own app\'s preferences.');

		self::shape()->userPreference(1, ['app' => 'beta', 'user' => 'u', 'key' => 'k', 'value' => 'v']);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function incompletePreferences(): array {
		return [
			'no user' => [['key' => 'k', 'value' => 'v']],
			'empty user' => [['user' => '', 'key' => 'k', 'value' => 'v']],
			'no key' => [['user' => 'u', 'value' => 'v']],
			'empty key' => [['user' => 'u', 'key' => '', 'value' => 'v']],
			'no value' => [['user' => 'u', 'key' => 'k']],
		];
	}//end incompletePreferences()

	/**
	 * @dataProvider incompletePreferences
	 */
	public function testAPreferenceNeedsAUserAKeyAndAValue(array $entry): void {
		$this->expectRefusal('alpha/demo.json: user preference 0 needs a user, a key and a value.');

		self::shape()->userPreference(0, $entry);
	}
}//end class

<?php

/**
 * FeatureToggleService — the merge, the refusal, the coercion and the fail mode.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Exception\FeatureToggleRefusedException;
use OCA\OpenRegister\AppHost\Service\FeatureToggleService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies that a declared toggle merges, an undeclared one is refused, and a
 * stored "false" reads as false.
 */
class FeatureToggleServiceTest extends TestCase {

	/**
	 * The toggles an app declares in these tests.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const DECLARED = [
		['key' => 'ai-summary', 'label' => 'AI summary', 'default' => true],
		['key' => 'beta-search', 'label' => 'Beta search', 'default' => false],
	];

	/**
	 * A stand-in app config holding one string per key.
	 *
	 * @param array<string, string> $stored The initial contents.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfig(array $stored = []): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			}
		);

		return $config;
	}//end appConfig()

	/**
	 * A service with no auditor in the container.
	 *
	 * @param IAppConfig            $config The config double.
	 * @param ContainerInterface|null $container An optional container.
	 *
	 * @return FeatureToggleService The service.
	 */
	private function service(IAppConfig $config, ?ContainerInterface $container = null): FeatureToggleService {
		if ($container === null) {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willThrowException(new \RuntimeException('no auditor here'));
		}

		return new FeatureToggleService(
			appConfig: $config,
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * With nothing stored, the declared defaults are what the app sees.
	 *
	 * @return void
	 */
	public function testDeclaredDefaultsApplyWhenNothingIsStored(): void {
		$service = $this->service(config: $this->appConfig());

		$merged = $service->merged(app: 'dossiq', declarations: self::DECLARED);

		$this->assertSame(['ai-summary' => true, 'beta-search' => false], $merged);
	}//end testDeclaredDefaultsApplyWhenNothingIsStored()

	/**
	 * The scenario the spec names: an administrator switches a feature off.
	 *
	 * @return void
	 */
	public function testAnAdministratorSwitchesAFeatureOff(): void {
		$service = $this->service(config: $this->appConfig());

		$after = $service->update(app: 'dossiq', declarations: self::DECLARED, overrides: ['ai-summary' => false]);

		$this->assertFalse($after['ai-summary'], 'the toggle the administrator switched off must read off');
		$this->assertFalse(
			$service->isEnabled(app: 'dossiq', key: 'ai-summary', declarations: self::DECLARED),
			'and the PHP reader must agree with the map the surface shows'
		);
		$this->assertFalse($after['beta-search'], 'the other toggle keeps its declared default');
	}//end testAnAdministratorSwitchesAFeatureOff()

	/**
	 * The second scenario: an undeclared key is refused, and named.
	 *
	 * @return void
	 */
	public function testAnUndeclaredKeyIsRefusedAndNamed(): void {
		$service = $this->service(config: $this->appConfig());

		try {
			$service->update(app: 'dossiq', declarations: self::DECLARED, overrides: ['unknown' => true]);
			$this->fail('an undeclared toggle must be refused');
		} catch (FeatureToggleRefusedException $e) {
			$this->assertSame(422, $e->getCode(), 'the refusal is a 422, not a 400');
			$this->assertStringContainsString('unknown', $e->getMessage(), 'the refusal names the key');
		}
	}//end testAnUndeclaredKeyIsRefusedAndNamed()

	/**
	 * A typo in one key refuses the whole write, so no half of it lands.
	 *
	 * @return void
	 */
	public function testOneUndeclaredKeyRefusesTheWholeWrite(): void {
		$config = $this->appConfig();
		$config->expects($this->never())->method('setValueString');
		$service = $this->service(config: $config);

		$this->expectException(FeatureToggleRefusedException::class);
		$service->update(
			app: 'dossiq',
			declarations: self::DECLARED,
			overrides: ['ai-summary' => false, 'ai-summry' => false]
		);
	}//end testOneUndeclaredKeyRefusesTheWholeWrite()

	/**
	 * 🔴 The control this class exists for: a stored "false" is FALSE.
	 *
	 * `(bool)"false"` is true, and `IAppConfig` hands back strings, so this is
	 * the shape in which a switched-off feature comes back on.
	 *
	 * @return void
	 */
	public function testAStoredStringFalseReadsAsFalse(): void {
		$service = $this->service(
			config: $this->appConfig(
				[FeatureToggleService::OVERRIDE_KEY => '{"ai-summary":"false","beta-search":"0"}']
			)
		);

		$merged = $service->merged(app: 'dossiq', declarations: self::DECLARED);

		$this->assertFalse($merged['ai-summary'], 'the string "false" is off, not on');
		$this->assertFalse($merged['beta-search'], 'and so is the string "0"');
	}//end testAStoredStringFalseReadsAsFalse()

	/**
	 * A stored "true" and a stored "1" are on.
	 *
	 * The mirror of the test above: a coercion that read everything as false
	 * would pass that one and fail this one.
	 *
	 * @return void
	 */
	public function testAStoredStringTrueReadsAsTrue(): void {
		$service = $this->service(
			config: $this->appConfig(
				[FeatureToggleService::OVERRIDE_KEY => '{"beta-search":"true"}']
			)
		);

		$this->assertTrue(
			$service->isEnabled(app: 'dossiq', key: 'beta-search', declarations: self::DECLARED),
			'a toggle stored as the string "true" is on'
		);
	}//end testAStoredStringTrueReadsAsTrue()

	/**
	 * An override for a key nobody declares any more is not in the map.
	 *
	 * @return void
	 */
	public function testAnOverrideForAnUndeclaredKeyIsNotReturned(): void {
		$service = $this->service(
			config: $this->appConfig(
				[FeatureToggleService::OVERRIDE_KEY => '{"retired-thing":true,"ai-summary":false}']
			)
		);

		$merged = $service->merged(app: 'dossiq', declarations: self::DECLARED);

		$this->assertArrayNotHasKey('retired-thing', $merged, 'an undeclared toggle is not a toggle');
		$this->assertFalse($merged['ai-summary'], 'the declared one still honours its override');
	}//end testAnOverrideForAnUndeclaredKeyIsNotReturned()

	/**
	 * Asking about a toggle nobody declared reads false, not true.
	 *
	 * @return void
	 */
	public function testAnUndeclaredToggleIsOff(): void {
		$service = $this->service(config: $this->appConfig());

		$this->assertFalse(
			$service->isEnabled(app: 'dossiq', key: 'never-declared', declarations: self::DECLARED),
			'a question with no answer must not read as an open door'
		);
	}//end testAnUndeclaredToggleIsOff()

	/**
	 * 🔴 An unreadable override map: a fail-closed toggle goes off, a
	 * fail-open one keeps its default (ADR-102).
	 *
	 * @return void
	 */
	public function testAnUnreadableOverrideHonoursTheDeclaredFailMode(): void {
		$declared = [
			['key' => 'guarded', 'default' => true, 'failMode' => FeatureToggleService::FAIL_CLOSED],
			['key' => 'convenience', 'default' => true],
		];
		$service = $this->service(
			config: $this->appConfig([FeatureToggleService::OVERRIDE_KEY => 'not json at all'])
		);

		$merged = $service->merged(app: 'dossiq', declarations: $declared);

		$this->assertFalse($merged['guarded'], 'a toggle guarding a security path must not come back on');
		$this->assertTrue($merged['convenience'], 'and one that is not must not disable itself over the same accident');
	}//end testAnUnreadableOverrideHonoursTheDeclaredFailMode()

	/**
	 * Nothing stored and an unreadable store are different answers.
	 *
	 * @return void
	 */
	public function testAbsentAndUnreadableAreDifferentAnswers(): void {
		$service = $this->service(config: $this->appConfig());

		$this->assertSame([], $service->storedOverrides(app: 'dossiq'), 'nothing stored is an empty map');

		$broken = $this->service(config: $this->appConfig([FeatureToggleService::OVERRIDE_KEY => '["a"']));
		$this->assertNull($broken->storedOverrides(app: 'dossiq'), 'an unparseable map is an unknown one');
	}//end testAbsentAndUnreadableAreDifferentAnswers()

	/**
	 * The per-request memo is dropped on a write, so a reader after an update
	 * does not answer from before it.
	 *
	 * @return void
	 */
	public function testTheCacheIsInvalidatedByAnUpdate(): void {
		$service = $this->service(config: $this->appConfig());

		$this->assertTrue($service->isEnabled(app: 'dossiq', key: 'ai-summary', declarations: self::DECLARED));
		$service->update(app: 'dossiq', declarations: self::DECLARED, overrides: ['ai-summary' => false]);

		$this->assertFalse(
			$service->isEnabled(app: 'dossiq', key: 'ai-summary', declarations: self::DECLARED),
			'a stale memo would answer with the value from before the write'
		);
	}//end testTheCacheIsInvalidatedByAnUpdate()

	/**
	 * A toggle change reaches the settings trail, one row per toggle, named.
	 *
	 * @return void
	 */
	public function testAToggleChangeIsAudited(): void {
		$recorded = [];
		$auditor = new class($recorded) {
			/**
			 * @param array<int, array<string, mixed>> $recorded Collected calls.
			 */
			public function __construct(private array &$recorded) {
			}

			/**
			 * @param string               $app        The app.
			 * @param array<string, mixed> $before     Before.
			 * @param array<string, mixed> $after      After.
			 * @param array<int, string>   $secretKeys Secret keys.
			 *
			 * @return int Rows written.
			 */
			public function recordUpdate(string $app, array $before, array $after, array $secretKeys = []): int {
				$this->recorded[] = ['app' => $app, 'before' => $before, 'after' => $after];
				return count($after);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($auditor);

		$service = $this->service(config: $this->appConfig(), container: $container);
		$service->update(app: 'dossiq', declarations: self::DECLARED, overrides: ['ai-summary' => false]);

		$this->assertCount(1, $recorded, 'the change reaches the auditor');
		$this->assertArrayHasKey(
			'features.ai-summary',
			$recorded[0]['after'],
			'the trail names the toggle, not the JSON blob it lives in'
		);
		$this->assertTrue($recorded[0]['before']['features.ai-summary'], 'before is the value the toggle had');
		$this->assertFalse($recorded[0]['after']['features.ai-summary'], 'after is the value it has now');
	}//end testAToggleChangeIsAudited()

	/**
	 * An auditor that throws does not fail the write: the toggle already moved.
	 *
	 * @return void
	 */
	public function testAFailingAuditorDoesNotFailTheWrite(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('trail is down'));

		$service = $this->service(config: $this->appConfig(), container: $container);
		$after = $service->update(app: 'dossiq', declarations: self::DECLARED, overrides: ['ai-summary' => false]);

		$this->assertFalse($after['ai-summary'], 'the toggle is off and the caller is told so');
	}//end testAFailingAuditorDoesNotFailTheWrite()

	/**
	 * A declaration with no key is not a toggle.
	 *
	 * @return void
	 */
	public function testADeclarationWithoutAKeyIsDropped(): void {
		$service = $this->service(config: $this->appConfig());

		$defaults = $service->defaults(declarations: [['label' => 'nameless'], 'a string', ['key' => 'real']]);

		$this->assertSame(['real' => false], $defaults, 'only a declaration with a key declares a toggle');
	}//end testADeclarationWithoutAKeyIsDropped()
}//end class

<?php

/**
 * Behaviour tests for FeatureToggleService fail modes, auditor shape and the refusal it raises.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Exception\FeatureToggleRefusedException;
use OCA\OpenRegister\AppHost\Service\FeatureToggleService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Fail-mode parsing, an auditor without recordUpdate, and the refusal's identity.
 */
class FeatureToggleServiceBehaviourTest extends TestCase {

	/**
	 * A service over the given doubles.
	 *
	 * @param IAppConfig|null         $config    Config double.
	 * @param ContainerInterface|null $container Container double.
	 * @param LoggerInterface|null    $logger    Logger double.
	 *
	 * @return FeatureToggleService
	 */
	private function service(
		?IAppConfig $config = null,
		?ContainerInterface $container = null,
		?LoggerInterface $logger = null
	): FeatureToggleService {
		return new FeatureToggleService(
			appConfig: ($config ?? $this->createMock(IAppConfig::class)),
			container: ($container ?? $this->createMock(ContainerInterface::class)),
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end service()

	/**
	 * Non-array and keyless declarations are dropped; a closed mode is kept,
	 * anything else reads open.
	 *
	 * @return void
	 */
	public function testFailModesDropMalformedDeclarations(): void {
		$modes = $this->service()->failModes([
			'not a declaration',
			['label' => 'no key', 'failMode' => FeatureToggleService::FAIL_CLOSED],
			['key' => 'strict', 'failMode' => FeatureToggleService::FAIL_CLOSED],
			['key' => 'loose', 'failMode' => 'whatever'],
			['key' => 'plain'],
		]);

		$this->assertSame(
			[
				'strict' => FeatureToggleService::FAIL_CLOSED,
				'loose' => FeatureToggleService::FAIL_OPEN,
				'plain' => FeatureToggleService::FAIL_OPEN,
			],
			$modes
		);
	}//end testFailModesDropMalformedDeclarations()

	/**
	 * An auditor without recordUpdate is skipped silently: the write succeeds
	 * and no "not audited" warning is logged.
	 *
	 * @return void
	 */
	public function testAnAuditorWithoutRecordUpdateIsSkipped(): void {
		$stored = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$key] ?? $default);
			}
		);
		$config->expects($this->once())->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->willReturn(new stdClass());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$after = $this->service($config, $container, $logger)->update(
			'dossiq',
			[['key' => 'ai-summary', 'default' => true]],
			['ai-summary' => false]
		);

		$this->assertSame(['ai-summary' => false], $after);
	}//end testAnAuditorWithoutRecordUpdateIsSkipped()

	/**
	 * The refusal names the app and the undeclared key through its accessors,
	 * and nothing is written.
	 *
	 * @return void
	 */
	public function testTheRefusalCarriesAppAndKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setValueString');

		try {
			$this->service($config)->update('dossiq', [['key' => 'ai-summary']], ['ai-sumary' => true]);
			$this->fail('an undeclared key must be refused');
		} catch (FeatureToggleRefusedException $e) {
			$this->assertSame('dossiq', $e->getAppId());
			$this->assertSame('ai-sumary', $e->getKey());
			$this->assertSame(
				'[AppHost:dossiq] feature toggle "ai-sumary" is not declared by this app, so it cannot be set.',
				$e->getMessage()
			);
		}
	}//end testTheRefusalCarriesAppAndKey()
}//end class

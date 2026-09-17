<?php

/**
 * Unit tests for the effective-configuration explainer.
 *
 * REQ-CAD-003's two scenarios: a value overridden at register level names the
 * value, the register layer and the deployment that set it; and a value never
 * moved by a deployment is named honestly as predating the first deployment
 * rather than reported as unknown.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

final class ConfigurationExplainerTest extends TestCase {

	/**
	 * The explainer over a staged per-layer reading.
	 *
	 * @param array<string, ConfigurationSnapshot> $byLayer    What each layer answers.
	 * @param ConfigurationDeployment|null         $deployment The deployment the history holds, or null to miss.
	 *
	 * @return ConfigurationExplainer The explainer.
	 */
	private function explainer(array $byLayer, ?ConfigurationDeployment $deployment = null): ConfigurationExplainer {
		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			static function (string $layer, ?string $ref, string $key) use ($byLayer): ConfigurationSnapshot {
				return ($byLayer[$layer] ?? ConfigurationSnapshot::absent($layer, $ref, $key));
			}
		);

		$mapper = $this->createMock(ConfigurationDeploymentMapper::class);
		if ($deployment === null) {
			$mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		} else {
			$mapper->method('findByUuid')->willReturn($deployment);
		}

		return new ConfigurationExplainer($store, $mapper);
	}//end explainer()

	private function deployment(string $uuid, string $name): ConfigurationDeployment {
		$deployment = new ConfigurationDeployment();
		$deployment->setUuid($uuid);
		$deployment->setName($name);
		$deployment->setDeployedBy('admin');
		$deployment->setDeployedAt(new DateTime('2026-09-15T10:00:00+00:00'));

		return $deployment;
	}//end deployment()

	public function testWhyDoesThisInstanceBehaveLikeThis(): void {
		$explainer = $this->explainer(
			[
				ConfigurationLayer::INSTANCE => new ConfigurationSnapshot(
					ConfigurationLayer::INSTANCE,
					null,
					'integration.mail',
					['relay' => 'instance-smtp'],
					true,
					'dep-instance'
				),
				ConfigurationLayer::REGISTER => new ConfigurationSnapshot(
					ConfigurationLayer::REGISTER,
					'zaken',
					'integration.mail',
					['relay' => 'zaken-smtp'],
					true,
					'dep-register'
				),
			],
			$this->deployment('dep-register', 'zaken mail relay')
		);

		$answer = $explainer->explain('integration.mail', 'zaken');

		$this->assertTrue($answer['found']);
		$this->assertSame(['relay' => 'zaken-smtp'], $answer['value']);
		$this->assertSame(ConfigurationLayer::REGISTER, $answer['layer']);
		$this->assertSame('zaken', $answer['layerRef']);
		$this->assertSame('dep-register', $answer['deployment']['uuid']);
		$this->assertSame('zaken mail relay', $answer['deployment']['name']);
		$this->assertFalse($answer['predatesFirstDeployment']);
	}//end testWhyDoesThisInstanceBehaveLikeThis()

	public function testAnOlderValueIsNamedHonestly(): void {
		$explainer = $this->explainer(
			[
				ConfigurationLayer::INSTANCE => new ConfigurationSnapshot(
					ConfigurationLayer::INSTANCE,
					null,
					'rbac',
					['enabled' => true],
					true,
					// Never moved by a deployment.
					null
				),
			]
		);

		$answer = $explainer->explain('rbac');

		$this->assertTrue($answer['found']);
		$this->assertSame(['enabled' => true], $answer['value']);
		$this->assertTrue($answer['predatesFirstDeployment']);
		$this->assertNull($answer['deployment']);
		$this->assertStringContainsString('predates the first deployment', $answer['explanation']);
		// Not the same thing as unknown, and the explanation must not say so.
		$this->assertStringNotContainsString('unknown', $answer['explanation']);
	}//end testAnOlderValueIsNamedHonestly()

	public function testTheHighestLayerHoldingAValueWins(): void {
		$byLayer = [
			ConfigurationLayer::INSTANCE => new ConfigurationSnapshot(
				ConfigurationLayer::INSTANCE, null, 'lifecycle.stage', 'instance', true, null
			),
			ConfigurationLayer::REGISTER => new ConfigurationSnapshot(
				ConfigurationLayer::REGISTER, 'zaken', 'lifecycle.stage', 'register', true, null
			),
			ConfigurationLayer::BUNDLE => new ConfigurationSnapshot(
				ConfigurationLayer::BUNDLE, 'standaard', 'lifecycle.stage', 'bundle', true, null
			),
			ConfigurationLayer::SUBJECT => new ConfigurationSnapshot(
				ConfigurationLayer::SUBJECT, 'zaak', 'lifecycle.stage', 'subject', true, null
			),
		];

		$answer = $this->explainer($byLayer)->explain('lifecycle.stage', 'zaken', 'standaard', 'zaak');

		$this->assertSame('subject', $answer['value']);
		$this->assertSame(ConfigurationLayer::SUBJECT, $answer['layer']);
		$this->assertCount(4, $answer['chain']);
	}//end testTheHighestLayerHoldingAValueWins()

	public function testALayerTheCallerDidNotNameIsLeftOutOfTheChain(): void {
		$byLayer = [
			ConfigurationLayer::INSTANCE => new ConfigurationSnapshot(
				ConfigurationLayer::INSTANCE, null, 'lifecycle.stage', 'instance', true, null
			),
		];

		$answer = $this->explainer($byLayer)->explain('lifecycle.stage');

		// Only the instance: reading the register layer with a null reference
		// would report the instance value as the register's.
		$this->assertCount(1, $answer['chain']);
		$this->assertSame(ConfigurationLayer::INSTANCE, $answer['layer']);
	}//end testALayerTheCallerDidNotNameIsLeftOutOfTheChain()

	public function testASettingNoLayerHoldsIsReportedAsNotFound(): void {
		$answer = $this->explainer([])->explain('integration.mail', 'zaken');

		$this->assertFalse($answer['found']);
		$this->assertNull($answer['value']);
		$this->assertNull($answer['layer']);
		$this->assertFalse($answer['predatesFirstDeployment']);
	}//end testASettingNoLayerHoldsIsReportedAsNotFound()

	public function testAProvenanceNamingADeploymentTheHistoryLostSaysSo(): void {
		$explainer = $this->explainer(
			[
				ConfigurationLayer::INSTANCE => new ConfigurationSnapshot(
					ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => true], true, 'dep-gone'
				),
			]
		);

		$answer = $explainer->explain('rbac');

		// Answering null here would read as "never deployed", which is a
		// different and wrong conclusion.
		$this->assertTrue($answer['deployment']['missing']);
		$this->assertSame('dep-gone', $answer['deployment']['uuid']);
		$this->assertFalse($answer['predatesFirstDeployment']);
	}//end testAProvenanceNamingADeploymentTheHistoryLostSaysSo()

	public function testTheLayerOrderIsInstanceRegisterBundleSubject(): void {
		$this->assertSame(
			['instance', 'register', 'bundle', 'subject'],
			ConfigurationLayer::ORDER
		);
		$this->assertLessThan(
			ConfigurationLayer::rank(ConfigurationLayer::SUBJECT),
			ConfigurationLayer::rank(ConfigurationLayer::BUNDLE)
		);
		$this->assertFalse(ConfigurationLayer::requiresReference(ConfigurationLayer::INSTANCE));
		$this->assertTrue(ConfigurationLayer::requiresReference(ConfigurationLayer::REGISTER));
		$this->assertSame(-1, ConfigurationLayer::rank('tenant'));
	}//end testTheLayerOrderIsInstanceRegisterBundleSubject()
}//end class

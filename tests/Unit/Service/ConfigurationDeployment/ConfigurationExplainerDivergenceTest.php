<?php

/**
 * The divergence from the shipped baseline is reported beside the layers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationScope;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\ShippedDivergenceSourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * The explainer's `divergence` block.
 *
 * @covers \OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer
 * @uses \OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress
 * @uses \OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationScope
 * @uses \OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot
 * @uses \OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer
 */
final class ConfigurationExplainerDivergenceTest extends TestCase {

	/**
	 * The subjects the divergence source was asked about.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * The explainer over an empty store and a fake divergence source.
	 *
	 * @param bool $withSource Whether a divergence source is wired.
	 *
	 * @return ConfigurationExplainer The explainer.
	 */
	private function explainer(bool $withSource = true): ConfigurationExplainer {
		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			static fn (string $layer, ?string $ref, string $key): ConfigurationSnapshot => ConfigurationSnapshot::absent(new ConfigurationAddress($layer, $ref, $key))
		);

		$source = null;
		if ($withSource === true) {
			$source = $this->createMock(ShippedDivergenceSourceInterface::class);
			$source->method('forSubject')->willReturnCallback(
				function (string $subject): array {
					$this->asked[] = $subject;
					return ['schema' => 'learner-profile', 'shipped' => true, 'divergences' => [['path' => 'properties.level.title', 'state' => 'local']]];
				}
			);
		}

		return new ConfigurationExplainer(
			store: $store,
			deployments: $this->createMock(ConfigurationDeploymentMapper::class),
			bindings: null,
			divergence: $source
		);
	}//end explainer()

	/**
	 * Explaining a schema's shipped baseline carries the divergence beside the layers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#requirement-the-instance-reports-where-it-differs-from-what-was-shipped-req-lca-002
	 */
	public function testTheBaselineKeyCarriesTheDivergence(): void {
		$answer = $this->explainer()->explain(
			configKey: 'schema.shippedBaseline',
			scope: new ConfigurationScope(subject: 'schema:learner-profile')
		);

		$this->assertSame(['schema:learner-profile'], $this->asked);
		$this->assertSame('properties.level.title', $answer['divergence']['divergences'][0]['path']);
		$this->assertArrayHasKey('chain', $answer);
		$this->assertSame('schema.shippedBaseline', $answer['key']);
	}//end testTheBaselineKeyCarriesTheDivergence()

	/**
	 * Any other key, a baseline without a subject, or no source leaves the answer as it was.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function testOtherKeysAndScopesAreUnchanged(): void {
		$other = $this->explainer()->explain(configKey: 'object.retention', scope: new ConfigurationScope(subject: 'schema:learner-profile'));
		$noSubject = $this->explainer()->explain(configKey: 'schema.shippedBaseline');
		$noSource = $this->explainer(withSource: false)->explain(configKey: 'schema.shippedBaseline', scope: new ConfigurationScope(subject: 'schema:learner-profile'));

		$this->assertArrayNotHasKey('divergence', $other);
		$this->assertArrayNotHasKey('divergence', $noSubject);
		$this->assertArrayNotHasKey('divergence', $noSource);
		$this->assertSame([], $this->asked);
	}//end testOtherKeysAndScopesAreUnchanged()
}//end class

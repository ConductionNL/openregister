<?php

/**
 * The one-sentence explanation of a setting, pinned word for word.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationProvenance;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationScope;

final class ConfigurationExplainerSentenceTest extends TestCase {

	/**
	 * The explainer over one staged layer.
	 *
	 * @param ConfigurationSnapshot        $snapshot   What that layer answers.
	 * @param ConfigurationDeployment|null $deployment The deployment the history holds, or null to miss.
	 *
	 * @return ConfigurationExplainer The explainer.
	 */
	private function explainer(ConfigurationSnapshot $snapshot, ?ConfigurationDeployment $deployment): ConfigurationExplainer {
		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			static function (string $layer, ?string $ref, string $key) use ($snapshot): ConfigurationSnapshot {
				if ($layer === $snapshot->layer) {
					return $snapshot;
				}

				return ConfigurationSnapshot::absent(new ConfigurationAddress($layer, $ref, $key));
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

	private function snapshot(string $layer, ?string $ref, ?string $deploymentUuid): ConfigurationSnapshot {
		return new ConfigurationSnapshot(new ConfigurationAddress($layer, $ref, 'k'), 'v', true, new ConfigurationProvenance($deploymentUuid));
	}//end snapshot()

	public function testAValueMovedByANamedDeploymentNamesItAndTheLayerReference(): void {
		$deployment = new ConfigurationDeployment();
		$deployment->setUuid('dep-1');
		$deployment->setName('mail relay');

		$answer = $this->explainer($this->snapshot(ConfigurationLayer::REGISTER, 'zaken', 'dep-1'), $deployment)->explain('k', new ConfigurationScope('zaken'));

		$this->assertSame(
			sprintf('"k" is set at the %s layer by zaken, last moved by the deployment "mail relay"', ConfigurationLayer::REGISTER),
			$answer['explanation']
		);
	}//end testAValueMovedByANamedDeploymentNamesItAndTheLayerReference()

	public function testAValueWhoseDeploymentIsGoneIsNamedByItsUuid(): void {
		$answer = $this->explainer($this->snapshot(ConfigurationLayer::INSTANCE, null, 'dep-gone'), null)->explain('k');

		$this->assertSame(
			sprintf('"k" is set at the %s layer, last moved by the deployment "dep-gone"', ConfigurationLayer::INSTANCE),
			$answer['explanation']
		);
	}//end testAValueWhoseDeploymentIsGoneIsNamedByItsUuid()

	public function testAValueOlderThanTheFirstDeploymentSaysSo(): void {
		$answer = $this->explainer($this->snapshot(ConfigurationLayer::INSTANCE, null, null), null)->explain('k');

		$this->assertSame(
			sprintf('"k" is set at the %s layer and predates the first deployment, so no deployment record names it', ConfigurationLayer::INSTANCE),
			$answer['explanation']
		);
	}//end testAValueOlderThanTheFirstDeploymentSaysSo()

	public function testAKeyNoLayerSetsSaysSo(): void {
		$answer = $this->explainer(ConfigurationSnapshot::absent(new ConfigurationAddress(ConfigurationLayer::INSTANCE, null, 'k')), null)->explain('k');

		$this->assertSame('no layer sets "k"', $answer['explanation']);
	}//end testAKeyNoLayerSetsSaysSo()
}//end class

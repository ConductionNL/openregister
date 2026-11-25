<?php

/**
 * Unit tests for the Configuration side of a promotion: export a register, land drafts in a set.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Kernel\Contracts\PromotionRefusedException;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftsInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftTarget;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCA\OpenRegister\Service\ConfigurationDeployment\PromotionLanding;
use OCA\OpenRegister\Service\ConfigurationTransferInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromotionLanding::class)]
#[UsesClass(ConfigurationAddress::class)]
#[UsesClass(ConfigurationDraftTarget::class)]
#[UsesClass(DeploymentRefusedException::class)]
#[UsesClass(PromotionRefusedException::class)]
final class PromotionLandingTest extends TestCase {

	private ConfigurationTransferInterface&MockObject $export;

	private ConfigurationDraftsInterface&MockObject $drafts;

	protected function setUp(): void {
		parent::setUp();
		$this->export = $this->createMock(ConfigurationTransferInterface::class);
		$this->drafts = $this->createMock(ConfigurationDraftsInterface::class);
	}

	private function landing(): PromotionLanding {
		return new PromotionLanding(export: $this->export, drafts: $this->drafts);
	}

	private function set(): ConfigurationDraftSet {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-9');
		$set->setName('Promotion from Dev');
		$set->setState(ConfigurationDraftSet::STATE_OPEN);

		return $set;
	}

	public function testARegisterIsExportedThroughTheConfigurationExport(): void {
		$register = $this->createMock(Register::class);
		$this->export->expects($this->once())->method('exportConfig')->with($register)->willReturn(['openapi' => '3.0.0']);

		$this->assertSame(['openapi' => '3.0.0'], $this->landing()->exportRegister($register));
	}

	public function testARegisterThatIsNotAStoredEntityIsRefusedBeforeTheExport(): void {
		$this->export->expects($this->never())->method('exportConfig');

		$this->expectException(PromotionRefusedException::class);
		$this->landing()->exportRegister($this->createMock(RegisterInterface::class));
	}

	public function testEveryDraftLandsInTheOpenedSetAtItsAddress(): void {
		$this->drafts->expects($this->once())->method('openSet')->with('Promotion from Dev', 'why')->willReturn($this->set());
		$seen = [];
		$this->drafts->expects($this->exactly(2))->method('draftValue')->willReturnCallback(
			static function (ConfigurationDraftTarget $target, mixed $value) use (&$seen): ConfigurationDraft {
				$seen[] = [$target->setUuid, $target->address->layer, $target->address->layerRef, $target->address->configKey, $value];
				return new ConfigurationDraft();
			}
		);

		$set = $this->landing()->land(
			'Promotion from Dev',
			'why',
			[
				['layer' => 'register', 'layerRef' => 'r-new', 'configKey' => 'register.definition', 'value' => ['slug' => 'cases']],
				['layer' => 'subject', 'layerRef' => 's-new', 'configKey' => 'schema.definition', 'value' => ['slug' => 'case']],
			]
		);

		$this->assertSame(
			[
				['set-9', 'register', 'r-new', 'register.definition', ['slug' => 'cases']],
				['set-9', 'subject', 's-new', 'schema.definition', ['slug' => 'case']],
			],
			$seen
		);
		$this->assertSame('set-9', $set['uuid']);
		$this->assertSame('Promotion from Dev', $set['name']);
	}

	public function testADraftRefusalIsPassedOnWithItsReasonMessageAndStatus(): void {
		$this->drafts->method('openSet')->willReturn($this->set());
		$this->drafts->method('draftValue')->willThrowException(
			new DeploymentRefusedException(reason: DeploymentRefusedException::REASON_INVALID, message: 'bad key', refusals: [['k' => 1]], statusCode: 400)
		);

		try {
			$this->landing()->land('n', 'd', [['layer' => 'subject', 'layerRef' => 'x', 'configKey' => 'schema.definition', 'value' => []]]);
			$this->fail('a draft refusal must be passed on');
		} catch (PromotionRefusedException $refusal) {
			$this->assertSame('invalid', $refusal->reason);
			$this->assertSame('bad key', $refusal->getMessage());
			$this->assertSame([['k' => 1]], $refusal->refusals);
			$this->assertSame(400, $refusal->statusCode);
		}
	}
}

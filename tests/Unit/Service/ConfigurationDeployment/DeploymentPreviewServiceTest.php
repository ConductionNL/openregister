<?php

/**
 * Unit tests for the diff a deployment would apply.
 *
 * REQ-CAD-002's second scenario lives here: a set where one value would fail
 * validation is not deployable, and the refusing value is named. So does the
 * stale-draft refusal, which is what stops a set approved against one reading
 * of the instance from being applied to a different one.
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

use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use PHPUnit\Framework\TestCase;

final class DeploymentPreviewServiceTest extends TestCase {

	private function set(string $uuid = 'set-1'): ConfigurationDraftSet {
		$set = new ConfigurationDraftSet();
		$set->setUuid($uuid);
		$set->setName('september tuning');
		$set->setState(ConfigurationDraftSet::STATE_OPEN);
		$set->setCreatedBy('author');

		return $set;
	}//end set()

	private function draft(
		string $key,
		mixed $value,
		mixed $base,
		bool $basePresent = true,
		string $layer = ConfigurationLayer::INSTANCE,
		?string $layerRef = null,
		bool $removes = false
	): ConfigurationDraft {
		$draft = new ConfigurationDraft();
		$draft->setUuid('draft-'.$key);
		$draft->setSetUuid('set-1');
		$draft->setLayer($layer);
		$draft->setLayerRef($layerRef);
		$draft->setConfigKey($key);
		$draft->setDraftValue(['value' => $value]);
		$draft->setBaseValue(['value' => $base]);
		$draft->setBasePresent($basePresent);
		$draft->setRemoves($removes);

		return $draft;
	}//end draft()

	/**
	 * The preview over a staged set of drafts and a staged live reading.
	 *
	 * @param ConfigurationDraft[]                     $drafts    The pending values.
	 * @param array<string, ConfigurationSnapshot> $liveByKey The live value per key.
	 * @param bool                                     $fourEyes  Whether the instance requires four eyes.
	 *
	 * @return DeploymentPreviewService The preview service.
	 */
	private function previews(array $drafts, array $liveByKey, bool $fourEyes = false): DeploymentPreviewService {
		$draftService = $this->createMock(ConfigurationDraftService::class);
		$draftService->method('draftsIn')->willReturn($drafts);
		$draftService->method('requiresFourEyes')->willReturn($fourEyes);

		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			static function (string $layer, ?string $layerRef, string $configKey) use ($liveByKey): ConfigurationSnapshot {
				return ($liveByKey[$configKey] ?? ConfigurationSnapshot::absent($layer, $layerRef, $configKey));
			}
		);

		return new DeploymentPreviewService($draftService, $store, new ConfigurationKeyRegistry());
	}//end previews()

	private function live(string $key, mixed $value, string $layer = ConfigurationLayer::INSTANCE): ConfigurationSnapshot {
		return new ConfigurationSnapshot($layer, null, $key, $value, true, 'dep-old', 'admin');
	}//end live()

	public function testAChangedValueIsReportedWithBothSides(): void {
		$preview = $this->previews(
			[$this->draft('rbac', ['enabled' => false], ['enabled' => true])],
			['rbac' => $this->live('rbac', ['enabled' => true])]
		)->preview($this->set());

		$this->assertSame(1, $preview['counts']['toChange']);
		$this->assertSame(0, $preview['counts']['refused']);
		$this->assertTrue($preview['deployable']);
		$this->assertSame('change', $preview['changes'][0]['status']);
		$this->assertSame(['enabled' => true], $preview['changes'][0]['from']);
		$this->assertSame(['enabled' => false], $preview['changes'][0]['to']);
	}//end testAChangedValueIsReportedWithBothSides()

	public function testAValueAlreadyEqualToTheLiveOneChangesNothing(): void {
		$preview = $this->previews(
			[$this->draft('rbac', ['enabled' => true], ['enabled' => true])],
			['rbac' => $this->live('rbac', ['enabled' => true])]
		)->preview($this->set());

		$this->assertSame(0, $preview['counts']['toChange']);
		$this->assertSame(1, $preview['counts']['unchanged']);
		// Nothing to change is not deployable: a deployment that moves no
		// value would put an empty row in an append-only history.
		$this->assertFalse($preview['deployable']);
	}//end testAValueAlreadyEqualToTheLiveOneChangesNothing()

	public function testAnUndeclaredKeyRefusesByName(): void {
		$preview = $this->previews(
			[$this->draft('not_a_setting', 'x', 'x')],
			[]
		)->preview($this->set());

		$this->assertSame(1, $preview['counts']['refused']);
		$this->assertFalse($preview['deployable']);
		$this->assertSame(DeploymentPreviewService::REFUSAL_UNKNOWN_KEY, $preview['refusals'][0]['refusal']);
		$this->assertSame('not_a_setting', $preview['refusals'][0]['key']);
	}//end testAnUndeclaredKeyRefusesByName()

	public function testTheApprovalRequirementCannotTravelInsideADeployment(): void {
		$preview = $this->previews(
			[$this->draft('configuration_four_eyes', false, true)],
			[]
		)->preview($this->set());

		$this->assertSame(1, $preview['counts']['refused']);
		$this->assertStringContainsString('reserved', $preview['refusals'][0]['reason']);
	}//end testTheApprovalRequirementCannotTravelInsideADeployment()

	public function testAValueOfTheWrongShapeRefuses(): void {
		$preview = $this->previews(
			// `rbac` is declared as an object and this is a string.
			[$this->draft('rbac', 'enabled', ['enabled' => true])],
			['rbac' => $this->live('rbac', ['enabled' => true])]
		)->preview($this->set());

		$this->assertSame(DeploymentPreviewService::REFUSAL_INVALID_VALUE, $preview['refusals'][0]['refusal']);
		$this->assertFalse($preview['deployable']);
	}//end testAValueOfTheWrongShapeRefuses()

	public function testADraftTakenAgainstAValueSomebodyElseHasSinceChangedRefuses(): void {
		$preview = $this->previews(
			[$this->draft('retention', ['readLogRetention' => 10], ['readLogRetention' => 1])],
			// The live value is neither the base nor the draft: somebody else
			// moved it between the draft being written and this preview.
			['retention' => $this->live('retention', ['readLogRetention' => 999])]
		)->preview($this->set());

		$this->assertSame(DeploymentPreviewService::REFUSAL_STALE_DRAFT, $preview['refusals'][0]['refusal']);
		$this->assertStringContainsString('discard that change', $preview['refusals'][0]['reason']);
	}//end testADraftTakenAgainstAValueSomebodyElseHasSinceChangedRefuses()

	public function testAKeyThatDidNotExistYetIsACreateRatherThanAChange(): void {
		$preview = $this->previews(
			[$this->draft('solr', ['enabled' => true], null, false)],
			[]
		)->preview($this->set());

		$this->assertSame('create', $preview['changes'][0]['status']);
		$this->assertTrue($preview['deployable']);
	}//end testAKeyThatDidNotExistYetIsACreateRatherThanAChange()

	public function testOneRefusalAmongManyGoodValuesStillBlocksTheWholeSet(): void {
		$preview = $this->previews(
			[
				$this->draft('rbac', ['enabled' => false], ['enabled' => true]),
				$this->draft('solr', ['enabled' => true], ['enabled' => false]),
				$this->draft('llm', 'not-an-object', ['provider' => 'ollama']),
			],
			[
				'rbac' => $this->live('rbac', ['enabled' => true]),
				'solr' => $this->live('solr', ['enabled' => false]),
				'llm' => $this->live('llm', ['provider' => 'ollama']),
			]
		)->preview($this->set());

		$this->assertSame(2, $preview['counts']['toChange']);
		$this->assertSame(1, $preview['counts']['refused']);
		$this->assertFalse($preview['deployable']);
	}//end testOneRefusalAmongManyGoodValuesStillBlocksTheWholeSet()

	public function testTwoObjectsDifferingOnlyInKeyOrderAreTheSameValue(): void {
		$preview = $this->previews(
			[$this->draft('rbac', ['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1])],
			['rbac' => $this->live('rbac', ['b' => 2, 'a' => 1])]
		)->preview($this->set());

		$this->assertSame(1, $preview['counts']['unchanged']);
		$this->assertSame(0, $preview['counts']['refused']);
	}//end testTwoObjectsDifferingOnlyInKeyOrderAreTheSameValue()
}//end class

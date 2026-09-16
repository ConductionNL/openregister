<?php

/**
 * Unit tests for the administered seed.
 *
 * REQ-CAD-006, second scenario. Three ways a seed goes wrong quietly, and the
 * test for each:
 *
 * - It writes live values, so a fresh instance is configured by a button
 *   nobody reviewed. Here the live store is never written.
 * - It overwrites a key the administrator already set, which is the opposite
 *   of what "seed the defaults" means on a running instance.
 * - One domain throws and the whole seed fails, leaving an administrator with
 *   a half-filled set and no way to tell which half.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSeedService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigurationSeedServiceTest extends TestCase {

	/**
	 * What was staged, by config key.
	 *
	 * @var array<string, mixed>
	 */
	private array $staged = [];

	private function drafts(): ConfigurationDraftService&MockObject {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-seed');
		$set->setName('working defaults seeded on 2026-09-16');
		$set->setState(ConfigurationDraftSet::STATE_OPEN);

		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('openSet')->willReturn($set);
		$drafts->method('draftValue')->willReturnCallback(
			function (string $setUuid, string $layer, ?string $ref, string $key, mixed $value): ConfigurationDraft {
				$this->staged[$key] = ['layer' => $layer, 'ref' => $ref, 'value' => $value];
				return new ConfigurationDraft();
			}
		);

		return $drafts;
	}//end drafts()

	/**
	 * A store where the named keys are already set on the instance.
	 *
	 * @param array<int, string> $alreadySet The keys this instance holds.
	 */
	private function store(array $alreadySet = []): ConfigurationValueStore&MockObject {
		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			static function (string $layer, ?string $ref, string $key) use ($alreadySet): ConfigurationSnapshot {
				if (in_array($key, $alreadySet, true) === false) {
					return ConfigurationSnapshot::absent(layer: $layer, layerRef: $ref, configKey: $key);
				}

				return new ConfigurationSnapshot(
					layer: $layer,
					layerRef: $ref,
					configKey: $key,
					value: ['chosen' => 'by the administrator'],
					present: true
				);
			}
		);

		return $store;
	}//end store()

	private function settings(): SettingsService&MockObject {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRbacSettingsOnly')->willReturn(['rbac' => ['enabled' => true], 'availableGroups' => []]);
		$settings->method('getMultitenancySettingsOnly')->willReturn(['multitenancy' => ['enabled' => false]]);
		$settings->method('getOrganisationSettingsOnly')->willReturn(['organisation' => ['auto' => true]]);
		// The five getters that answer their blob directly rather than wrapping
		// it. Reading a section off these would seed null, silently.
		$settings->method('getRetentionSettingsOnly')->willReturn(['objectArchiveRetention' => 31536000000]);
		$settings->method('getArchivalSettingsOnly')->willReturn(['notificationLeadDays' => 30]);
		$settings->method('getObjectSettingsOnly')->willReturn(['vectorizationEnabled' => false]);
		$settings->method('getFileSettingsOnly')->willReturn(['chunkingStrategy' => 'RECURSIVE_CHARACTER']);
		$settings->method('getLLMSettingsOnly')->willReturn(['enabled' => false]);

		return $settings;
	}//end settings()

	public function testAFreshInstanceGetsAWorkingVocabularyToReview(): void {
		$store = $this->store();
		$store->expects($this->never())->method('write');

		$answer = (new ConfigurationSeedService($this->drafts(), $store, $this->settings()))->seed();

		$this->assertSame('set-seed', $answer['set']['uuid']);
		$this->assertCount(8, $answer['seeded']);
		$this->assertSame([], $answer['skipped']);

		// Every default is staged at the instance layer, and the unwrapped
		// getters are staged as their own blob rather than as null.
		$this->assertSame(['enabled' => true], $this->staged['rbac']['value']);
		$this->assertSame(['objectArchiveRetention' => 31536000000], $this->staged['retention']['value']);
		$this->assertSame(['chunkingStrategy' => 'RECURSIVE_CHARACTER'], $this->staged['fileManagement']['value']);
		$this->assertSame(ConfigurationLayer::INSTANCE, $this->staged['llm']['layer']);
	}//end testAFreshInstanceGetsAWorkingVocabularyToReview()

	public function testAKeyTheAdministratorAlreadySetIsSkippedByName(): void {
		$answer = (new ConfigurationSeedService($this->drafts(), $this->store(['rbac', 'llm']), $this->settings()))
			->seed();

		$this->assertArrayNotHasKey('rbac', $this->staged);
		$this->assertArrayNotHasKey('llm', $this->staged);
		$this->assertCount(6, $answer['seeded']);
		$this->assertSame(['rbac', 'llm'], array_column($answer['skipped'], 'key'));
		$this->assertSame('this instance already sets it', $answer['skipped'][0]['reason']);
	}//end testAKeyTheAdministratorAlreadySetIsSkippedByName()

	public function testADomainThatCannotAnswerIsSkippedRatherThanFailingTheSeed(): void {
		$settings = $this->settings();
		$settings->method('getLLMSettingsOnly')->willThrowException(new RuntimeException('no llm handler wired'));

		$answer = (new ConfigurationSeedService($this->drafts(), $this->store(), $settings))->seed();

		$this->assertCount(7, $answer['seeded']);
		$this->assertSame(['llm'], array_column($answer['skipped'], 'key'));
		$this->assertStringContainsString('no default', $answer['skipped'][0]['reason']);
	}//end testADomainThatCannotAnswerIsSkippedRatherThanFailingTheSeed()

	public function testASeedThatStagesNothingSaysSoRatherThanReportingSuccess(): void {
		$alreadySet = ['rbac', 'multitenancy', 'organisation', 'retention', 'archival', 'objectManagement', 'fileManagement', 'llm'];

		$answer = (new ConfigurationSeedService($this->drafts(), $this->store($alreadySet), $this->settings()))->seed();

		$this->assertSame([], $answer['seeded']);
		$this->assertSame('this instance already sets every default, so nothing was staged', $answer['message']);
	}//end testASeedThatStagesNothingSaysSoRatherThanReportingSuccess()
}//end class

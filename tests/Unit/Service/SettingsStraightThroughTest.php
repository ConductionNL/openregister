<?php

/**
 * The regression that an instance which never drafts writes straight through.
 *
 * Task 6.3. The draft gate sits in front of ten settings endpoints, which is
 * every settings endpoint OpenRegister has. Almost every instance in the fleet
 * will never switch drafting on, and for those the only acceptable behaviour is
 * the one they had yesterday: the handler is called once, with the payload it
 * was always called with, and its answer is returned unchanged.
 *
 * This is deliberately a whole-facade test rather than one case. A gate that
 * short-circuits nine methods correctly and swallows the tenth would pass any
 * test written per method by the person who added the tenth.
 *
 * Both no-gate instances are covered: no gate wired at all, which is what the
 * existing unit tests construct, and a gate wired with drafting off, which is
 * what a real instance runs.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\SettingsDomainMap;
use OCA\OpenRegister\Service\ConfigurationDeployment\SettingsDraftGate;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCA\OpenRegister\Service\Settings\ValidationOperationsHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SettingsStraightThroughTest extends TestCase {

	/**
	 * The handler doubles this facade delegates to, by property name.
	 *
	 * @var array<string, \PHPUnit\Framework\MockObject\MockObject>
	 */
	private array $handlers = [];

	private function facade(?SettingsDraftGate $gate): SettingsService {
		$this->handlers = [
			'searchBackend' => $this->createMock(SearchBackendHandler::class),
			'llm' => $this->createMock(LlmSettingsHandler::class),
			'file' => $this->createMock(FileSettingsHandler::class),
			'retention' => $this->createMock(ObjectRetentionHandler::class),
			'cache' => $this->createMock(CacheSettingsHandler::class),
			'configuration' => $this->createMock(ConfigurationSettingsHandler::class),
		];

		return new SettingsService(
			$this->createMock(IConfig::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ICacheFactory::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(SchemaCacheHandler::class),
			$this->createMock(FacetCacheHandler::class),
			$this->createMock(SearchTrailMapper::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IDBConnection::class),
			null,
			null,
			'openregister',
			$this->createMock(ValidationOperationsHandler::class),
			$this->handlers['searchBackend'],
			$this->handlers['llm'],
			$this->handlers['file'],
			$this->handlers['retention'],
			$this->handlers['cache'],
			$this->handlers['configuration'],
			$gate
		);
	}//end facade()

	/**
	 * A gate on an instance that has not switched drafting on.
	 */
	private function gateWithDraftingOff(): SettingsDraftGate {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);

		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->expects($this->never())->method('draftValue');
		$drafts->expects($this->never())->method('openSet');

		return new SettingsDraftGate(
			$drafts,
			$this->createMock(ConfigurationValueStore::class),
			new SettingsDomainMap(),
			$appConfig,
			'openregister'
		);
	}//end gateWithDraftingOff()

	/**
	 * Every update method, with the handler and handler method behind it.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function updateMethods(): array {
		return [
			'search backend' => ['updateSearchBackendConfig', 'searchBackend', 'updateSearchBackendConfig'],
			'llm' => ['updateLLMSettingsOnly', 'llm', 'updateLLMSettingsOnly'],
			'files' => ['updateFileSettingsOnly', 'file', 'updateFileSettingsOnly'],
			'objects' => ['updateObjectSettingsOnly', 'retention', 'updateObjectSettingsOnly'],
			'retention' => ['updateRetentionSettingsOnly', 'retention', 'updateRetentionSettingsOnly'],
			'archival' => ['updateArchivalSettingsOnly', 'retention', 'updateArchivalSettingsOnly'],
			'settings' => ['updateSettings', 'configuration', 'updateSettings'],
			'rbac' => ['updateRbacSettingsOnly', 'configuration', 'updateRbacSettingsOnly'],
			'organisation' => ['updateOrganisationSettingsOnly', 'configuration', 'updateOrganisationSettingsOnly'],
			'multitenancy' => ['updateMultitenancySettingsOnly', 'configuration', 'updateMultitenancySettingsOnly'],
		];
	}//end updateMethods()

	/**
	 * @dataProvider updateMethods
	 */
	public function testWithNoGateWiredEveryDomainWritesStraightThrough(
		string $facadeMethod,
		string $handler,
		string $handlerMethod
	): void {
		$facade = $this->facade(null);

		$this->handlers[$handler]
			->expects($this->once())
			->method($handlerMethod)
			->willReturn(['written' => $handlerMethod]);

		$this->assertSame(
			['written' => $handlerMethod],
			$facade->{$facadeMethod}(['rbac' => ['enabled' => false], 'enabled' => false])
		);
	}//end testWithNoGateWiredEveryDomainWritesStraightThrough()

	/**
	 * @dataProvider updateMethods
	 */
	public function testWithDraftingOffEveryDomainWritesStraightThrough(
		string $facadeMethod,
		string $handler,
		string $handlerMethod
	): void {
		$facade = $this->facade($this->gateWithDraftingOff());

		$this->handlers[$handler]
			->expects($this->once())
			->method($handlerMethod)
			->willReturn(['written' => $handlerMethod]);

		$answer = $facade->{$facadeMethod}(['rbac' => ['enabled' => false], 'enabled' => false]);

		$this->assertSame(['written' => $handlerMethod], $answer);
		// The pending marker is what a drafted answer carries. Its absence is
		// the assertion: a straight-through write answers the handler, whole.
		$this->assertArrayNotHasKey('pending', $answer);
	}//end testWithDraftingOffEveryDomainWritesStraightThrough()

	/**
	 * A gate on an instance that has switched drafting on.
	 */
	private function gateWithDraftingOn(): SettingsDraftGate {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);
		$appConfig->method('getValueString')->willReturn('');

		$set = new \OCA\OpenRegister\Db\ConfigurationDraftSet();
		$set->setUuid('set-1');
		$set->setName('settings drafted today');
		$set->setState(\OCA\OpenRegister\Db\ConfigurationDraftSet::STATE_OPEN);

		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('openSet')->willReturn($set);
		$drafts->method('draftValue')->willReturn(new \OCA\OpenRegister\Db\ConfigurationDraft());

		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			static fn (string $layer, ?string $ref, string $key): ConfigurationSnapshot
				=> ConfigurationSnapshot::absent(layer: $layer, layerRef: $ref, configKey: $key)
		);

		return new SettingsDraftGate(
			$drafts,
			$store,
			new SettingsDomainMap(),
			$appConfig,
			'openregister'
		);
	}//end gateWithDraftingOn()

	/**
	 * REQ-CAD-006, scenario "no domain escapes the lifecycle".
	 *
	 * This is the case the straight-through test cannot see: with drafting off
	 * a method that forgot to ask the gate behaves identically to one that
	 * asked. With drafting on it does not, because its handler still runs.
	 *
	 * @dataProvider updateMethods
	 */
	public function testWithDraftingOnNoDomainEscapesTheLifecycle(
		string $facadeMethod,
		string $handler,
		string $handlerMethod
	): void {
		$facade = $this->facade($this->gateWithDraftingOn());

		$this->handlers[$handler]->expects($this->never())->method($handlerMethod);

		$answer = $facade->{$facadeMethod}(['rbac' => ['enabled' => false], 'enabled' => false]);

		$this->assertTrue($answer['pending']);
		$this->assertNotEmpty($answer['drafts']);
	}//end testWithDraftingOnNoDomainEscapesTheLifecycle()

	public function testTheDomainMapCoversEveryUpdateMethodOnTheFacade(): void {
		$mapped = (new SettingsDomainMap())->domains();

		// Ten update methods, ten domains. A method added to the facade without
		// a domain here would be a settings write the lifecycle cannot see, and
		// REQ-CAD-006 is that no domain escapes it.
		$this->assertCount(count(self::updateMethods()), $mapped);
	}//end testTheDomainMapCoversEveryUpdateMethodOnTheFacade()
}//end class

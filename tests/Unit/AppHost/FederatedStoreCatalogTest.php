<?php

/**
 * Tests for the federated store catalogue.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Store\FederatedStoreCatalog;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The store's catalogue when an app exchanges configuration.
 *
 * @covers \OCA\OpenRegister\AppHost\Store\FederatedStoreCatalog
 *
 * @uses \OCA\OpenRegister\AppHost\Service\StoreDescriptor
 * @uses \OCA\OpenRegister\AppHost\Store\StoreManifest
 */
class FederatedStoreCatalogTest extends TestCase {
	/** @var ShareableConfigTypeRegistry&MockObject */
	private $registry;

	/** @var FederatedConfigService&MockObject */
	private $federated;

	private FederatedStoreCatalog $catalog;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = $this->getMockBuilder(ShareableConfigTypeRegistry::class)
			->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
		$this->federated = $this->getMockBuilder(FederatedConfigService::class)
			->disableOriginalConstructor()
			->onlyMethods(['discover', 'fetchBundle', 'install', 'isSourceAllowed'])
			->getMock();

		$this->catalog = new FederatedStoreCatalog(
			registry: $this->registry,
			federated: $this->federated,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A descriptor naming one type.
	 *
	 * @param array<int, string> $types The declared type ids.
	 *
	 * @return StoreDescriptor
	 */
	private function descriptor(array $types = ['openregister.configset']): StoreDescriptor {
		return new StoreDescriptor(
			appId: 'decidiq',
			schema: '',
			defaultRegister: '',
			types: $types
		);
	}//end descriptor()

	/**
	 * A registered type stub.
	 *
	 * @param string $id    The type id.
	 * @param string $topic The discovery topic.
	 * @param string $name  The display name.
	 *
	 * @return IShareableConfigType&MockObject
	 */
	private function type(string $id, string $topic, string $name) {
		$type = $this->createMock(IShareableConfigType::class);
		$type->method('getId')->willReturn($id);
		$type->method('getTopic')->willReturn($topic);
		$type->method('getDisplayName')->willReturn($name);
		return $type;
	}//end type()

	/**
	 * A discovered configuration set becomes one card naming its type.
	 *
	 * @return void
	 */
	public function testDiscoveredSetBecomesACard(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willReturn([
			[
				'repo' => 'ConductionNL/default-gemeente',
				'name' => 'default-gemeente',
				'description' => 'A council with committees and factions.',
				'url' => 'https://github.com/ConductionNL/default-gemeente',
			],
		]);

		$result = $this->catalog->search(descriptor: $this->descriptor());

		$this->assertSame('ok', $result['outcome']);
		$this->assertCount(1, $result['cards']);
		$this->assertSame('openregister.configset', $result['cards'][0]['type']);
		$this->assertSame('Configuration set', $result['cards'][0]['typeName']);
		$this->assertSame('ConductionNL', $result['cards'][0]['publisher']);
	}//end testDiscoveredSetBecomesACard()

	/**
	 * A declared type nothing owns is skipped, not fatal.
	 *
	 * One stale id in a manifest must not blank the whole store.
	 *
	 * @return void
	 */
	public function testUnownedTypeIsSkipped(): void {
		$this->registry->method('get')->willReturn(null);
		$this->federated->expects($this->never())->method('discover');

		$result = $this->catalog->search(descriptor: $this->descriptor(types: ['nobody.owns-this']));

		$this->assertSame([], $result['cards']);
	}//end testUnownedTypeIsSkipped()

	/**
	 * An untrusted publisher is refused before anything is written.
	 *
	 * @return void
	 */
	public function testUntrustedSourceIsRefused(): void {
		$this->federated->method('isSourceAllowed')->willReturn(false);
		$this->federated->expects($this->never())->method('install');

		$report = $this->catalog->install(
			ref: [
				'typeId' => 'openregister.configset',
				'repo' => 'someone/else',
				'source' => 'https://github.com/someone/else',
				'bundle' => ['type' => 'openregister.configset'],
			]
		);

		$this->assertFalse($report['success']);
		$this->assertSame('refused', $report['components'][0]['status']);
	}//end testUntrustedSourceIsRefused()

	/**
	 * A trusted bundle is applied by the type that owns it.
	 *
	 * @return void
	 */
	public function testTrustedBundleInstallsThroughItsType(): void {
		$this->federated->method('isSourceAllowed')->willReturn(true);
		$this->federated->expects($this->once())
			->method('install')
			->with('openregister.configset', ['type' => 'openregister.configset'], 'https://github.com/c/d')
			->willReturn(['installed' => ['registers', 'schemas', 'flows']]);

		$report = $this->catalog->install(
			ref: [
				'typeId' => 'openregister.configset',
				'repo' => 'c/d',
				'source' => 'https://github.com/c/d',
				'bundle' => ['type' => 'openregister.configset'],
			]
		);

		$this->assertTrue($report['success']);
		$this->assertCount(3, $report['components']);
	}//end testTrustedBundleInstallsThroughItsType()

	/**
	 * The card slug survives a round trip through the URL pattern.
	 *
	 * @return void
	 */
	public function testSlugIsUrlSafe(): void {
		$slug = FederatedStoreCatalog::slugFor(
			typeId: 'openregister.configset',
			repo: 'ConductionNL/default-gemeente'
		);

		$this->assertSame('openregister-configset-conductionnl-default-gemeente', $slug);
		$this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug);
	}//end testSlugIsUrlSafe()

	/**
	 * An app that declares no types is not a federated store.
	 *
	 * The two paths are selected by declaration, so an app that has not moved
	 * never reaches discovery at all.
	 *
	 * @return void
	 */
	public function testNoDeclaredTypesIsNotFederated(): void {
		$store = StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => ['schema' => 'case-type-template']]
		);

		$this->assertFalse($store->isFederated());
		$this->assertSame([], $store->declaredTypes());
		$this->assertFalse($this->descriptor(types: [])->isFederated());
	}//end testNoDeclaredTypesIsNotFederated()

	/**
	 * Declared types are carried through the manifest in order.
	 *
	 * @return void
	 */
	public function testDeclaredTypesAreCarried(): void {
		$store = StoreManifest::fromManifest(
			appId: 'decidiq',
			manifest: ['store' => ['types' => ['openregister.configset', 'openregister.flows']]]
		);

		$this->assertTrue($store->isFederated());
		$this->assertSame(['openregister.configset', 'openregister.flows'], $store->declaredTypes());
	}//end testDeclaredTypesAreCarried()
}//end class

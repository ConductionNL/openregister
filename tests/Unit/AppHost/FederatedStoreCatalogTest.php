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
	 * A slug resolves to the bundle the repository carries.
	 *
	 * @return void
	 */
	public function testResolveFetchesTheBundleAtTheConventionalPath(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willReturn([
			['repo' => 'ConductionNL/default-gemeente', 'url' => 'https://github.com/ConductionNL/default-gemeente'],
		]);
		$this->federated->expects($this->once())
			->method('fetchBundle')
			->with('ConductionNL/default-gemeente', 'openregister.json')
			->willReturn(['type' => 'openregister.configset', 'version' => '1']);

		$slug = FederatedStoreCatalog::slugFor(
			typeId: 'openregister.configset',
			repo: 'ConductionNL/default-gemeente'
		);
		$ref = $this->catalog->resolve(descriptor: $this->descriptor(), slug: $slug);

		$this->assertNotNull($ref);
		$this->assertSame('openregister.configset', $ref['typeId']);
		$this->assertSame('ConductionNL/default-gemeente', $ref['repo']);
		$this->assertSame('openregister.configset', $ref['bundle']['type']);
	}//end testResolveFetchesTheBundleAtTheConventionalPath()

	/**
	 * A slug matching no discovered repository resolves to nothing.
	 *
	 * @return void
	 */
	public function testResolveReturnsNullWhenNothingMatches(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willReturn([['repo' => 'someone/other-thing']]);
		$this->federated->expects($this->never())->method('fetchBundle');

		$this->assertNull($this->catalog->resolve(descriptor: $this->descriptor(), slug: 'nothing-by-that-name'));
	}//end testResolveReturnsNullWhenNothingMatches()

	/**
	 * A repository carrying no bundle at any conventional path resolves to nothing.
	 *
	 * Every candidate path is tried before giving up, so a repo using the
	 * dotted directory is still installable.
	 *
	 * @return void
	 */
	public function testResolveTriesEveryConventionalPath(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willReturn([['repo' => 'a/b']]);
		$this->federated->expects($this->exactly(count(FederatedStoreCatalog::BUNDLE_PATHS)))
			->method('fetchBundle')
			->willThrowException(new \RuntimeException('404'));

		$slug = FederatedStoreCatalog::slugFor(typeId: 'openregister.configset', repo: 'a/b');

		$this->assertNull($this->catalog->resolve(descriptor: $this->descriptor(), slug: $slug));
	}//end testResolveTriesEveryConventionalPath()

	/**
	 * The free-text filter reads the title, description and publisher.
	 *
	 * @return void
	 */
	public function testSearchFiltersOnFreeText(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willReturn([
			['repo' => 'ConductionNL/default-gemeente', 'name' => 'default-gemeente', 'description' => 'A council.'],
			['repo' => 'ConductionNL/works-council', 'name' => 'works-council', 'description' => 'Staff advice.'],
		]);

		$hit = $this->catalog->search(descriptor: $this->descriptor(), query: 'gemeente');
		$miss = $this->catalog->search(descriptor: $this->descriptor(), query: 'nothing here');

		$this->assertCount(1, $hit['cards']);
		$this->assertSame('default-gemeente', $hit['cards'][0]['title']);
		$this->assertSame([], $miss['cards']);
	}//end testSearchFiltersOnFreeText()

	/**
	 * The kind chip filters by type id, and a non-matching type is not searched.
	 *
	 * @return void
	 */
	public function testSearchFiltersByType(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->expects($this->never())->method('discover');

		$result = $this->catalog->search(descriptor: $this->descriptor(), query: null, kind: 'openregister.flows');

		$this->assertSame([], $result['cards']);
	}//end testSearchFiltersByType()

	/**
	 * A discovery failure leaves the store empty rather than raising.
	 *
	 * @return void
	 */
	public function testDiscoveryFailureIsContained(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willThrowException(new \RuntimeException('github is down'));

		$result = $this->catalog->search(descriptor: $this->descriptor());

		$this->assertSame('ok', $result['outcome']);
		$this->assertSame([], $result['cards']);
	}//end testDiscoveryFailureIsContained()

	/**
	 * An install whose type raises is reported, not thrown.
	 *
	 * @return void
	 */
	public function testInstallFailureIsReported(): void {
		$this->federated->method('isSourceAllowed')->willReturn(true);
		$this->federated->method('install')->willThrowException(new \RuntimeException('bad bundle'));

		$report = $this->catalog->install(
			ref: [
				'typeId' => 'openregister.configset',
				'repo' => 'c/d',
				'source' => 'https://github.com/c/d',
				'bundle' => [],
			]
		);

		$this->assertFalse($report['success']);
		$this->assertSame('error', $report['components'][0]['status']);
	}//end testInstallFailureIsReported()

	/**
	 * A type reporting nothing still names itself as the installed component.
	 *
	 * @return void
	 */
	public function testInstallWithNoReportedComponentsNamesTheType(): void {
		$this->federated->method('isSourceAllowed')->willReturn(true);
		$this->federated->method('install')->willReturn(['installed' => []]);

		$report = $this->catalog->install(
			ref: [
				'typeId' => 'openregister.flows',
				'repo' => 'c/d',
				'source' => 'https://github.com/c/d',
				'bundle' => [],
			]
		);

		$this->assertTrue($report['success']);
		$this->assertSame('openregister.flows', $report['components'][0]['schema']);
	}//end testInstallWithNoReportedComponentsNamesTheType()

	/**
	 * A type reporting descriptors rather than names is read too.
	 *
	 * @return void
	 */
	public function testInstallReadsDescriptorShapedComponents(): void {
		$this->federated->method('isSourceAllowed')->willReturn(true);
		$this->federated->method('install')->willReturn(
			['installed' => [['type' => 'registers'], ['type' => 'flows']]]
		);

		$report = $this->catalog->install(
			ref: [
				'typeId' => 'openregister.configset',
				'repo' => 'c/d',
				'source' => 'https://github.com/c/d',
				'bundle' => [],
			]
		);

		$this->assertSame(['registers', 'flows'], array_column($report['components'], 'schema'));
	}//end testInstallReadsDescriptorShapedComponents()

	/**
	 * Resolve walks past a declared type nothing owns and keeps looking.
	 *
	 * A stale id in a manifest must not hide a card that a later declared
	 * type does own.
	 *
	 * @return void
	 */
	public function testResolveSkipsAnUnownedTypeAndKeepsLooking(): void {
		$this->registry->method('get')->willReturnCallback(
			fn (string $id) => $id === 'openregister.flows'
				? $this->type(id: 'openregister.flows', topic: 'openregister-flow', name: 'Flows')
				: null
		);
		$this->federated->method('discover')->willReturn([['repo' => 'a/b', 'url' => 'u']]);
		$this->federated->method('fetchBundle')->willReturn(['type' => 'openregister.flows']);

		$slug = FederatedStoreCatalog::slugFor(typeId: 'openregister.flows', repo: 'a/b');
		$ref = $this->catalog->resolve(
			descriptor: $this->descriptor(types: ['nobody.owns-this', 'openregister.flows']),
			slug: $slug
		);

		$this->assertNotNull($ref);
		$this->assertSame('openregister.flows', $ref['typeId']);
	}//end testResolveSkipsAnUnownedTypeAndKeepsLooking()

	/**
	 * A discovery failure during resolve is contained, not raised.
	 *
	 * @return void
	 */
	public function testResolveContainsADiscoveryFailure(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willThrowException(new \RuntimeException('github is down'));

		$this->assertNull($this->catalog->resolve(descriptor: $this->descriptor(), slug: 'anything'));
	}//end testResolveContainsADiscoveryFailure()

	/**
	 * A discovered entry naming no repository is skipped.
	 *
	 * @return void
	 */
	public function testResolveSkipsAnEntryWithNoRepo(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willReturn([['name' => 'no repo here']]);
		$this->federated->expects($this->never())->method('fetchBundle');

		$this->assertNull($this->catalog->resolve(descriptor: $this->descriptor(), slug: 'anything'));
	}//end testResolveSkipsAnEntryWithNoRepo()

	/**
	 * The second conventional path answers when the first does not.
	 *
	 * A repo using the dotted directory is still installable.
	 *
	 * @return void
	 */
	public function testFetchFallsThroughToTheSecondPath(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willReturn([['repo' => 'a/b', 'url' => 'u']]);
		$this->federated->method('fetchBundle')->willReturnCallback(
			function (string $repo, string $path) {
				if ($path === FederatedStoreCatalog::BUNDLE_PATHS[0]) {
					throw new \RuntimeException('404');
				}

				return ['type' => 'openregister.configset', 'from' => $path];
			}
		);

		$slug = FederatedStoreCatalog::slugFor(typeId: 'openregister.configset', repo: 'a/b');
		$ref = $this->catalog->resolve(descriptor: $this->descriptor(), slug: $slug);

		$this->assertNotNull($ref);
		$this->assertSame(FederatedStoreCatalog::BUNDLE_PATHS[1], $ref['bundle']['from']);
	}//end testFetchFallsThroughToTheSecondPath()

	/**
	 * A path answering with an empty bundle is treated as no answer.
	 *
	 * @return void
	 */
	public function testAnEmptyBundleIsNotAnAnswer(): void {
		$this->registry->method('get')->willReturn(
			$this->type(id: 'openregister.configset', topic: 'openregister-configset', name: 'Configuration set')
		);
		$this->federated->method('discover')->willReturn([['repo' => 'a/b', 'url' => 'u']]);
		$this->federated->method('fetchBundle')->willReturn([]);

		$slug = FederatedStoreCatalog::slugFor(typeId: 'openregister.configset', repo: 'a/b');

		$this->assertNull($this->catalog->resolve(descriptor: $this->descriptor(), slug: $slug));
	}//end testAnEmptyBundleIsNotAnAnswer()

	/**
	 * A type reporting a non-list install result still reports success.
	 *
	 * @return void
	 */
	public function testInstallResultWithoutAListIsStillReported(): void {
		$this->federated->method('isSourceAllowed')->willReturn(true);
		$this->federated->method('install')->willReturn(['installed' => 'not-a-list']);

		$report = $this->catalog->install(
			ref: [
				'typeId' => 'openregister.configset',
				'repo' => 'c/d',
				'source' => 'https://github.com/c/d',
				'bundle' => [],
			]
		);

		$this->assertTrue($report['success']);
		$this->assertSame('openregister.configset', $report['components'][0]['schema']);
	}//end testInstallResultWithoutAListIsStillReported()

	/**
	 * A component entry that is neither a name nor a descriptor falls back.
	 *
	 * @return void
	 */
	public function testAnUnreadableComponentEntryFallsBackToTheType(): void {
		$this->federated->method('isSourceAllowed')->willReturn(true);
		$this->federated->method('install')->willReturn(['installed' => [42]]);

		$report = $this->catalog->install(
			ref: [
				'typeId' => 'openregister.flows',
				'repo' => 'c/d',
				'source' => 'https://github.com/c/d',
				'bundle' => [],
			]
		);

		$this->assertSame('openregister.flows', $report['components'][0]['schema']);
	}//end testAnUnreadableComponentEntryFallsBackToTheType()

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

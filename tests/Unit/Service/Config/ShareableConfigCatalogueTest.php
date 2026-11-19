<?php

/**
 * Tests for the shareable configuration catalogue the store surface asks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Config;

use OCA\OpenRegister\AppHost\Store\DeclaredShareableType;
use OCA\OpenRegister\Kernel\Contracts\Integrations\ShareableConfigCatalogueInterface;
use OCA\OpenRegister\Service\Config\FederatedConfigServiceInterface;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Config\ShareableConfigCatalogue;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistryInterface;
use OCA\OpenRegister\Service\ContractBindings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Configuration answer to the store's shareable configuration port.
 *
 * @covers \OCA\OpenRegister\Service\Config\ShareableConfigCatalogue
 *
 * @uses \OCA\OpenRegister\AppHost\Store\DeclaredShareableType
 */
final class ShareableConfigCatalogueTest extends TestCase {

	/** @var ShareableConfigTypeRegistryInterface&MockObject */
	private $registry;

	/** @var FederatedConfigServiceInterface&MockObject */
	private $federated;

	private ShareableConfigCatalogue $catalogue;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry  = $this->createMock(ShareableConfigTypeRegistryInterface::class);
		$this->federated = $this->createMock(FederatedConfigServiceInterface::class);
		$this->catalogue = new ShareableConfigCatalogue(registry: $this->registry, federated: $this->federated);
	}//end setUp()

	/**
	 * A registered type is handed to the store as its id, display name and topic.
	 *
	 * @return void
	 */
	public function testARegisteredTypeBecomesADeclaredType(): void {
		$type = $this->createMock(IShareableConfigType::class);
		$type->method('getDisplayName')->willReturn('Configuration set');
		$type->method('getTopic')->willReturn('openregister-configset');
		$this->registry->expects($this->once())->method('get')->with('openregister.configset')->willReturn($type);

		$declared = $this->catalogue->declaredType(typeId: 'openregister.configset');

		$this->assertInstanceOf(DeclaredShareableType::class, $declared);
		$this->assertSame('openregister.configset', $declared->typeId);
		$this->assertSame('Configuration set', $declared->typeName);
		$this->assertSame('openregister-configset', $declared->topic);
	}//end testARegisteredTypeBecomesADeclaredType()

	/**
	 * A type id no app registered is not declared.
	 *
	 * @return void
	 */
	public function testAnUnregisteredTypeIsNotDeclared(): void {
		$this->registry->method('get')->willReturn(null);

		$this->assertNull($this->catalogue->declaredType(typeId: 'nobody.owns.this'));
	}//end testAnUnregisteredTypeIsNotDeclared()

	/**
	 * Discovery, fetch, trust and install are the federated engine's own answers.
	 *
	 * @return void
	 */
	public function testTheEngineAnswersDiscoveryFetchTrustAndInstall(): void {
		$this->federated->expects($this->once())->method('discover')->with('topic-a')->willReturn([['repo' => 'a/b']]);
		$this->federated->expects($this->once())->method('fetchBundle')->with('a/b', 'openregister.json')->willReturn(['type' => 'x']);
		$this->federated->expects($this->once())->method('isSourceAllowed')->with('https://github.com/a/b')->willReturn(false);
		$this->federated->expects($this->once())
			->method('install')
			->with('openregister.configset', ['type' => 'x'], 'https://github.com/a/b')
			->willReturn(['installed' => ['x']]);

		$this->assertSame([['repo' => 'a/b']], $this->catalogue->discover(topic: 'topic-a'));
		$this->assertSame(['type' => 'x'], $this->catalogue->fetchBundle(repo: 'a/b', path: 'openregister.json'));
		$this->assertFalse($this->catalogue->isSourceAllowed(source: 'https://github.com/a/b'));
		$this->assertSame(
			['installed' => ['x']],
			$this->catalogue->install(typeId: 'openregister.configset', bundle: ['type' => 'x'], source: 'https://github.com/a/b')
		);
	}//end testTheEngineAnswersDiscoveryFetchTrustAndInstall()

	/**
	 * Configuration binds the store's port to this class in its own bindings.
	 *
	 * @return void
	 */
	public function testConfigurationBindsThePort(): void {
		$this->assertSame(
			ShareableConfigCatalogue::class,
			ContractBindings::BINDINGS[ShareableConfigCatalogueInterface::class] ?? null
		);
	}//end testConfigurationBindsThePort()
}//end class

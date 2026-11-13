<?php

/**
 * PlatformCapability: versions and limits from the API's own source, deep
 * link patterns, the platform integrations, a block per claiming app, and no
 * register or schema name anywhere in it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Capabilities
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-capability/specs/deep-link-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use OCA\OpenRegister\Capabilities\PlatformCapability;
use OCA\OpenRegister\Capabilities\PlatformIntegrations;
use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesServiceInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCP\App\IAppManager;
use OCP\Capabilities\IPublicCapability;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Capabilities\PlatformCapability
 * @uses   \OCA\OpenRegister\Capabilities\PlatformIntegrations
 * @uses   \OCA\OpenRegister\Dto\DeepLinkRegistration
 */
class PlatformCapabilityTest extends TestCase {

	/**
	 * What the API capabilities endpoint answers an anonymous caller.
	 *
	 * @var array<string, mixed>
	 */
	private const API = [
		'currentVersion' => 'v2',
		'versionHeader' => 'X-OpenRegister-Api-Version',
		'apiVersions' => [
			['id' => 'v1', 'status' => 'deprecated'],
			['id' => 'v2', 'status' => 'current'],
		],
		'contracts' => ['v1' => '/apps/openregister/api/versions/v1/oas', 'v2' => '/apps/openregister/api/versions/v2/oas'],
		'limits' => [
			'uploadBytes' => 536870912,
			'pageSize' => ['default' => 20, 'maximum' => 500],
			'authentication' => ['attempts' => 5, 'window' => 300],
		],
	];

	/**
	 * The capability over the given registrations, or a registry that throws.
	 *
	 * @param array<string, DeepLinkRegistration>|null $registrations The registrations; null makes the registry throw.
	 * @param LoggerInterface|null                     $logger        The logger.
	 *
	 * @return PlatformCapability
	 */
	private function capability(?array $registrations, ?LoggerInterface $logger=null): PlatformCapability {
		$api = $this->createMock(ApiCapabilitiesServiceInterface::class);
		$api->method('publicCapabilities')->willReturn(self::API);
		$api->expects($this->never())->method('sessionCapabilities');

		$deepLinks = $this->createMock(DeepLinkRegistryServiceInterface::class);
		if ($registrations === null) {
			$deepLinks->method('registrations')->willThrowException(new RuntimeException('registry down'));
		} else {
			$deepLinks->method('registrations')->willReturn($registrations);
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('openregister')->willReturn('1.42.0');

		return new PlatformCapability($api, $deepLinks, $appManager, $logger ?? $this->createMock(LoggerInterface::class));
	}//end capability()

	/**
	 * A registration.
	 *
	 * @param string      $appId       The claiming app.
	 * @param string      $register    The register slug.
	 * @param string      $schema      The schema slug.
	 * @param string      $template    The URL template.
	 * @param string|null $displayName The app's display name.
	 *
	 * @return DeepLinkRegistration
	 */
	private function registration(string $appId, string $register, string $schema, string $template, ?string $displayName=null): DeepLinkRegistration {
		return new DeepLinkRegistration(
			appId: $appId,
			registerSlug: $register,
			schemaSlug: $schema,
			urlTemplate: $template,
			displayName: $displayName
		);
	}//end registration()

	/**
	 * The platform block.
	 *
	 * @param PlatformCapability $capability The capability.
	 *
	 * @return array<string, mixed>
	 */
	private function platform(PlatformCapability $capability): array {
		$capabilities = $capability->getCapabilities();
		self::assertSame(['openregister'], array_keys($capabilities));
		self::assertSame(['platform'], array_keys($capabilities['openregister']));
		return $capabilities['openregister']['platform'];
	}//end platform()

	/**
	 * The block is public: a client reads it before it signs in.
	 *
	 * @return void
	 */
	public function testTheBlockIsPublic(): void {
		self::assertInstanceOf(IPublicCapability::class, $this->capability(registrations: []));
	}//end testTheBlockIsPublic()

	/**
	 * A client learns the versions and limits before it tries, and they are the API endpoint's own.
	 *
	 * @return void
	 */
	public function testVersionsAndLimitsAreTheApiEndpointsOwn(): void {
		$platform = $this->platform(capability: $this->capability(registrations: []));

		self::assertSame('1.42.0', $platform['version']);
		self::assertSame(self::API['currentVersion'], $platform['currentVersion']);
		self::assertSame(self::API['versionHeader'], $platform['versionHeader']);
		self::assertSame(self::API['apiVersions'], $platform['apiVersions']);
		self::assertSame(self::API['limits'], $platform['limits']);
		self::assertSame(536870912, $platform['limits']['uploadBytes']);
		self::assertSame(PlatformIntegrations::switches(), $platform['integrations']);
		self::assertSame([], $platform['deepLinks']);
		self::assertSame([], $platform['apps']);
	}//end testVersionsAndLimitsAreTheApiEndpointsOwn()

	/**
	 * A claiming app appears under its own id, with its name, patterns and integrations.
	 *
	 * @return void
	 */
	public function testAClaimingAppHasItsOwnBlock(): void {
		$platform = $this->platform(
			capability: $this->capability(
				registrations: [
					'zaken-register::zaak-schema' => $this->registration('dossiq', 'zaken-register', 'zaak-schema', '/apps/dossiq/#/cases/{uuid}', 'Dossiq'),
					'zaken-register::document-schema' => $this->registration('dossiq', 'zaken-register', 'document-schema', '/apps/dossiq/#/cases/{uuid}'),
					'catalogus::publicatie' => $this->registration('opencatalogi', 'catalogus', 'publicatie', '/apps/opencatalogi/publications/{uuid}'),
				]
			)
		);

		self::assertSame(['dossiq', 'opencatalogi'], array_keys($platform['apps']));
		self::assertSame(
			[
				'id' => 'dossiq',
				'displayName' => 'Dossiq',
				'deepLinks' => ['/apps/dossiq/#/cases/{uuid}'],
				'integrations' => PlatformIntegrations::enabled(),
			],
			$platform['apps']['dossiq']
		);
		self::assertSame('opencatalogi', $platform['apps']['opencatalogi']['displayName']);
		self::assertSame(['/apps/dossiq/#/cases/{uuid}', '/apps/opencatalogi/publications/{uuid}'], $platform['deepLinks']);
	}//end testAClaimingAppHasItsOwnBlock()

	/**
	 * The first display name an app gives is kept.
	 *
	 * @return void
	 */
	public function testTheFirstDisplayNameIsKept(): void {
		$platform = $this->platform(
			capability: $this->capability(
				registrations: [
					'a::b' => $this->registration('dossiq', 'a1', 'b1', '/apps/dossiq/x/{uuid}'),
					'c::d' => $this->registration('dossiq', 'c1', 'd1', '/apps/dossiq/y/{uuid}', 'Dossiq'),
					'e::f' => $this->registration('dossiq', 'e1', 'f1', '/apps/dossiq/z/{uuid}', 'Something else'),
				]
			)
		);

		self::assertSame('Dossiq', $platform['apps']['dossiq']['displayName']);
		self::assertSame(['/apps/dossiq/x/{uuid}', '/apps/dossiq/y/{uuid}', '/apps/dossiq/z/{uuid}'], $platform['apps']['dossiq']['deepLinks']);
	}//end testTheFirstDisplayNameIsKept()

	/**
	 * No register or schema name appears anywhere in the block.
	 *
	 * @return void
	 */
	public function testNoRegisterOrSchemaNameAppears(): void {
		$platform = $this->platform(
			capability: $this->capability(
				registrations: [
					'geheim-register::besluit' => $this->registration('dossiq', 'geheim-register', 'besluit', '/apps/dossiq/#/cases/{uuid}'),
					'geheim-register::melding' => $this->registration('dossiq', 'geheim-register', 'melding', '/apps/dossiq/geheim-register/{uuid}'),
					'archief::stuk' => $this->registration('records', 'archief', 'stuk', '/apps/records/index.php/STUK?id={uuid}'),
				]
			)
		);

		$published = (string)json_encode($platform);
		foreach (['geheim-register', 'besluit', 'melding', 'archief', 'stuk'] as $name) {
			self::assertStringNotContainsStringIgnoringCase($name, $published, $name.' was published.');
		}

		self::assertSame(['/apps/dossiq/#/cases/{uuid}'], $platform['apps']['dossiq']['deepLinks']);
		self::assertSame([], $platform['apps']['records']['deepLinks']);
		self::assertSame(['/apps/dossiq/#/cases/{uuid}'], $platform['deepLinks']);
	}//end testNoRegisterOrSchemaNameAppears()

	/**
	 * A registration without an app id makes no block, and an empty pattern is not published.
	 *
	 * @return void
	 */
	public function testIncompleteRegistrationsAreLeftOut(): void {
		$platform = $this->platform(
			capability: $this->capability(
				registrations: [
					'a::b' => $this->registration('', 'a1', 'b1', '/apps/nobody/{uuid}'),
					'c::d' => $this->registration('dossiq', 'c1', 'd1', '   '),
				]
			)
		);

		self::assertSame(['dossiq'], array_keys($platform['apps']));
		self::assertSame([], $platform['apps']['dossiq']['deepLinks']);
		self::assertSame([], $platform['deepLinks']);
	}//end testIncompleteRegistrationsAreLeftOut()

	/**
	 * A registry that cannot be read costs the app blocks only, and is logged.
	 *
	 * @return void
	 */
	public function testABrokenRegistryCostsOnlyTheAppBlocks(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('registry down'));

		$platform = $this->platform(capability: $this->capability(registrations: null, logger: $logger));

		self::assertSame([], $platform['apps']);
		self::assertSame([], $platform['deepLinks']);
		self::assertSame(self::API['limits'], $platform['limits']);
	}//end testABrokenRegistryCostsOnlyTheAppBlocks()
}//end class

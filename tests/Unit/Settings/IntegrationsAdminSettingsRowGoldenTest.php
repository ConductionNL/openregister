<?php

/**
 * Golden test for the rows IntegrationsAdminSettings renders.
 *
 * Pins the whole row, key order included, for an external provider (the
 * registry refuses one without an OpenConnector source), an external provider
 * with an installed required app and a native provider whose required app is
 * missing, so the row builder can be split without changing a row.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-23
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Settings;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Settings\IntegrationLinks;
use OCA\OpenRegister\Settings\IntegrationsAdminSettings;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden rows of IntegrationsAdminSettings::getForm().
 */
class IntegrationsAdminSettingsRowGoldenTest extends TestCase {

	/**
	 * A provider stub with the given storage, required app and source.
	 *
	 * @param string      $storage     The storage strategy.
	 * @param string|null $requiredApp The required app.
	 * @param string|null $source      The OpenConnector source.
	 *
	 * @return AbstractIntegrationProvider The provider.
	 */
	private function provider(string $storage, ?string $requiredApp, ?string $source): AbstractIntegrationProvider {
		return new class($storage, $requiredApp, $source) extends AbstractIntegrationProvider {
			public function __construct(
				private string $storage,
				private ?string $requiredApp,
				private ?string $source,
			) {
			}

			public function getId(): string {
				return 'p-' . $this->storage;
			}

			public function getLabel(): string {
				return 'Label ' . $this->storage;
			}

			public function getIcon(): string {
				return 'Icon';
			}

			public function getGroup(): ?string {
				return null;
			}

			public function getRequiredApp(): ?string {
				return $this->requiredApp;
			}

			public function getStorageStrategy(): string {
				return $this->storage;
			}

			public function getOpenConnectorSource(): ?string {
				return $this->source;
			}

			public function isEnabled(): bool {
				return false;
			}

			public function list(string $register, string $schema, string $objectId, array $filters = []): array {
				return [];
			}

			public function health(): array {
				return ['status' => 'ok', 'authStatus' => 'none', 'message' => 'native', 'extra' => 'dropped'];
			}
		};
	}//end provider()

	/**
	 * The only row rendered for one provider.
	 *
	 * @param AbstractIntegrationProvider $provider The provider.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function rowFor(AbstractIntegrationProvider $provider): array {
		$registry = new IntegrationRegistry(new NullLogger());
		$registry->addProvider($provider);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('http://nc/configure');
		$url->method('linkToOCSRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params): string => 'http://nc/ocs/' . $route . '/' . $params['id']
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app !== 'missing');

		$router = $this->createMock(ExternalIntegrationRouter::class);
		$router->method('probe')->willReturn(['status' => 'degraded', 'authStatus' => 'configured', 'message' => 'slow']);

		$settings = new IntegrationsAdminSettings(
			registry: $registry,
			router: $router,
			links: new IntegrationLinks(appManager: $appManager, urlGenerator: $url),
			l10n: $l10n,
		);

		return $settings->getForm()->getParams()['rows'][0];
	}//end rowFor()

	public function testExternalProviderWithASource(): void {
		$this->assertSame(
			[
				'id' => 'p-external',
				'label' => 'Label external',
				'group' => '',
				'storage' => 'external',
				'requiredApp' => null,
				'requiredAppOk' => true,
				'enabled' => false,
				'isExternal' => true,
				'openConnectorSource' => 'src-1',
				'authStatus' => 'configured',
				'status' => 'degraded',
				'message' => 'slow',
				'configureUrl' => 'http://nc/configure',
				'testConnectionUrl' => 'http://nc/ocs/openregister.integrations.show/p-external',
			],
			$this->rowFor($this->provider('external', null, 'src-1'))
		);
	}//end testExternalProviderWithASource()

	public function testExternalProviderWithAnInstalledRequiredApp(): void {
		$row = $this->rowFor($this->provider('external', 'present', 'src-2'));

		$this->assertSame('http://nc/configure', $row['configureUrl']);
		$this->assertSame('http://nc/ocs/openregister.integrations.show/p-external', $row['testConnectionUrl']);
		$this->assertSame('present', $row['requiredApp']);
		$this->assertTrue($row['requiredAppOk']);
	}//end testExternalProviderWithAnInstalledRequiredApp()

	public function testNativeProviderWithAMissingApp(): void {
		$this->assertSame(
			[
				'id' => 'p-magic-column',
				'label' => 'Label magic-column',
				'group' => '',
				'storage' => 'magic-column',
				'requiredApp' => 'missing',
				'requiredAppOk' => false,
				'enabled' => false,
				'isExternal' => false,
				'openConnectorSource' => 'ignored',
				'authStatus' => 'none',
				'status' => 'ok',
				'message' => 'native',
				'configureUrl' => null,
				'testConnectionUrl' => null,
			],
			$this->rowFor($this->provider('magic-column', 'missing', 'ignored'))
		);
	}//end testNativeProviderWithAMissingApp()
}//end class

<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\FederatedConfigTransport;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

class FederatedConfigServiceTest extends TestCase {
	private ShareableConfigTypeRegistry&MockObject $registry;

	private IAppConfig&MockObject $config;

	private FederatedConfigService $service;

	private array $allowlist = [''];

	private string $allowUnverified = 'true';

	protected function setUp(): void {
		$this->registry = $this->createMock(ShareableConfigTypeRegistry::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($key === 'federated_config_allow_unverified_bundles' ? $this->allowUnverified : $this->allowlist[0])
		);

		$signer = $this->createMock(\OCA\OpenRegister\Service\Config\BundleSigner::class);
		// Default: an administrator allows unverified bundles, so the source
		// allowlist assertions are unaffected by signing.
		$signer->method('verify')->willReturn(['signed' => false, 'valid' => false, 'trusted' => true, 'publicKey' => null]);
		$signer->method('sign')->willReturnArgument(0);

		$this->service = new FederatedConfigService(
			new FederatedConfigTransport(
				$this->createMock(CredentialBrokerService::class),
				$signer,
				$this->createMock(\OCP\Http\Client\IClientService::class)
			),
			$this->registry,
			$this->config,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);
	}

	private function type(): IShareableConfigType {
		return new class implements IShareableConfigType {
			public function getId(): string {
				return 'demo.type';
			}
			public function getDisplayName(): string {
				return 'Demo';
			}
			public function getTopic(): string {
				return 'demo-topic';
			}
			public function serialise(array $selection): array {
				return ['type' => 'demo.type', 'picked' => $selection];
			}
			public function deserialise(array $bundle): array {
				return ['installed' => ['x']];
			}
		};
	}

	public function testTypesListsTheRegisteredTypes(): void {
		$this->registry->method('all')->willReturn(['demo.type' => $this->type()]);

		$this->assertSame(
			[['id' => 'demo.type', 'name' => 'Demo', 'topic' => 'demo-topic']],
			$this->service->types()
		);
	}

	public function testBundleDelegatesToTheType(): void {
		$this->registry->method('get')->with('demo.type')->willReturn($this->type());

		$out = $this->service->bundle('demo.type', ['flowIds' => ['a']]);
		$this->assertSame(['flowIds' => ['a']], $out['picked']);
	}

	public function testAnUnknownTypeIsRefused(): void {
		$this->registry->method('get')->willReturn(null);
		$this->expectException(UnexpectedValueException::class);
		$this->service->bundle('nope', []);
	}

	public function testInstallSucceedsWhenNoAllowlistIsSet(): void {
		$this->allowlist = ['']; // empty = not yet enforced
		$this->registry->method('get')->willReturn($this->type());

		$result = $this->service->install('demo.type', ['flows' => []], 'anyone/repo');
		$this->assertSame(['x'], $result['installed']);
	}

	public function testInstallFromANonAllowlistedSourceIsRefused(): void {
		$this->allowlist = ['ConductionNL'];
		$this->registry->method('get')->willReturn($this->type());

		$this->expectException(RuntimeException::class);
		$this->service->install('demo.type', ['flows' => []], 'evil/repo');
	}

	public function testInstallFromAnAllowlistedOrgPrefixSucceeds(): void {
		$this->allowlist = ['ConductionNL'];
		$this->registry->method('get')->willReturn($this->type());

		$result = $this->service->install('demo.type', ['flows' => []], 'ConductionNL/flow-pack');
		$this->assertSame(['x'], $result['installed']);
	}

	public function testEmptyAllowlistAllowsAnySourceButASetOneEnforces(): void {
		$this->allowlist = [''];
		$this->assertTrue($this->service->isSourceAllowed('whoever/repo'));

		$this->allowlist = ['acme, ConductionNL/flows'];
		$this->assertTrue($this->service->isSourceAllowed('ConductionNL/flows'));
		$this->assertTrue($this->service->isSourceAllowed('acme/anything'));
		$this->assertFalse($this->service->isSourceAllowed('other/repo'));
	}

	/**
	 * Build a service whose signer returns a fixed verify() verdict.
	 *
	 * @param array $verdict The verdict verify() should return.
	 * @param bool $enforced Whether the trusted-keys list is in force.
	 *
	 * @return FederatedConfigService The service.
	 */
	private function serviceWithVerdict(array $verdict, bool $enforced = false): FederatedConfigService {
		$signer = $this->createMock(\OCA\OpenRegister\Service\Config\BundleSigner::class);
		$signer->method('verify')->willReturn($verdict);
		$signer->method('enforced')->willReturn($enforced);
		$signer->method('sign')->willReturnArgument(0);

		return new FederatedConfigService(
			new FederatedConfigTransport(
				$this->createMock(CredentialBrokerService::class),
				$signer,
				$this->createMock(\OCP\Http\Client\IClientService::class)
			),
			$this->registry,
			$this->config,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function unverifiedVerdicts(): array {
		return [
			'unsigned, no allowance'           => [['signed' => false, 'valid' => false, 'trusted' => true, 'publicKey' => null], ''],
			'signed by any key, no allowance'  => [['signed' => true, 'valid' => true, 'trusted' => true, 'publicKey' => 'k'], ''],
			'a value that is not exactly true' => [['signed' => false, 'valid' => false, 'trusted' => true, 'publicKey' => null], '1'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unverifiedVerdicts')]
	public function testInstallRefusesABundleNothingVouchesFor(array $verdict, string $allowance): void {
		$this->allowlist = [''];
		$this->allowUnverified = $allowance;
		$this->registry->expects($this->never())->method('get');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/trusted/');
		$this->serviceWithVerdict($verdict)->install('demo.type', [], 'ConductionNL/pack');
	}

	public function testAnAdministratorMayAllowUnverifiedBundles(): void {
		$this->allowlist = [''];
		$this->allowUnverified = 'true';
		$this->registry->method('get')->willReturn($this->type());
		$service = $this->serviceWithVerdict(['signed' => false, 'valid' => false, 'trusted' => true, 'publicKey' => null]);

		$this->assertSame(['x'], $service->install('demo.type', [], 'ConductionNL/pack')['installed']);
	}

	public function testATrustedKeyVerifiesWithoutTheAllowance(): void {
		$this->allowlist = [''];
		$this->allowUnverified = '';
		$this->registry->method('get')->willReturn($this->type());
		$service = $this->serviceWithVerdict(['signed' => true, 'valid' => true, 'trusted' => true, 'publicKey' => 'k'], enforced: true);

		$this->assertSame(['x'], $service->install('demo.type', [], 'ConductionNL/pack')['installed']);
	}

	public function testTheAllowanceDoesNotOverruleATrustedKeysList(): void {
		$this->allowlist = [''];
		$this->allowUnverified = 'true';
		$this->registry->expects($this->never())->method('get');
		$service = $this->serviceWithVerdict(['signed' => false, 'valid' => false, 'trusted' => false, 'publicKey' => null], enforced: true);

		$this->expectException(RuntimeException::class);
		$service->install('demo.type', [], 'ConductionNL/pack');
	}

	/**
	 * The values an administrator may send for the allowance, and what is stored.
	 *
	 * @return array<string, array{0: string, 1: string}> Sent and stored.
	 */
	public static function allowanceValues(): array {
		return [
			'JSON true, cast to a string' => ['1', 'true'],
			'the string true'             => ['true', 'true'],
			'upper case'                  => [' TRUE ', 'true'],
			'JSON false, cast to a string' => ['', ''],
			'the string false'            => ['false', ''],
			'zero'                        => ['0', ''],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('allowanceValues')]
	public function testTheAllowanceIsStoredAsTrueOrEmpty(string $sent, string $stored): void {
		$this->config->expects($this->once())->method('setValueString')->with('openregister', 'federated_config_allow_unverified_bundles', $stored);

		$this->service->setTrustValue('allowUnverifiedBundles', $sent);
	}

	public function testOtherTrustSettingsAreStoredAsSent(): void {
		$this->config->expects($this->once())->method('setValueString')->with('openregister', 'federated_config_install_groups', '1');

		$this->service->setTrustValue('installGroups', ' 1 ');
	}

	public function testTheAllowanceIsATrustSetting(): void {
		$this->config->expects($this->once())->method('setValueString')->with('openregister', 'federated_config_allow_unverified_bundles', 'true');

		$this->service->setTrustValue('allowUnverifiedBundles', 'true');
	}

	public function testInstallRefusesATamperedSignature(): void {
		$this->allowlist = [''];
		$this->registry->method('get')->willReturn($this->type());
		$service = $this->serviceWithVerdict(['signed' => true, 'valid' => false, 'trusted' => true, 'publicKey' => 'k']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/signature/');
		$service->install('demo.type', ['provenance' => ['signature' => 'bad']], 'ConductionNL/pack');
	}

	public function testInstallRefusesAnUntrustedPublisher(): void {
		$this->allowlist = [''];
		$this->registry->method('get')->willReturn($this->type());
		$service = $this->serviceWithVerdict(['signed' => true, 'valid' => true, 'trusted' => false, 'publicKey' => 'k']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/trusted/');
		$service->install('demo.type', [], 'ConductionNL/pack');
	}

	public function testInstallAcceptsAValidTrustedBundle(): void {
		$this->allowlist = [''];
		$this->registry->method('get')->willReturn($this->type());
		$service = $this->serviceWithVerdict(['signed' => true, 'valid' => true, 'trusted' => true, 'publicKey' => 'k']);

		$result = $service->install('demo.type', [], 'ConductionNL/pack');
		$this->assertSame(['x'], $result['installed']);
	}

	public function testFetchBundleDecodesTheRepoContentsFile(): void {
		$bundle = ['type' => 'demo.type', 'objects' => [['a' => 1]]];
		$content = base64_encode((string)json_encode($bundle));

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturn(['status' => 200, 'body' => (string)json_encode(['content' => $content, 'encoding' => 'base64'])]);

		$service = new FederatedConfigService(
			new FederatedConfigTransport(
				$broker,
				$this->createMock(\OCA\OpenRegister\Service\Config\BundleSigner::class),
				$this->createMock(\OCP\Http\Client\IClientService::class)
			),
			$this->registry,
			$this->config,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$out = $service->fetchBundle('ConductionNL/pack', 'set.json', 'cred-1');
		$this->assertSame($bundle, $out);
	}

	public function testFetchBundleRejectsMissingContent(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturn(['status' => 404, 'body' => '{"message":"Not Found"}']);

		$service = new FederatedConfigService(
			new FederatedConfigTransport(
				$broker,
				$this->createMock(\OCA\OpenRegister\Service\Config\BundleSigner::class),
				$this->createMock(\OCP\Http\Client\IClientService::class)
			),
			$this->registry,
			$this->config,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$this->expectException(RuntimeException::class);
		$service->fetchBundle('ConductionNL/pack', 'missing.json', 'cred-1');
	}

	public function testPublishEnsuresRepoSetsTopicAndReturnsMetadata(): void {
		$this->registry->method('get')->willReturn($this->type());

		$broker = $this->createMock(CredentialBrokerService::class);
		// Repo missing (404) → created → topics set → contents written; every
		// broker call returns a 2xx so publish completes and reports metadata.
		$calls = [];
		$broker->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path) use (&$calls) {
				$calls[] = $method . ' ' . $path;
				// The initial repo existence check is a miss so ensureRepo creates it.
				if ($method === 'GET' && str_starts_with($path, '/repos/') === true) {
					return ['status' => 404, 'body' => ''];
				}
				return ['status' => 201, 'body' => '{}'];
			}
		);

		$signer = $this->createMock(\OCA\OpenRegister\Service\Config\BundleSigner::class);
		$signer->method('sign')->willReturnArgument(0);
		$service = new FederatedConfigService(
			new FederatedConfigTransport(
				$broker,
				$signer,
				$this->createMock(\OCP\Http\Client\IClientService::class)
			),
			$this->registry,
			$this->config,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$result = $service->publish('demo.type', ['x' => 1], 'ConductionNL/pack', 'set.json', 'cred-1');

		$this->assertTrue($result['published']);
		$this->assertSame('ConductionNL/pack', $result['repo']);
		$this->assertSame('demo-topic', $result['topic']);
		// The topic was set and the bundle written.
		$this->assertContains('PUT /repos/ConductionNL/pack/topics', $calls);
		$this->assertContains('PUT /repos/ConductionNL/pack/contents/set.json', $calls);
	}

	public function testTrustConfigReadWriteRoundTrips(): void {
		$store = [];
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willReturnCallback(
			function (string $a, string $k, string $d = '') use (&$store) {
				return ($store[$k] ?? $d);
			}
		);
		$cfg->method('setValueString')->willReturnCallback(
			function (string $a, string $k, string $v) use (&$store): bool {
				$store[$k] = $v;
				return true;
			}
		);

		$service = new FederatedConfigService(
			new FederatedConfigTransport(
				$this->createMock(CredentialBrokerService::class),
				$this->createMock(\OCA\OpenRegister\Service\Config\BundleSigner::class),
				$this->createMock(\OCP\Http\Client\IClientService::class)
			),
			$this->registry,
			$cfg,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$service->setTrustValue('sourceAllowlist', 'ConductionNL');
		$service->setTrustValue('publishGroups', 'editors');
		$service->trustPublisherKey('KEYA=');
		$service->trustPublisherKey('KEYA=');
		$service->trustPublisherKey('KEYB=');

		$trust = $service->getTrustConfig();
		$this->assertSame('ConductionNL', $trust['sourceAllowlist']);
		$this->assertSame('editors', $trust['publishGroups']);
		$this->assertSame('KEYA=,KEYB=', $trust['trustedKeys']);

		$this->expectException(UnexpectedValueException::class);
		$service->setTrustValue('nope', 'x');
	}
}

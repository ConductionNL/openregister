<?php

/**
 * Golden test for FederatedConfigService install, publish, discover and fetchBundle.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Config
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Config;

use OCA\OpenRegister\Service\Config\BundleSigner;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\FederatedConfigTransport;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each case runs one operation against scripted broker / HTTP-client answers
 * and records the outcome (or exception class and message), every broker and
 * client call, and every warning logged. Install cases pin which bundles are
 * refused and which are installed. Compared with
 * fixtures/federated-config-service-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Config\FederatedConfigService
 */
class FederatedConfigServiceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/federated-config-service-golden.json';

	private const UNSIGNED = ['signed' => false, 'valid' => false, 'trusted' => true, 'publicKey' => null];

	private const VALID = ['signed' => true, 'valid' => true, 'trusted' => true, 'publicKey' => 'k'];

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$bundle = base64_encode((string) json_encode(['type' => 'demo.type', 'x' => 1]));
		$items = (string) json_encode([
			'items' => [
				['full_name' => 'o/a', 'name' => 'a', 'description' => null, 'html_url' => 'u', 'stargazers_count' => '7', 'updated_at' => 'd', 'topics' => ['t', 3, 'u']],
				'not-an-item',
				[],
				['full_name' => 'o/b', 'topics' => 'single'],
			],
		]);
		return [
			'install no allowlist unsigned allowed'   => ['install', ['source' => 'any/repo']],
			'install source refused'                   => ['install', ['allowlist' => 'ConductionNL, other/x', 'source' => 'evil/repo']],
			'install source exact'                     => ['install', ['allowlist' => 'ConductionNL, other/x', 'source' => 'other/x']],
			'install source org prefix'                => ['install', ['allowlist' => 'ConductionNL, other/x', 'source' => 'ConductionNL/pack']],
			'install source prefix not a segment'      => ['install', ['allowlist' => 'ConductionNL', 'source' => 'ConductionNLx/pack']],
			'install empty source with allowlist'      => ['install', ['allowlist' => 'ConductionNL', 'source' => '']],
			'install tampered'                         => ['install', ['verdict' => ['signed' => true, 'valid' => false, 'trusted' => true]]],
			'install tampered and untrusted'           => ['install', ['verdict' => ['signed' => true, 'valid' => false, 'trusted' => false]]],
			'install untrusted'                        => ['install', ['verdict' => ['signed' => true, 'valid' => true, 'trusted' => false]]],
			'install unsigned untrusted'               => ['install', ['verdict' => ['signed' => false, 'valid' => false, 'trusted' => false]]],
			'install unverified not allowed'           => ['install', ['allowUnverified' => '']],
			'install unverified allowance not exact'   => ['install', ['allowUnverified' => 'TRUE']],
			'install enforced valid'                   => ['install', ['verdict' => self::VALID, 'enforced' => true, 'allowUnverified' => '']],
			'install enforced unsigned'                => ['install', ['enforced' => true, 'allowUnverified' => '']],
			'install unknown type'                     => ['install', ['typeId' => 'nope']],
			'publish repo exists'                      => ['publish', ['broker' => ['GET /repos/o/r' => ['status' => 200]]]],
			'publish repo created in org'              => ['publish', ['broker' => ['GET /repos/o/r' => ['status' => 404]]]],
			'publish repo lookup throws'               => ['publish', ['broker' => ['GET /repos/o/r' => 'throw']]],
			'publish org create fails, user ok'        => ['publish', ['broker' => ['GET /repos/o/r' => ['status' => 404], 'POST /orgs/o/repos' => ['status' => 422]]]],
			'publish org create throws, user ok'       => ['publish', ['broker' => ['GET /repos/o/r' => [], 'POST /orgs/o/repos' => 'throw']]],
			'publish no create succeeds'               => ['publish', ['broker' => ['GET /repos/o/r' => ['status' => 404], 'POST /orgs/o/repos' => ['status' => 300], 'POST /user/repos' => 'throw']]],
			'publish status 199 create'                => ['publish', ['broker' => ['GET /repos/o/r' => ['status' => 404], 'POST /orgs/o/repos' => ['status' => 199], 'POST /user/repos' => ['status' => '201']]]],
			'publish repo without name'                => ['publish', ['repo' => 'lonely']],
			'publish repo empty owner'                 => ['publish', ['repo' => '/r']],
			'publish topics throw'                     => ['publish', ['broker' => ['GET /repos/o/r' => ['status' => 200], 'PUT /repos/o/r/topics' => 'throw']]],
			'publish write fails'                      => ['publish', ['broker' => ['GET /repos/o/r' => ['status' => 200], 'PUT /repos/o/r/contents/dir/set.json' => ['status' => 409]]]],
			'publish write no status'                  => ['publish', ['broker' => ['GET /repos/o/r' => ['status' => 200], 'PUT /repos/o/r/contents/dir/set.json' => []]]],
			'publish private branch'                   => ['publish', ['broker' => ['GET /repos/o/r' => ['status' => 404]], 'branch' => 'dev', 'private' => true]],
			'publish unknown type'                     => ['publish', ['typeId' => 'nope']],
			'discover blank topic'                     => ['discover', ['topic' => '  ']],
			'discover anonymous'                       => ['discover', ['topic' => ' demo topic ', 'client' => $items]],
			'discover empty credential is anonymous'   => ['discover', ['credentialId' => '', 'client' => $items]],
			'discover with credential'                 => ['discover', ['credentialId' => 'cred-1', 'broker' => ['GET *' => ['status' => 200, 'body' => $items]]]],
			'discover credential no body'              => ['discover', ['credentialId' => 'cred-1', 'broker' => ['GET *' => ['status' => 200]]]],
			'discover client throws'                   => ['discover', ['client' => 'throw']],
			'discover broker throws'                   => ['discover', ['credentialId' => 'cred-1', 'broker' => ['GET *' => 'throw']]],
			'discover not json'                        => ['discover', ['client' => 'nope']],
			'discover items not a list'                => ['discover', ['client' => '{"items":"x"}']],
			'discover no items'                        => ['discover', ['client' => '{"total":0}']],
			'discover scalar json'                     => ['discover', ['client' => '5']],
			'fetch anonymous'                          => ['fetch', ['client' => (string) json_encode(['content' => $bundle])]],
			'fetch trims repo and path'                => ['fetch', ['repo' => ' o/r ', 'path' => ' /dir/b.json ', 'client' => (string) json_encode(['content' => $bundle])]],
			'fetch with credential'                    => ['fetch', ['credentialId' => 'cred-1', 'broker' => ['GET *' => ['body' => (string) json_encode(['content' => $bundle])]]]],
			'fetch empty repo'                         => ['fetch', ['repo' => ' ']],
			'fetch empty path'                         => ['fetch', ['path' => '/']],
			'fetch client throws'                      => ['fetch', ['client' => 'throw']],
			'fetch broker throws'                      => ['fetch', ['credentialId' => 'cred-1', 'broker' => ['GET *' => 'throw']]],
			'fetch no content'                         => ['fetch', ['client' => '{"sha":"x"}']],
			'fetch not json'                           => ['fetch', ['client' => 'oops']],
			'fetch bad base64'                         => ['fetch', ['client' => '{"content":"***"}']],
			'fetch content not json'                   => ['fetch', ['client' => (string) json_encode(['content' => base64_encode('plain')])]],
			'fetch content scalar json'                => ['fetch', ['client' => (string) json_encode(['content' => base64_encode('7')])]],
			'fetch content numeric'                    => ['fetch', ['client' => '{"content":12}']],
		];
	}

	/**
	 * @param string               $operation install, publish, discover or fetch.
	 * @param array<string, mixed> $scenario  Scripted answers and arguments.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testOperation(string $operation, array $scenario): void {
		$record = ['broker' => [], 'client' => [], 'warnings' => [], 'deserialised' => []];

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			static function (string $credentialId, string $appId, string $method, string $path, array $headers = [], ?string $body = null) use ($scenario, &$record): array {
				$record['broker'][] = [$credentialId, $appId, $method, $path, $headers, $body];
				$script = ($scenario['broker'] ?? []);
				$answer = ($script[$method . ' ' . $path] ?? $script[$method . ' *'] ?? ['status' => 201, 'body' => '{}']);
				if ($answer === 'throw') {
					throw new \RuntimeException('broker down: ' . $path);
				}

				return $answer;
			}
		);

		$clientService = $this->createMock(IClientService::class);
		$client = $this->createMock(IClient::class);
		$clientService->method('newClient')->willReturn($client);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options) use ($scenario, &$record): IResponse {
				$record['client'][] = [$uri, $options];
				if (($scenario['client'] ?? '') === 'throw') {
					throw new \RuntimeException('network down');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn(($scenario['client'] ?? ''));
				return $response;
			}
		);

		$signer = $this->createMock(BundleSigner::class);
		$signer->method('verify')->willReturn(($scenario['verdict'] ?? self::UNSIGNED));
		$signer->method('enforced')->willReturn(($scenario['enforced'] ?? false));
		$signer->method('sign')->willReturnCallback(static fn (array $bundle): array => $bundle + ['signature' => 'sig']);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'federated_config_source_allowlist' => ($scenario['allowlist'] ?? ''),
				'federated_config_allow_unverified_bundles' => ($scenario['allowUnverified'] ?? 'true'),
				default => $default,
			}
		);

		$registry = $this->createMock(ShareableConfigTypeRegistry::class);
		$registry->method('get')->willReturnCallback(
			function (string $id) use (&$record): ?IShareableConfigType {
				return ($id === 'demo.type') ? $this->type($record) : null;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$record): void {
				$record['warnings'][] = $message;
			}
		);

		$service = new FederatedConfigService(new FederatedConfigTransport($broker, $signer, $clientService), $registry, $config, $logger);

		try {
			$outcome = match ($operation) {
				'install' => $service->install(($scenario['typeId'] ?? 'demo.type'), ['type' => 'demo.type'], ($scenario['source'] ?? 'o/r')),
				'publish' => $service->publish(
					($scenario['typeId'] ?? 'demo.type'),
					['x' => 1],
					($scenario['repo'] ?? 'o/r'),
					'/dir/set.json',
					'cred-1',
					($scenario['branch'] ?? 'main'),
					($scenario['private'] ?? false)
				),
				'discover' => $service->discover(($scenario['topic'] ?? 'demo'), ($scenario['credentialId'] ?? null)),
				default => $service->fetchBundle(($scenario['repo'] ?? 'o/r'), ($scenario['path'] ?? 'b.json'), ($scenario['credentialId'] ?? null)),
			};
		} catch (\Throwable $e) {
			$outcome = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = json_decode((string) json_encode(['outcome' => $outcome, 'record' => $record]), true);
		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}

	/**
	 * Mutation check on the pinned install decisions: refused bundles never
	 * reach deserialise(), installed ones do.
	 *
	 * @return void
	 */
	public function testRefusedInstallsNeverDeserialise(): void {
		$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach (['install source refused', 'install tampered', 'install untrusted', 'install unverified not allowed', 'install unsigned untrusted', 'install source prefix not a segment'] as $case) {
			$this->assertArrayHasKey('thrown', $golden[$case]['outcome'], $case);
			$this->assertSame([], $golden[$case]['record']['deserialised'], $case);
		}

		foreach (['install no allowlist unsigned allowed', 'install enforced valid', 'install source org prefix'] as $case) {
			$this->assertArrayNotHasKey('thrown', $golden[$case]['outcome'], $case);
			$this->assertCount(1, $golden[$case]['record']['deserialised'], $case);
		}
	}

	/**
	 * @param array<string, mixed> $record The call record, by reference.
	 *
	 * @return IShareableConfigType
	 */
	private function type(array &$record): IShareableConfigType {
		return new class ($record) implements IShareableConfigType {
			/**
			 * @param array<string, mixed> $record The call record.
			 */
			public function __construct(private array &$record) {
			}

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
				$this->record['deserialised'][] = $bundle;
				return ['installed' => ['x']];
			}
		};
	}
}

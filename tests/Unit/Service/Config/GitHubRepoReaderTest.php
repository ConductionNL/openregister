<?php

/**
 * Reading a package out of a GitHub repository (G3): one archive, else the tree, at a ref.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/store-through-federated-config
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Service\Config\BrokerIdentity;
use OCA\OpenRegister\Service\Config\BundleSigner;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\FederatedConfigTransport;
use OCA\OpenRegister\Service\Config\GitHubArchiveExtractor;
use OCA\OpenRegister\Service\Config\GitHubRepoRead;
use OCA\OpenRegister\Service\Config\GitHubRepoReader;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use Phar;
use PharData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(GitHubRepoReader::class)]
#[CoversClass(GitHubRepoRead::class)]
#[CoversClass(GitHubArchiveExtractor::class)]
#[CoversClass(FederatedConfigService::class)]
#[UsesClass(BrokerIdentity::class)]
#[UsesClass(FederatedConfigTransport::class)]
final class GitHubRepoReaderTest extends TestCase {

	/**
	 * The broker calls made: [path, appId, actingUserId].
	 *
	 * @var list<array{0: string, 1: string, 2: string|null}>
	 */
	private array $brokered = [];

	/**
	 * The anonymous URLs read.
	 *
	 * @var list<string>
	 */
	private array $anonymous = [];

	public function testOneArchiveDownloadReadsTheWantedFilesAsTheNamedAppAndUser(): void {
		$tarball = self::tarball(['acme-pack-abc123/hermiq-skills.json' => '{"v":1}', 'acme-pack-abc123/skills/a/SKILL.md' => '# A', 'acme-pack-abc123/README.md' => 'readme']);
		$reader  = $this->reader(broker: ['/repos/acme/pack/tarball/v1.2' => [200, $tarball]]);

		$read = GitHubRepoRead::of(repo: 'acme/pack', ref: 'v1.2', credentialId: 'cred-1', identity: BrokerIdentity::of(appId: 'hermiq', actingUserId: 'alice'));
		$answer = $reader->files(read: $read, paths: ['hermiq-skills.json', 'skills/'], maxBytes: 1024, maxFiles: 10);

		self::assertSame(['files' => ['hermiq-skills.json' => '{"v":1}', 'skills/a/SKILL.md' => '# A'], 'truncated' => false, 'source' => 'archive'], $answer);
		self::assertSame([['/repos/acme/pack/tarball/v1.2', 'hermiq', 'alice']], $this->brokered);
		self::assertSame([], $this->anonymous);
	}

	public function testABrokerRefusalIsRetriedAnonymously(): void {
		$tarball = self::tarball(['root/a.md' => 'A']);
		$reader  = $this->reader(broker: new RuntimeException('not allowed for this app'), anonymous: ['https://api.github.com/repos/acme/pack/tarball/HEAD' => [200, $tarball]]);

		$answer = $reader->files(read: GitHubRepoRead::of(repo: 'acme/pack', credentialId: 'cred-1'), paths: [], maxBytes: 1024, maxFiles: 10);

		self::assertSame(['a.md' => 'A'], $answer['files']);
		self::assertSame(['https://api.github.com/repos/acme/pack/tarball/HEAD'], $this->anonymous);
	}

	public function testWithoutAnArchiveTheTreeIsReadFileByFileAtTheRef(): void {
		$tree   = (string)json_encode(['tree' => [['type' => 'blob', 'path' => 'hermiq-skill.md'], ['type' => 'tree', 'path' => 'references'], ['type' => 'blob', 'path' => 'references/steps.md'], ['type' => 'blob', 'path' => 'other.txt']]]);
		$reader = $this->reader(anonymous: [
			'https://api.github.com/repos/acme/skill/tarball/main' => [404, ''],
			'https://api.github.com/repos/acme/skill/git/trees/main?recursive=1' => [200, $tree],
			'https://api.github.com/repos/acme/skill/contents/hermiq-skill.md?ref=main' => [200, (string)json_encode(['content' => chunk_split(base64_encode('# Skill'), 4, "\n")])],
			'https://api.github.com/repos/acme/skill/contents/references/steps.md?ref=main' => [200, (string)json_encode(['content' => base64_encode('1. step')])],
		]);

		$answer = $reader->files(read: GitHubRepoRead::of(repo: 'acme/skill', ref: 'main'), paths: ['hermiq-skill.md', 'references/'], maxBytes: 1024, maxFiles: 10);

		self::assertSame(['files' => ['hermiq-skill.md' => '# Skill', 'references/steps.md' => '1. step'], 'truncated' => false, 'source' => 'tree'], $answer);
	}

	public function testAFileByFileReadSaysWhenItsBoundCutThePackageShort(): void {
		$tree   = (string)json_encode(['tree' => [['type' => 'blob', 'path' => 'a.md'], ['type' => 'blob', 'path' => 'b.md']]]);
		$reader = $this->reader(anonymous: [
			'https://api.github.com/repos/acme/skill/tarball/HEAD' => [500, ''],
			'https://api.github.com/repos/acme/skill/git/trees/HEAD?recursive=1' => [200, $tree],
			'https://api.github.com/repos/acme/skill/contents/a.md' => [200, (string)json_encode(['content' => base64_encode('A')])],
		]);

		$answer = $reader->files(read: GitHubRepoRead::of(repo: 'acme/skill'), paths: [], maxBytes: 1024, maxFiles: 1);

		self::assertSame(['files' => ['a.md' => 'A'], 'truncated' => true, 'source' => 'tree'], $answer);
	}

	public function testAnUnreadableRepositoryAnswersNoFiles(): void {
		$reader = $this->reader(anonymous: []);

		self::assertSame(['files' => [], 'truncated' => false, 'source' => 'none'], $reader->files(read: GitHubRepoRead::of(repo: 'acme/gone'), paths: [], maxBytes: 1024, maxFiles: 10));
	}

	public function testAPathThatLeavesTheRepositoryIsNeverRequested(): void {
		$reader = $this->reader(anonymous: []);

		self::assertNull($reader->file(read: GitHubRepoRead::of(repo: 'acme/pack'), path: '../../orgs/x'));
		self::assertNull($reader->file(read: GitHubRepoRead::of(repo: 'acme/pack'), path: 'a//b'));
		self::assertSame([], $this->anonymous);
	}

	public function testUnsafeCoordinatesAreRefusedBeforeAnyCall(): void {
		foreach ([['acme', null], ['acme/../x', null], ['acme/pack/extra', null], ['acme/pack', 'main..evil'], ['acme/pack', 'a b']] as [$repo, $ref]) {
			try {
				GitHubRepoRead::of(repo: $repo, ref: $ref);
				self::fail('Accepted ' . $repo . ' @ ' . (string)$ref);
			} catch (RuntimeException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}

		$read = GitHubRepoRead::of(repo: ' acme/pack ', ref: '', credentialId: ' ');
		self::assertSame(['/repos/acme/pack', 'HEAD', null, 'openregister'], [$read->apiPath(), $read->treeRef(), $read->credentialId, $read->identity->appId]);
	}

	public function testAnArchiveOverTheBoundIsDiscardedWhole(): void {
		$tarball = self::tarball(['root/a.md' => str_repeat('A', 600), 'root/b.md' => str_repeat('B', 600)]);
		$extractor = new GitHubArchiveExtractor();

		self::assertNull($extractor->extract(body: $tarball, wanted: static fn (string $path): bool => true, maxBytes: 1000));
		self::assertNull($extractor->extract(body: $tarball, wanted: static fn (string $path): bool => true, maxBytes: 10));
		self::assertSame(['a.md' => str_repeat('A', 600)], $extractor->extract(body: $tarball, wanted: static fn (string $path): bool => $path === 'a.md', maxBytes: 1000));
	}

	public function testAnArchiveWithoutOneRootFolderIsNotRead(): void {
		$extractor = new GitHubArchiveExtractor();

		self::assertNull($extractor->extract(body: self::tarball(['one/a.md' => 'A', 'two/b.md' => 'B']), wanted: static fn (string $path): bool => true, maxBytes: 1000));
		self::assertNull($extractor->extract(body: 'not a tarball', wanted: static fn (string $path): bool => true, maxBytes: 1000));
	}

	public function testTheServiceReadsAPackageThroughTheReaderWithItsBounds(): void {
		$tarball = self::tarball(['root/hermiq-skill.md' => '# S']);
		$service = new FederatedConfigService(
			$this->transport(broker: null, anonymous: ['https://api.github.com/repos/acme/skill/tarball/HEAD' => [200, $tarball]]),
			$this->createMock(ShareableConfigTypeRegistry::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);

		self::assertSame(['files' => ['hermiq-skill.md' => '# S'], 'truncated' => false, 'source' => 'archive'], $service->fetchPackage(repo: 'acme/skill', paths: ['hermiq-skill.md']));
		$this->expectException(RuntimeException::class);
		$service->fetchPackage(repo: 'not-a-repo');
	}

	/**
	 * A gzipped tarball holding the given files.
	 *
	 * @param array<string, string> $files Path => contents.
	 *
	 * @return string The bytes.
	 */
	private static function tarball(array $files): string {
		$base = sys_get_temp_dir() . '/or-test-' . bin2hex(random_bytes(6));
		$tar  = new PharData($base . '.tar');
		foreach ($files as $path => $contents) {
			$tar->addFromString($path, $contents);
		}

		$tar->compress(Phar::GZ);
		$bytes = (string)file_get_contents($base . '.tar.gz');
		unlink($base . '.tar');
		unlink($base . '.tar.gz');

		return $bytes;
	}

	/**
	 * A reader over a broker and an anonymous client that answer per path.
	 *
	 * @param array<string, array{0: int, 1: string}>|RuntimeException|null $broker    Path => answer, or what the broker throws.
	 * @param array<string, array{0: int, 1: string}>                        $anonymous URL => answer; others throw.
	 *
	 * @return GitHubRepoReader
	 */
	private function reader(array|RuntimeException|null $broker = null, array $anonymous = []): GitHubRepoReader {
		return new GitHubRepoReader(
			transport: $this->transport(broker: $broker, anonymous: $anonymous),
			extractor: new GitHubArchiveExtractor(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A transport over a broker and an anonymous client that answer per path.
	 *
	 * @param array<string, array{0: int, 1: string}>|RuntimeException|null $broker    Path => answer, or what the broker throws.
	 * @param array<string, array{0: int, 1: string}>                        $anonymous URL => answer; others throw.
	 *
	 * @return FederatedConfigTransport
	 */
	private function transport(array|RuntimeException|null $broker, array $anonymous): FederatedConfigTransport {
		$brokerService = $this->createMock(CredentialBrokerService::class);
		$brokerService->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path, array $headers = [], ?string $body = null, ?string $actingUserId = null) use ($broker): array {
				$this->brokered[] = [$path, $appId, $actingUserId];
				if ($broker instanceof RuntimeException || isset($broker[$path]) === false) {
					throw ($broker instanceof RuntimeException ? $broker : new RuntimeException('no answer for ' . $path));
				}

				return ['status' => $broker[$path][0], 'body' => $broker[$path][1]];
			}
		);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($anonymous): IResponse {
				$this->anonymous[] = $url;
				if (isset($anonymous[$url]) === false) {
					throw new RuntimeException('unreachable');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($anonymous[$url][0]);
				$response->method('getBody')->willReturn($anonymous[$url][1]);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new FederatedConfigTransport($brokerService, $this->createMock(BundleSigner::class), $clients);
	}
}

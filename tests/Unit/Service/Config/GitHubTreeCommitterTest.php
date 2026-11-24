<?php

/**
 * Publishing many files as one commit on the branch tip (G4).
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

use OCA\OpenRegister\Service\Config\BrokeredPublishTarget;
use OCA\OpenRegister\Service\Config\BrokerIdentity;
use OCA\OpenRegister\Service\Config\BundleSigner;
use OCA\OpenRegister\Service\Config\ConfigPublishTarget;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\FederatedConfigTransport;
use OCA\OpenRegister\Service\Config\GitHubTreeCommitter;
use OCA\OpenRegister\Service\Config\RepoFilesPublish;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(GitHubTreeCommitter::class)]
#[CoversClass(RepoFilesPublish::class)]
#[CoversClass(FederatedConfigService::class)]
#[CoversClass(BrokeredPublishTarget::class)]
#[CoversClass(ConfigPublishTarget::class)]
#[UsesClass(BrokerIdentity::class)]
#[UsesClass(FederatedConfigTransport::class)]
final class GitHubTreeCommitterTest extends TestCase {

	private const REPO = '/repos/acme/pack';

	/**
	 * The calls made: "METHOD path" => decoded body; plus the identity of each.
	 *
	 * @var list<array{call: string, body: mixed, app: string, user: string|null}>
	 */
	private array $calls = [];

	/**
	 * Answers by "METHOD path"; a missing one answers 404.
	 *
	 * @var array<string, array{0: int, 1: mixed}>
	 */
	private array $answers = [];

	protected function setUp(): void {
		$this->answers = [
			'GET ' . self::REPO . '/git/ref/heads/main' => [200, ['object' => ['sha' => 'tip1']]],
			'GET ' . self::REPO . '/git/commits/tip1' => [200, ['tree' => ['sha' => 'tree0']]],
			'POST ' . self::REPO . '/git/blobs' => [201, ['sha' => 'blob']],
			'POST ' . self::REPO . '/git/trees' => [201, ['sha' => 'tree1']],
			'POST ' . self::REPO . '/git/commits' => [201, ['sha' => 'commit1']],
			'PATCH ' . self::REPO . '/git/refs/heads/main' => [200, ['object' => ['sha' => 'commit1']]],
		];
	}

	public function testFilesAreCommittedOnTheTipsTreeAndTheBranchMovesToTheNewCommit(): void {
		$written = $this->committer()->commit(destination: $this->destination(), files: ['skills/b.md' => 'B', 'hermiq-skills.json' => '{}'], message: 'publish');

		self::assertSame(['commit' => 'commit1', 'files' => 2], $written);
		self::assertSame(
			[
				'GET ' . self::REPO . '/git/ref/heads/main',
				'GET ' . self::REPO . '/git/commits/tip1',
				'POST ' . self::REPO . '/git/blobs',
				'POST ' . self::REPO . '/git/blobs',
				'POST ' . self::REPO . '/git/trees',
				'POST ' . self::REPO . '/git/commits',
				'PATCH ' . self::REPO . '/git/refs/heads/main',
			],
			array_column($this->calls, 'call')
		);
		self::assertSame(['content' => base64_encode('{}'), 'encoding' => 'base64'], $this->calls[2]['body']);
		self::assertSame(
			['tree' => [
				['path' => 'hermiq-skills.json', 'mode' => '100644', 'type' => 'blob', 'sha' => 'blob'],
				['path' => 'skills/b.md', 'mode' => '100644', 'type' => 'blob', 'sha' => 'blob'],
			], 'base_tree' => 'tree0'],
			$this->calls[4]['body']
		);
		self::assertSame(['message' => 'publish', 'tree' => 'tree1', 'parents' => ['tip1']], $this->calls[5]['body']);
		self::assertSame(['sha' => 'commit1'], $this->calls[6]['body']);
		self::assertSame([['hermiq', 'alice']], array_values(array_unique(array_map(static fn (array $c): array => [$c['app'], $c['user']], $this->calls), SORT_REGULAR)));
	}

	public function testABlobWithoutAShaFailsOnItsPathBeforeAnyTreeIsBuilt(): void {
		$this->answers['POST ' . self::REPO . '/git/blobs'] = [201, ['url' => 'no sha']];

		try {
			$this->committer()->commit(destination: $this->destination(), files: ['a.md' => 'A'], message: 'publish');
			self::fail('A blob without a sha must stop the publish.');
		} catch (RuntimeException $e) {
			self::assertSame('GitHub publish: the blob upload for "a.md" returned no sha.', $e->getMessage());
		}

		self::assertNotContains('POST ' . self::REPO . '/git/trees', array_column($this->calls, 'call'));
	}

	public function testPathsThatLeaveTheRepositoryAreSkippedAndNothingLeftIsRefused(): void {
		$written = $this->committer()->commit(destination: $this->destination(), files: ['../x' => 'X', '/abs' => 'Y', 'ok.md' => 'Z'], message: 'm');
		self::assertSame(1, $written['files']);

		$this->calls = [];
		try {
			$this->committer()->commit(destination: $this->destination(), files: ['a/../b' => 'X', 'c\\d' => 'Y'], message: 'm');
			self::fail('No publishable file must be refused.');
		} catch (RuntimeException $e) {
			self::assertSame('GitHub publish: no file with a publishable path.', $e->getMessage());
		}

		self::assertSame([], $this->calls);
	}

	public function testABranchMoveGitHubRefusesFailsThePublish(): void {
		$this->answers['PATCH ' . self::REPO . '/git/refs/heads/main'] = [422, ['message' => 'not a fast forward']];

		$this->expectExceptionMessage('GitHub publish: PATCH ' . self::REPO . '/git/refs/heads/main failed (422).');
		$this->committer()->commit(destination: $this->destination(), files: ['a.md' => 'A'], message: 'm');
	}

	public function testAPublishUpdatesAnExistingRepositoryAndKeepsItsTopics(): void {
		$this->answers['GET ' . self::REPO] = [200, ['full_name' => 'acme/pack']];
		$this->answers['GET ' . self::REPO . '/topics'] = [200, ['names' => ['openbuild-app']]];
		$this->answers['PUT ' . self::REPO . '/topics'] = [200, []];

		$answer = $this->service()->publishFiles(request: $this->request(topics: ['hermiq-skill-bundle']));

		self::assertSame(['published' => true, 'repo' => 'acme/pack', 'branch' => 'main', 'commit' => 'commit1', 'files' => 1], $answer);
		$calls = array_column($this->calls, 'call');
		self::assertNotContains('POST /orgs/acme/repos', $calls);
		self::assertSame(['names' => ['hermiq-skill-bundle', 'openbuild-app']], $this->calls[array_search('PUT ' . self::REPO . '/topics', $calls, true)]['body']);
	}

	public function testANewOnlyPublishRefusesAnExistingRepositoryWithoutWritingIt(): void {
		$this->answers['GET ' . self::REPO] = [200, ['full_name' => 'acme/pack']];

		try {
			$this->service()->publishFiles(request: $this->request()->onlyIntoANewRepository());
			self::fail('An existing repository must be refused.');
		} catch (RuntimeException $e) {
			self::assertSame('The repository acme/pack already exists.', $e->getMessage());
		}

		self::assertSame(['GET ' . self::REPO], array_column($this->calls, 'call'));
	}

	public function testANewOnlyPublishThatCannotCreateTheRepositoryWritesNothing(): void {
		$target = (new ConfigPublishTarget(repo: 'acme/pack', path: '', private: true))->withoutUserNamespaceFallback();

		try {
			$this->service()->publishFiles(request: $this->request(target: $target)->onlyIntoANewRepository());
			self::fail('A repository that cannot be created must stop the publish.');
		} catch (RuntimeException $e) {
			self::assertSame('The repository acme/pack could not be created.', $e->getMessage());
		}

		self::assertSame(['GET ' . self::REPO, 'POST /orgs/acme/repos'], array_column($this->calls, 'call'));
	}

	public function testACreatedRepositoryGetsTheChosenVisibilityAndDescription(): void {
		$this->answers['POST /orgs/acme/repos'] = [201, ['full_name' => 'acme/pack']];
		$target = (new ConfigPublishTarget(repo: 'acme/pack', path: '', private: true))->withDescription(' Agent skills for Hermiq ');

		$this->service()->publishFiles(request: $this->request(target: $target)->onlyIntoANewRepository());

		$calls = array_column($this->calls, 'call');
		self::assertSame(['name' => 'pack', 'private' => true, 'auto_init' => true, 'description' => 'Agent skills for Hermiq'], $this->calls[array_search('POST /orgs/acme/repos', $calls, true)]['body']);
		self::assertSame('{"name":"pack","private":false,"auto_init":true}', (new BrokeredPublishTarget(target: new ConfigPublishTarget(repo: 'acme/pack', path: 'x'), credentialId: 'c'))->createBody());
	}

	/**
	 * A publish request for acme/pack as hermiq and alice.
	 *
	 * @param ConfigPublishTarget|null $target The target; acme/pack on main when null.
	 * @param array<int, string>       $topics Topics to add.
	 *
	 * @return RepoFilesPublish
	 */
	private function request(?ConfigPublishTarget $target = null, array $topics = []): RepoFilesPublish {
		return new RepoFilesPublish(
			target: ($target ?? new ConfigPublishTarget(repo: 'acme/pack', path: '')),
			files: ['hermiq-skill.md' => '# S'],
			message: 'chore: publish',
			credentialId: 'cred-1',
			identity: BrokerIdentity::of(appId: 'hermiq', actingUserId: 'alice'),
			topics: $topics
		);
	}

	private function destination(): BrokeredPublishTarget {
		return new BrokeredPublishTarget(target: new ConfigPublishTarget(repo: 'acme/pack', path: ''), credentialId: 'cred-1', identity: BrokerIdentity::of(appId: 'hermiq', actingUserId: 'alice'));
	}

	private function committer(): GitHubTreeCommitter {
		return new GitHubTreeCommitter(transport: $this->transport(), logger: $this->createMock(LoggerInterface::class));
	}

	private function service(): FederatedConfigService {
		return new FederatedConfigService(
			$this->transport(),
			$this->createMock(ShareableConfigTypeRegistry::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A transport whose broker answers from $this->answers and records every call.
	 *
	 * @return FederatedConfigTransport
	 */
	private function transport(): FederatedConfigTransport {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path, array $headers = [], ?string $body = null, ?string $actingUserId = null): array {
				$call = $method . ' ' . $path;
				$this->calls[] = ['call' => $call, 'body' => ($body === null ? null : json_decode($body, true)), 'app' => $appId, 'user' => $actingUserId];
				[$status, $answer] = ($this->answers[$call] ?? [404, ['message' => 'Not Found']]);

				return ['status' => $status, 'body' => (string)json_encode($answer)];
			}
		);

		return new FederatedConfigTransport($broker, $this->createMock(BundleSigner::class), $this->createMock(IClientService::class));
	}
}

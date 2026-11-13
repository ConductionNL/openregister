<?php

/**
 * DeclaredProviderResolverTest — discovery of app declarations from disk and the
 * admission state each one resolves to: the narrowing lane, the approval lane with
 * its digest pinning, the administrator decisions, and the fail-closed cases.
 *
 * The declarations are real files in a temporary app tree, and the reviewed
 * catalogue is the one this repository ships.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Credential\DeclarationValidator;
use OCA\OpenRegister\Service\Credential\DeclaredProviderApprovals;
use OCA\OpenRegister\Service\Credential\DeclaredProviderLoader;
use OCA\OpenRegister\Service\Credential\DeclaredProviderResolver;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\DeclaredProviderResolver
 * @covers \OCA\OpenRegister\Service\Credential\DeclaredProviderLoader
 * @covers \OCA\OpenRegister\Service\Credential\DeclaredProviderApprovals
 * @uses   \OCA\OpenRegister\Service\Credential\DeclarationValidator
 * @uses   \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 */
class DeclaredProviderResolverTest extends TestCase {

	private string $appsRoot;

	/** @var array<int, string> */
	private array $installed = [];

	/** @var array<int, array<string, mixed>> Approval records the register holds. */
	private array $decisions = [];

	private MockObject&ObjectOperationsInterface $objects;

	private MockObject&LoggerInterface $logger;

	private DeclarationValidator $validator;

	protected function setUp(): void {
		$this->appsRoot = sys_get_temp_dir() . '/or-declared-' . bin2hex(random_bytes(6));
		mkdir($this->appsRoot);
		$this->validator = new DeclarationValidator();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, array $filters): array {
				$this->assertSame('credential-broker', $registerSlug);
				$this->assertSame('credentialproviderapproval', $schemaSlug);
				$rows = [];
				foreach ($this->decisions as $decision) {
					$object = new ObjectEntity();
					$object->setObject($decision);
					$rows[] = $object;
				}

				return $rows;
			}
		);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->appsRoot));
	}

	/**
	 * Ship a declaration file in an installed app.
	 *
	 * @param string $appId     The app.
	 * @param mixed  $providers The `providers` map, or a raw string to write as is.
	 */
	private function declare(string $appId, mixed $providers): void {
		$dir = $this->appsRoot . '/' . $appId . '/lib/Settings';
		mkdir($dir, 0777, true);
		$body = $providers;
		if (is_string($providers) === false) {
			$body = (string)json_encode(['providers' => $providers]);
		}

		file_put_contents($dir . '/credential-providers.json', $body);
		$this->installed[] = $appId;
	}

	private function resolver(): DeclaredProviderResolver {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);
		$appManager->method('getAppPath')->willReturnCallback(
			fn (string $appId): string => $appId === 'openregister' ? dirname(__DIR__, 4) : $this->appsRoot . '/' . $appId
		);
		$catalogue = new ProviderCatalogue($appManager, $this->createMock(LoggerInterface::class));

		return new DeclaredProviderResolver(
			loader: new DeclaredProviderLoader($appManager, $catalogue, $this->validator, $this->logger),
			approvals: new DeclaredProviderApprovals($this->objects, $this->logger),
			validator: $this->validator
		);
	}

	/**
	 * A novel Codeberg declaration of an app.
	 *
	 * @param string                             $appId The app.
	 * @param array<int, array<string, string>>  $rules The rules.
	 *
	 * @return array<string, mixed>
	 */
	private function codeberg(string $appId, array $rules = [['method' => 'GET', 'pathPattern' => '/repos/*']]): array {
		return [
			'identifier' => $appId . ':codeberg',
			'title' => 'Codeberg',
			'baseUrl' => 'https://codeberg.org/api/v1',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => $rules,
		];
	}

	private function decide(array $entry, string $status, string $at, ?string $digest = null): void {
		$this->decisions[] = [
			'providerIdentifier' => $entry['identifier'],
			'declaringApp' => explode(':', $entry['identifier'])[0],
			'declarationDigest' => ($digest ?? $this->validator->digest($entry)),
			'status' => $status,
			'decidedBy' => 'admin',
			'decidedAt' => $at,
		];
	}

	public function testANovelDeclarationIsPendingAndYieldsNoEntry(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg('alpha')]);

		$this->assertSame(
			['status' => 'pending', 'origin' => 'alpha', 'lane' => 'approval', 'entry' => null],
			$this->resolver()->resolve('alpha:codeberg')
		);
	}

	public function testAnApprovalOnTheCurrentDigestAdmitsAProxyEntryInTheCatalogueShape(): void {
		$entry = $this->codeberg('alpha');
		$entry['$why'] = 'Our forge.';
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');

		$state = $this->resolver()->resolve('alpha:codeberg');

		$this->assertSame('admitted', $state['status']);
		$this->assertSame(
			[
				'identifier' => 'alpha:codeberg',
				'title' => 'Codeberg',
				'baseUrl' => 'https://codeberg.org/api/v1',
				'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
				'allowRules' => [['method' => 'GET', 'pathPattern' => '/repos/*']],
				'inject_only' => false,
				'origin' => 'alpha',
			],
			$state['entry']
		);
		$this->assertSame($state['entry'], $this->resolver()->admittedEntry('alpha:codeberg'));
	}

	public function testEditingAnApprovedDeclarationReturnsItToPending(): void {
		$approved = $this->codeberg('alpha');
		$this->decide($approved, 'approved', '2026-11-01T10:00:00+00:00');
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg('alpha', [
			['method' => 'GET', 'pathPattern' => '/repos/*'],
			['method' => 'DELETE', 'pathPattern' => '/repos/*'],
		])]);

		$state = $this->resolver()->resolve('alpha:codeberg');

		$this->assertSame('pending', $state['status']);
		$this->assertNull($state['entry']);
	}

	public function testAnUnrelatedEntryDoesNotDisturbAnApproval(): void {
		$approved = $this->codeberg('alpha');
		$this->decide($approved, 'approved', '2026-11-01T10:00:00+00:00');
		$other = $this->codeberg('alpha');
		$other['identifier'] = 'alpha:forgejo';
		$other['baseUrl'] = 'https://git.gemeente.example/api/v1';
		$this->declare('alpha', ['alpha:codeberg' => $approved, 'alpha:forgejo' => $other]);

		$resolver = $this->resolver();

		$this->assertSame('admitted', $resolver->resolve('alpha:codeberg')['status']);
		$this->assertSame('pending', $resolver->resolve('alpha:forgejo')['status']);
	}

	public function testTheLatestDecisionOnTheDigestWins(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');
		$this->decide($entry, 'revoked', '2026-11-02T10:00:00+00:00');
		$this->assertSame('revoked', $this->resolver()->resolve('alpha:codeberg')['status']);

		$this->decide($entry, 'approved', '2026-11-03T10:00:00+00:00');
		$this->assertSame('admitted', $this->resolver()->resolve('alpha:codeberg')['status']);
	}

	public function testARejectionStaysRejectedForTheSameDigest(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'rejected', '2026-11-01T10:00:00+00:00');

		$state = $this->resolver()->resolve('alpha:codeberg');

		$this->assertSame('rejected', $state['status']);
		$this->assertNull($state['entry']);
	}

	public function testASeedRowWithTheNilDigestAdmitsNothing(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00', 'sha256:' . str_repeat('0', 64));
		$this->decide($entry, 'mystery', '2026-11-02T10:00:00+00:00');

		$this->assertSame('pending', $this->resolver()->resolve('alpha:codeberg')['status']);
	}

	public function testANarrowingDeclarationIsAdmittedWithoutAnyDecision(): void {
		$this->declare('alpha', ['alpha:github-read' => [
			'identifier' => 'alpha:github-read',
			'title' => 'GitHub, read only',
			'extends' => 'github',
			'baseUrl' => 'https://api.github.com',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => [['method' => 'GET', 'pathPattern' => '/repos/*']],
		]]);
		$this->objects->expects($this->never())->method('searchObjectsBySlug');

		$state = $this->resolver()->resolve('alpha:github-read');

		$this->assertSame('admitted', $state['status']);
		$this->assertSame('narrowing', $state['lane']);
		$this->assertSame('https://api.github.com', $state['entry']['baseUrl']);
		$this->assertArrayNotHasKey('extends', $state['entry']);
	}

	public function testOneInvalidEntryMakesTheWholeFileAdmitNothing(): void {
		$entry = $this->codeberg('alpha');
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');
		$bad = $this->codeberg('alpha');
		$bad['identifier'] = 'alpha:cli';
		$bad['inject_only'] = true;
		$this->declare('alpha', ['alpha:codeberg' => $entry, 'alpha:cli' => $bad]);
		$this->logger->expects($this->once())->method('warning')
			->with('[DeclaredProviderLoader] declaration of app "alpha" admits nothing: entry "alpha:cli": a declared provider cannot be inject_only');

		$resolver = $this->resolver();

		$this->assertSame(['status' => 'invalid', 'origin' => 'alpha', 'lane' => '', 'entry' => null], $resolver->resolve('alpha:codeberg'));
		$this->assertSame('invalid', $resolver->resolve('alpha:cli')['status']);
	}

	public function testAWideningExtendsIsInvalidNotPending(): void {
		$this->declare('alpha', ['alpha:github-write' => [
			'identifier' => 'alpha:github-write',
			'title' => 'GitHub',
			'extends' => 'github',
			'baseUrl' => 'https://api.github.com',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => [['method' => 'DELETE', 'pathPattern' => '/repos/*']],
		]]);

		$this->assertSame('invalid', $this->resolver()->resolve('alpha:github-write')['status']);
	}

	public function testAnUnreadableFileContributesNothing(): void {
		$this->declare('alpha', '{not json');
		$this->logger->expects($this->once())->method('warning')
			->with('[DeclaredProviderLoader] declaration of app "alpha" is not readable provider JSON');

		$this->assertSame('unknown', $this->resolver()->resolve('alpha:codeberg')['status']);
	}

	public function testADisabledAppsDeclarationIsUnknown(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');
		$this->installed = [];

		$this->assertSame(['status' => 'unknown', 'origin' => '', 'lane' => '', 'entry' => null], $this->resolver()->resolve('alpha:codeberg'));

		// Re-enabled with the declaration unchanged: the kept approval applies again.
		$this->installed = ['alpha'];
		$this->assertSame('admitted', $this->resolver()->resolve('alpha:codeberg')['status']);
	}

	public function testOpenRegistersOwnCatalogueIsNeverADeclaration(): void {
		$this->installed = ['openregister'];
		$resolver = $this->resolver();

		$this->assertSame('unknown', $resolver->resolve('openregister:github')['status']);
		$this->assertSame('unknown', $resolver->resolve('github')['status']);
		$this->assertFalse($resolver->isDeclaredIdentifier('github'));
		$this->assertTrue($resolver->isDeclaredIdentifier('alpha:codeberg'));
	}

	public function testTwoAppsMayDeclareTheSameLocalName(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg('alpha')]);
		$beta = $this->codeberg('beta');
		$this->declare('beta', ['beta:codeberg' => $beta]);
		$this->decide($beta, 'approved', '2026-11-01T10:00:00+00:00');

		$resolver = $this->resolver();

		$this->assertSame('pending', $resolver->resolve('alpha:codeberg')['status']);
		$this->assertSame(['admitted', 'beta'], [$resolver->resolve('beta:codeberg')['status'], $resolver->resolve('beta:codeberg')['origin']]);
	}

	public function testAFailedApprovalReadLeavesTheDeclarationPending(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjectsBySlug')->willThrowException(new \RuntimeException('register missing'));
		$approvals = new DeclaredProviderApprovals($objects, $this->logger);
		$this->logger->expects($this->once())->method('warning')
			->with('[DeclaredProviderApprovals] approval records could not be read for "alpha:codeberg"');

		$this->assertSame([], $approvals->decisionsFor('alpha:codeberg'));
		$this->assertSame([], $approvals->decisionsFor('alpha:codeberg'));
	}

	public function testOnlyRecordsForTheExactIdentifierCount(): void {
		$entry = $this->codeberg('alpha');
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		$this->decide($entry, 'approved', '2026-11-01T10:00:00+00:00');
		$this->decisions[0]['providerIdentifier'] = 'alpha:codeberg-legacy';

		$this->assertSame('pending', $this->resolver()->resolve('alpha:codeberg')['status']);
	}
}

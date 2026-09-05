<?php

/**
 * OAuth2TokenRefreshJobTest — the daily sweep that keeps idle connections alive.
 *
 * The sweep's whole value is for connections nothing calls, so the properties worth
 * pinning are about SELECTION and RESILIENCE rather than about refreshing itself,
 * which OAuth2RefreshServiceTest already covers: only active token sets are touched,
 * a credential of another kind is never opened, one bad provider does not end the
 * run, and a dead grant is not counted as a sweep failure because it was already
 * recorded and notified where it happened.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
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
 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-daily-job-refreshes-active-token-sets-before-they-expire
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\OAuth2TokenRefreshJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialRelinkRequiredException;
use OCA\OpenRegister\Service\Credential\OAuth2RefreshService;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\OAuth2TokenRefreshJob
 */
class OAuth2TokenRefreshJobTest extends TestCase {
	/** @var array<int, string> Credential ids the sweep actually asked to refresh. */
	private array $swept = [];

	/** @var string|null Credential id whose refresh throws a transport failure. */
	private ?string $failOn = null;

	/** @var string|null Credential id whose refresh reports a dead grant. */
	private ?string $relinkOn = null;

	protected function setUp(): void {
		$this->swept = [];
		$this->failOn = null;
		$this->relinkOn = null;
	}

	public function testOnlyActiveTokenSetCredentialsAreSwept(): void {
		$job = $this->makeJob(
			credentials: [
				'active-token-set' => ['provider' => 'x', 'kind' => 'oauth2-token-set', 'status' => 'active'],
				'a-classic-secret' => ['provider' => 'github'],
				'already-relinked' => ['provider' => 'x', 'kind' => 'oauth2-token-set', 'status' => 'relink_needed'],
				'still-pending' => ['provider' => 'x', 'kind' => 'oauth2-token-set', 'status' => 'pending'],
			]
		);

		$this->runJob(job: $job);

		$this->assertSame(['active-token-set'], $this->swept);
	}

	public function testOneFailingCredentialDoesNotStopTheSweep(): void {
		$job = $this->makeJob(
			credentials: [
				'first' => ['provider' => 'x', 'kind' => 'oauth2-token-set', 'status' => 'active'],
				'broken' => ['provider' => 'x', 'kind' => 'oauth2-token-set', 'status' => 'active'],
				'last' => ['provider' => 'x', 'kind' => 'oauth2-token-set', 'status' => 'active'],
			],
			failOn: 'broken'
		);

		$this->runJob(job: $job);

		$this->assertSame(['first', 'broken', 'last'], $this->swept, 'every credential must still be attempted');
	}

	public function testADeadGrantIsSurvivedRatherThanCountedAsASweepFailure(): void {
		$job = $this->makeJob(
			credentials: [
				'revoked' => ['provider' => 'x', 'kind' => 'oauth2-token-set', 'status' => 'active'],
				'healthy' => ['provider' => 'x', 'kind' => 'oauth2-token-set', 'status' => 'active'],
			],
			relinkOn: 'revoked'
		);

		$this->runJob(job: $job);

		$this->assertSame(['revoked', 'healthy'], $this->swept);
	}

	public function testACredentialWhoseProviderLeftTheCatalogueIsSkipped(): void {
		$job = $this->makeJob(
			credentials: ['orphan' => ['provider' => 'a-provider-that-was-removed', 'kind' => 'oauth2-token-set', 'status' => 'active']],
			knownProvider: null
		);

		$this->runJob(job: $job);

		$this->assertSame([], $this->swept, 'a credential the catalogue no longer knows must not be opened');
	}

	/**
	 * Invoke the job's protected run().
	 *
	 * @param OAuth2TokenRefreshJob $job The job under test.
	 *
	 * @return void
	 */
	private function runJob(OAuth2TokenRefreshJob $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}

	/**
	 * Build the job with a scripted credential list and refresh service.
	 *
	 * @param array<string, array<string, mixed>> $credentials Credential id to property bag.
	 * @param string|null $failOn A credential id whose refresh throws.
	 * @param string|null $relinkOn A credential id whose refresh reports a dead grant.
	 * @param array<string, mixed>|null $knownProvider The catalogue entry every provider resolves to.
	 *
	 * @return OAuth2TokenRefreshJob The job.
	 */
	private function makeJob(
		array $credentials,
		?string $failOn = null,
		?string $relinkOn = null,
		?array $knownProvider = ['identifier' => 'x', 'kind' => 'oauth2-token-set'],
	): OAuth2TokenRefreshJob {
		$entities = [];
		foreach ($credentials as $uuid => $data) {
			$entity = new ObjectEntity();
			$entity->setUuid((string)$uuid);
			$entity->setObject($data);
			$entities[] = $entity;
		}

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($entities);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($knownProvider);

		$refresh = $this->createMock(OAuth2RefreshService::class);
		$refresh->method('sweepCredential')->willReturnCallback(
			function (array $credential, array $provider, string $credentialId): bool {
				$this->swept[] = $credentialId;
				if ($credentialId === $this->failOn) {
					throw new RuntimeException('provider is down');
				}

				if ($credentialId === $this->relinkOn) {
					throw new CredentialRelinkRequiredException(message: 'refresh refused: invalid_grant');
				}

				return true;
			}
		);

		$this->failOn = $failOn;
		$this->relinkOn = $relinkOn;

		return new OAuth2TokenRefreshJob(
			$this->createMock(ITimeFactory::class),
			$objectService,
			$catalogue,
			$refresh,
			$this->createMock(LoggerInterface::class)
		);
	}
}

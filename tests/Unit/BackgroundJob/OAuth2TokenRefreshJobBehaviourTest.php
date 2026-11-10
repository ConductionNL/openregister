<?php

/**
 * Behaviour test for OAuth2TokenRefreshJob and TenantDeprovisionJob.
 *
 * Token refresh: which credentials are swept (active token sets with a uuid
 * whose provider is a token-set provider), how refreshed, relink and failed
 * credentials are counted, and that a failing listing stops the run. Tenant
 * deprovisioning: every deprovisioning tenant is archived, one failure does not
 * stop the rest, and a failing query archives nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\JobRuntime;
use Exception;
use OCA\OpenRegister\BackgroundJob\OAuth2TokenRefreshJob;
use OCA\OpenRegister\BackgroundJob\Support\OAuth2TokenRefreshServices;
use OCA\OpenRegister\BackgroundJob\TenantDeprovisionJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialRelinkRequiredException;
use OCA\OpenRegister\Service\Credential\OAuth2CredentialSweepInterface;
use OCA\OpenRegister\Service\Credential\ProviderCatalogueInterface;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class OAuth2TokenRefreshJobBehaviourTest extends TestCase {

	/** @var list<array{string, string}> Every log line as [level, message]. */
	private array $logged = [];

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message) use ($level): void {
				$this->logged[] = [$level, $message];
			});
		}

		return $logger;
	}

	private static function runJob(object $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}

	private function credential(?string $uuid, array $data): ObjectEntity {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($data);
		$object->method('getUuid')->willReturn($uuid);
		return $object;
	}

	private function objects(array|Exception $found): ObjectOperationsInterface&MockObject {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->with(CredentialBrokerService::REGISTER)->willReturnSelf();
		$objects->method('setSchema')->with(CredentialBrokerService::SCHEMA)->willReturnSelf();
		if ($found instanceof Exception) {
			$objects->method('findAll')->willThrowException($found);
			return $objects;
		}

		$objects->method('findAll')->with([], false, false)->willReturn($found);
		return $objects;
	}

	private function catalogue(): ProviderCatalogueInterface {
		$catalogue = $this->createMock(ProviderCatalogueInterface::class);
		$catalogue->method('get')->willReturnCallback(static fn (string $id): ?array => match ($id) {
			'ms' => ['id' => 'ms', 'kind' => 'oauth2-token-set'],
			'key' => ['id' => 'key', 'kind' => 'api-key'],
			default => null,
		});
		return $catalogue;
	}

	private function job(ObjectOperationsInterface $objects, OAuth2CredentialSweepInterface $sweep): OAuth2TokenRefreshJob {
		return new OAuth2TokenRefreshJob(
			new JobRuntime($this->createMock(ITimeFactory::class), $this->logger()),
			new OAuth2TokenRefreshServices($objects, $this->catalogue(), $sweep)
		);
	}

	private static function tokenSet(string $provider, string $status = 'active', ?string $scope = null): array {
		$data = ['kind' => 'oauth2-token-set', 'status' => $status, 'provider' => $provider];
		if ($scope !== null) {
			$data['scope'] = $scope;
		}

		return $data;
	}

	public function testTheJobRunsDaily(): void {
		$job = $this->job($this->objects([]), $this->createMock(OAuth2CredentialSweepInterface::class));
		$this->assertSame(86400, $job->getInterval());
	}

	public function testOnlyActiveTokenSetsOfTokenSetProvidersAreSwept(): void {
		$found = [
			$this->credential('c1', self::tokenSet('ms', 'active', 'shared')),
			$this->credential('c2', self::tokenSet('ms', 'revoked')),
			$this->credential('c3', ['kind' => 'api-key', 'status' => 'active', 'provider' => 'ms']),
			$this->credential('', self::tokenSet('ms')),
			$this->credential('c5', self::tokenSet('key')),
			$this->credential('c6', self::tokenSet('unknown')),
			$this->credential('c7', self::tokenSet('ms')),
		];
		$swept = [];
		$sweep = $this->createMock(OAuth2CredentialSweepInterface::class);
		$sweep->method('sweepCredential')->willReturnCallback(
			static function (array $credential, array $provider, string $credentialId, string $scope) use (&$swept): bool {
				$swept[] = [$credentialId, $provider['id'], $scope];
				return $credentialId === 'c1';
			}
		);

		self::runJob($this->job($this->objects($found), $sweep));

		$this->assertSame([['c1', 'ms', 'shared'], ['c7', 'ms', 'personal']], $swept);
		$this->assertContains(['info', '[OAuth2TokenRefreshJob] swept 4 connection(s), refreshed 1, failed 0'], $this->logged);
	}

	public function testARelinkIsNotAFailureButAnErrorIs(): void {
		$found = [
			$this->credential('c1', self::tokenSet('ms')),
			$this->credential('c2', self::tokenSet('ms')),
			$this->credential('c3', self::tokenSet('ms')),
		];
		$sweep = $this->createMock(OAuth2CredentialSweepInterface::class);
		$sweep->method('sweepCredential')->willReturnCallback(
			static function (array $credential, array $provider, string $credentialId): bool {
				if ($credentialId === 'c1') {
					throw new CredentialRelinkRequiredException('refresh refused');
				}

				if ($credentialId === 'c2') {
					throw new RuntimeException('timeout');
				}

				return true;
			}
		);

		self::runJob($this->job($this->objects($found), $sweep));

		$this->assertContains(['info', '[OAuth2TokenRefreshJob] credential c1 needs re-authorisation'], $this->logged);
		$this->assertContains(['warning', '[OAuth2TokenRefreshJob] refresh failed for c2: timeout'], $this->logged);
		$this->assertContains(['info', '[OAuth2TokenRefreshJob] swept 3 connection(s), refreshed 1, failed 1'], $this->logged);
	}

	public function testAFailingListingStopsTheRun(): void {
		$sweep = $this->createMock(OAuth2CredentialSweepInterface::class);
		$sweep->expects($this->never())->method('sweepCredential');
		self::runJob($this->job($this->objects(new Exception('register missing')), $sweep));
		$this->assertSame([['warning', '[OAuth2TokenRefreshJob] could not list credentials: register missing']], $this->logged);
	}

	private function deprovisionJob(OrganisationMapperInterface $mapper, TenantLifecycleServiceInterface $lifecycle): TenantDeprovisionJob {
		return new TenantDeprovisionJob(new \OCA\OpenRegister\BackgroundJob\Support\ScheduledJobRuntime($this->createMock(ITimeFactory::class), $this->createMock(\OCP\IAppConfig::class), $this->logger()), new \OCA\OpenRegister\BackgroundJob\Support\TenantRecords($mapper, $this->createMock(\OCA\OpenRegister\Db\TenantUsageMapperInterface::class), $lifecycle));
	}

	private static function tenant(string $uuid): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		return $organisation;
	}

	public function testEveryDeprovisioningTenantIsArchivedEvenWhenOneFails(): void {
		$tenants = [self::tenant('t1'), self::tenant('t2'), self::tenant('t3')];
		$mapper = $this->createMock(OrganisationMapperInterface::class);
		$mapper->expects($this->once())->method('findLocalTenants')->with(50, 0, ['status' => 'deprovisioning'])->willReturn($tenants);
		$archived = [];
		$lifecycle = $this->createMock(TenantLifecycleServiceInterface::class);
		$lifecycle->method('archive')->willReturnCallback(static function (Organisation $o) use (&$archived): Organisation {
			if ($o->getUuid() === 't2') {
				throw new Exception('storage busy');
			}

			$archived[] = $o->getUuid();
			return $o;
		});

		$job = $this->deprovisionJob($mapper, $lifecycle);
		self::runJob($job);

		$this->assertSame(3600, $job->getInterval());
		$this->assertSame(['t1', 't3'], $archived);
		$this->assertContains(['error', '[TenantDeprovisionJob] Failed to archive organisation'], $this->logged);
		$this->assertContains(['info', '[TenantDeprovisionJob] Completed, processed 3 organisations'], $this->logged);
	}

	public function testAFailingQueryArchivesNothing(): void {
		$mapper = $this->createMock(OrganisationMapperInterface::class);
		$mapper->method('findLocalTenants')->willThrowException(new Exception('db down'));
		$lifecycle = $this->createMock(TenantLifecycleServiceInterface::class);
		$lifecycle->expects($this->never())->method('archive');

		self::runJob($this->deprovisionJob($mapper, $lifecycle));
		$this->assertContains(['error', '[TenantDeprovisionJob] Failed to query deprovisioning organisations'], $this->logged);
		$this->assertNotContains(['info', '[TenantDeprovisionJob] Completed, processed 0 organisations'], $this->logged);
	}
}

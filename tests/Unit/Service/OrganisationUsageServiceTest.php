<?php

/**
 * Unit tests for OrganisationUsageService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/organisation-usage-measured/specs/organisation-usage-measured/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationUsageService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What the usage block claims, and what it refuses to claim: a figure the
 * service cannot measure is null, never 0.
 */
class OrganisationUsageServiceTest extends TestCase {
	private MagicMapper&MockObject $magicMapper;
	private FileMapper&MockObject $fileMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->fileMapper = $this->createMock(FileMapper::class);
	}

	/**
	 * A service whose APCu is replaced by the given counters.
	 *
	 * @param bool                $available Whether APCu is enabled.
	 * @param array<string, int>  $counters  The counters by key.
	 *
	 * @return OrganisationUsageService The service.
	 */
	private function service(bool $available, array $counters = []): OrganisationUsageService {
		return new class($this->magicMapper, $this->fileMapper, new NullLogger(), $available, $counters) extends OrganisationUsageService {
			/**
			 * @param MagicMapper        $magicMapper The magic mapper.
			 * @param FileMapper         $fileMapper  The file mapper.
			 * @param NullLogger         $logger      The logger.
			 * @param bool               $available   Whether APCu is enabled.
			 * @param array<string, int> $counters    The counters by key.
			 */
			public function __construct(
				MagicMapper $magicMapper,
				FileMapper $fileMapper,
				NullLogger $logger,
				private bool $available,
				private array $counters,
			) {
				parent::__construct($magicMapper, $fileMapper, $logger);
			}

			protected function apcuAvailable(): bool {
				return $this->available;
			}

			protected function apcuFetchInt(string $key): ?int {
				return $this->counters[$key] ?? null;
			}
		};
	}

	/**
	 * An organisation with two members, one group and the given request quota.
	 *
	 * @param int|null $requestQuota The request quota.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(?int $requestQuota = null): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->addUser('alice');
		$organisation->addUser('bob');
		$organisation->setGroups(['staff']);
		$organisation->setRequestQuota($requestQuota);
		return $organisation;
	}

	public function testUsersAndGroupsCountTheMembers(): void {
		$usage = $this->service(available: false)->measure($this->organisation());

		$this->assertSame(2, $usage['users']);
		$this->assertSame(1, $usage['groups']);
	}

	public function testStorageSumsTheFoldersOfTheOrganisationsObjects(): void {
		$this->magicMapper->expects($this->once())->method('findFolderIdsByOrganisation')
			->with('org-1')->willReturn([11, 12]);
		$this->fileMapper->expects($this->once())->method('sumSizesByFileIds')
			->with([11, 12])->willReturn(5120);

		$this->assertSame(5120, $this->service(available: false)->measure($this->organisation())['storage']);
	}

	public function testStorageThatCannotBeReadIsNullNotZero(): void {
		$this->magicMapper->method('findFolderIdsByOrganisation')->willThrowException(new Exception('db down'));

		$this->assertNull($this->service(available: false)->measure($this->organisation())['storage']);
	}

	public function testRequestsAreNullWithoutAQuotaBecauseNothingCountsThem(): void {
		$usage = $this->service(available: true, counters: ['or_quota_org-1_' . date('YmdH') => 7])->measure($this->organisation());

		$this->assertNull($usage['requests']);
	}

	public function testRequestsAreNullWithoutApcu(): void {
		$this->assertNull($this->service(available: false)->measure($this->organisation(requestQuota: 100))['requests']);
	}

	public function testRequestsReadThisHoursCounter(): void {
		$usage = $this->service(available: true, counters: ['or_quota_org-1_' . date('YmdH') => 7])
			->measure($this->organisation(requestQuota: 100));

		$this->assertSame(7, $usage['requests']);
	}

	public function testRequestsAreZeroWhenCountedAndNoneThisHour(): void {
		$usage = $this->service(available: true)->measure($this->organisation(requestQuota: 100));

		$this->assertSame(0, $usage['requests']);
	}

	public function testBandwidthIsNeverClaimed(): void {
		$this->assertNull($this->service(available: true)->measure($this->organisation(requestQuota: 100))['bandwidth']);
	}
}

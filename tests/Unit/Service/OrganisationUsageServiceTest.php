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
 * @spec openspec/specs/organisation-usage-measured/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationUsageService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What the usage block claims, and what it refuses to claim: a figure the
 * service cannot measure is null, never 0.
 */
class OrganisationUsageServiceTest extends TestCase {
	/**
	 * The magic mapper double.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $magicMapper;

	/**
	 * The file mapper double.
	 *
	 * @var FileMapper&MockObject
	 */
	private FileMapper&MockObject $fileMapper;

	/**
	 * Resolves the schema slug an object quota counts.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Create the mapper doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->magicMapper = $this->createMock(originalClassName: MagicMapper::class);
		$this->fileMapper = $this->createMock(originalClassName: FileMapper::class);
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
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
		return new class($this->magicMapper, $this->fileMapper, new NullLogger(), $available, $counters, $this->schemaMapper) extends OrganisationUsageService {
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
				SchemaMapper $schemaMapper,
			) {
				parent::__construct(magicMapper: $magicMapper, fileMapper: $fileMapper, logger: $logger, schemaMapper: $schemaMapper);
			}

			/**
			 * Whether the stand-in APCu is enabled.
			 *
			 * @return bool
			 */
			protected function apcuAvailable(): bool {
				return $this->available;
			}

			/**
			 * Read a stand-in counter.
			 *
			 * @param string $key The key.
			 *
			 * @return int|null
			 */
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

	/**
	 * Users and groups count the members.
	 *
	 * @return void
	 */
	public function testUsersAndGroupsCountTheMembers(): void {
		$usage = $this->service(available: false)->measure($this->organisation());

		$this->assertSame(expected: 2, actual: $usage['users']);
		$this->assertSame(expected: 1, actual: $usage['groups']);
	}

	/**
	 * Storage sums the folders of the organisations objects.
	 *
	 * @return void
	 */
	public function testStorageSumsTheFoldersOfTheOrganisationsObjects(): void {
		$this->magicMapper->expects($this->once())->method('findFolderIdsByOrganisation')
			->with('org-1')->willReturn([11, 12]);
		$this->fileMapper->expects($this->once())->method('sumSizesByFileIds')
			->with([11, 12])->willReturn(5120);

		$this->assertSame(expected: 5120, actual: $this->service(available: false)->measure($this->organisation())['storage']);
	}

	/**
	 * Storage that cannot be read is null not zero.
	 *
	 * @return void
	 */
	public function testStorageThatCannotBeReadIsNullNotZero(): void {
		$this->magicMapper->method('findFolderIdsByOrganisation')->willThrowException(exception: new Exception(message: 'db down'));

		$this->assertNull(actual: $this->service(available: false)->measure($this->organisation())['storage']);
	}

	/**
	 * Requests are null without a quota because nothing counts them.
	 *
	 * @return void
	 */
	public function testRequestsAreNullWithoutAQuotaBecauseNothingCountsThem(): void {
		$usage = $this->service(available: true, counters: ['or_quota_org-1_' . date('YmdH') => 7])->measure($this->organisation());

		$this->assertNull(actual: $usage['requests']);
	}

	/**
	 * Requests are null without apcu.
	 *
	 * @return void
	 */
	public function testRequestsAreNullWithoutApcu(): void {
		$this->assertNull(actual: $this->service(available: false)->measure($this->organisation(requestQuota: 100))['requests']);
	}

	/**
	 * Requests read this hours counter.
	 *
	 * @return void
	 */
	public function testRequestsReadThisHoursCounter(): void {
		$usage = $this->service(available: true, counters: ['or_quota_org-1_' . date('YmdH') => 7])
			->measure($this->organisation(requestQuota: 100));

		$this->assertSame(expected: 7, actual: $usage['requests']);
	}

	/**
	 * Requests are zero when counted and none this hour.
	 *
	 * @return void
	 */
	public function testRequestsAreZeroWhenCountedAndNoneThisHour(): void {
		$usage = $this->service(available: true)->measure($this->organisation(requestQuota: 100));

		$this->assertSame(expected: 0, actual: $usage['requests']);
	}

	/**
	 * Bandwidth is never claimed.
	 *
	 * @return void
	 */
	public function testBandwidthIsNeverClaimed(): void {
		$this->assertNull(actual: $this->service(available: true)->measure($this->organisation(requestQuota: 100))['bandwidth']);
	}

	/**
	 * The object quota counts the organisation's live objects of the schema it names, by slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function testObjectsCountTheObjectsOfTheQuotaSchema(): void {
		$organisation = $this->organisation();
		$organisation->setObjectQuota(5000);
		$organisation->setObjectQuotaSchema('case');
		$this->schemaMapper->expects($this->once())->method('findIdsBySlugs')->with(['case'])->willReturn(['case' => ['7', '9']]);
		$this->magicMapper->expects($this->once())->method('countObjectsByOrganisation')->with('org-1', [7, 9])->willReturn(1240);

		$this->assertSame(expected: 1240, actual: $this->service(available: false)->measure($organisation)['objects']);
	}

	/**
	 * Without an object quota nothing is counted and the figure is null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function testObjectsAreNullWithoutAnObjectQuota(): void {
		$this->magicMapper->expects($this->never())->method('countObjectsByOrganisation');

		$this->assertNull(actual: $this->service(available: false)->measure($this->organisation())['objects']);
	}

	/**
	 * Objects that cannot be counted are null, not zero.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function testObjectsThatCannotBeCountedAreNullNotZero(): void {
		$organisation = $this->organisation();
		$organisation->setObjectQuota(5000);
		$this->magicMapper->method('countObjectsByOrganisation')->willThrowException(exception: new Exception(message: 'db down'));

		$this->assertNull(actual: $this->service(available: false)->measure($organisation)['objects']);
	}
}

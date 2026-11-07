<?php

/**
 * Golden test pinning OrganisationUsageService's guard branches.
 *
 * Recorded before measureStorage/measureObjects/measureRequests were split:
 * an organisation without a uuid measures nothing, an object quota naming an
 * unknown schema counts 0 without querying, a quota naming none counts every
 * schema, and the request counter key is per organisation and hour.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Service\OrganisationUsageService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden outcomes of OrganisationUsageService::measure().
 */
class OrganisationUsageServiceGoldenTest extends TestCase {

	/**
	 * Keys the stand-in APCu was asked for.
	 *
	 * @var list<string>
	 */
	public array $fetched = [];

	/**
	 * Build the service with a stand-in APCu that answers every key with 3.
	 *
	 * @param MagicMapperInterface  $magic   The magic mapper.
	 * @param SchemaMapperInterface $schemas The schema mapper.
	 *
	 * @return OrganisationUsageService
	 */
	private function service(MagicMapperInterface $magic, SchemaMapperInterface $schemas): OrganisationUsageService {
		$files = $this->createMock(FileMapperInterface::class);
		$files->method('sumSizesByFileIds')->willReturn(10);
		$test = $this;
		return new class($magic, $files, new NullLogger(), $schemas, $test) extends OrganisationUsageService {
			/**
			 * Constructor.
			 *
			 * @param MagicMapperInterface             $magic   The magic mapper.
			 * @param FileMapperInterface              $files   The file mapper.
			 * @param NullLogger                       $logger  The logger.
			 * @param SchemaMapperInterface            $schemas The schema mapper.
			 * @param OrganisationUsageServiceGoldenTest $test  Records fetched keys.
			 */
			public function __construct(
				MagicMapperInterface $magic,
				FileMapperInterface $files,
				NullLogger $logger,
				SchemaMapperInterface $schemas,
				private OrganisationUsageServiceGoldenTest $test,
			) {
				parent::__construct($magic, $files, $logger, $schemas);
			}

			/**
			 * APCu is on.
			 *
			 * @return bool
			 */
			protected function apcuAvailable(): bool {
				return true;
			}

			/**
			 * Every counter reads 3.
			 *
			 * @param string $key The key.
			 *
			 * @return int|null
			 */
			protected function apcuFetchInt(string $key): ?int {
				$this->test->fetched[] = $key;
				return 3;
			}
		};
	}//end service()

	/**
	 * An organisation with both quotas set.
	 *
	 * @param string|null $uuid The uuid.
	 *
	 * @return Organisation
	 */
	private function organisation(?string $uuid): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setRequestQuota(100);
		$organisation->setObjectQuota(100);
		return $organisation;
	}//end organisation()

	/**
	 * Without a uuid nothing is measured or queried, whatever the quotas.
	 *
	 * @return void
	 */
	public function testNoUuidMeasuresNothing(): void {
		foreach ([null, ''] as $uuid) {
			$magic = $this->createMock(MagicMapperInterface::class);
			$magic->expects($this->never())->method('findFolderIdsByOrganisation');
			$magic->expects($this->never())->method('countObjectsByOrganisation');
			$usage = $this->service($magic, $this->createMock(SchemaMapperInterface::class))
				->measure($this->organisation($uuid));

			$this->assertSame(
				['storage' => null, 'bandwidth' => null, 'requests' => null, 'objects' => null, 'users' => 0, 'groups' => 0],
				$usage
			);
		}

		$this->assertSame([], $this->fetched);
	}//end testNoUuidMeasuresNothing()

	/**
	 * A quota schema slug that resolves to nothing counts 0 without counting.
	 *
	 * @return void
	 */
	public function testUnknownQuotaSchemaCountsZero(): void {
		$magic = $this->createMock(MagicMapperInterface::class);
		$magic->expects($this->never())->method('countObjectsByOrganisation');
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->once())->method('findIdsBySlugs')->with(['Case'])->willReturn(['other' => [1]]);
		$organisation = $this->organisation('org-1');
		$organisation->setObjectQuotaSchema('Case');

		$this->assertSame(0, $this->service($magic, $schemas)->measure($organisation)['objects']);
	}//end testUnknownQuotaSchemaCountsZero()

	/**
	 * The slug is matched lower-cased and the ids are passed as integers.
	 *
	 * @return void
	 */
	public function testQuotaSchemaIsMatchedLowerCase(): void {
		$magic = $this->createMock(MagicMapperInterface::class);
		$magic->expects($this->once())->method('countObjectsByOrganisation')->with('org-1', [4, 5])->willReturn(8);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findIdsBySlugs')->willReturn(['case' => ['4', 5]]);
		$organisation = $this->organisation('org-1');
		$organisation->setObjectQuotaSchema('Case');

		$this->assertSame(8, $this->service($magic, $schemas)->measure($organisation)['objects']);
	}//end testQuotaSchemaIsMatchedLowerCase()

	/**
	 * A quota naming no schema counts every schema and never resolves a slug.
	 *
	 * @return void
	 */
	public function testQuotaWithoutSchemaCountsEverySchema(): void {
		$magic = $this->createMock(MagicMapperInterface::class);
		$magic->method('findFolderIdsByOrganisation')->willReturn([1]);
		$magic->expects($this->once())->method('countObjectsByOrganisation')->with('org-1', [])->willReturn(12);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->never())->method('findIdsBySlugs');
		$usage = $this->service($magic, $schemas)->measure($this->organisation('org-1'));

		$this->assertSame(12, $usage['objects']);
		$this->assertSame(10, $usage['storage']);
		$this->assertSame(3, $usage['requests']);
		$this->assertCount(1, $this->fetched);
		$this->assertMatchesRegularExpression('/^or_quota_org-1_\d{10}$/', $this->fetched[0]);
	}//end testQuotaWithoutSchemaCountsEverySchema()
}//end class

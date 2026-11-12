<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use OCA\OpenRegister\Db\AuditTrail\PartyQueryRefusal;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden outcomes of the capped party search: the refusal over the cap, the
 * schema it names on the trail, which schemas a filter selects, and the
 * answer inside the cap, pinned before the search was split into steps.
 *
 * @spec openspec/specs/party-model/spec.md
 */
class PartySearchServiceAccessGoldenTest extends TestCase {

	/**
	 * The trail entries the refusal wrote, as their arguments.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $trail = [];

	/**
	 * The schemas each findAll ran over.
	 *
	 * @var array<int, int>
	 */
	private array $found = [];

	/**
	 * A search service over two party schemas.
	 *
	 * @param array<int, int|RuntimeException> $counts Per schema id: the count, or what counting throws.
	 * @param int $cap The cap.
	 *
	 * @return PartySearchService The service.
	 */
	private function service(array $counts, int $cap=10): PartySearchService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('count')->willReturnCallback(
			function (array $config) use ($counts): int {
				$count = $counts[$config['filters']['schema']];
				if ($count instanceof RuntimeException) {
					throw $count;
				}

				return $count;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->found[] = $config['filters']['schema'];
				if ($config['filters']['schema'] === 8) {
					throw new RuntimeException('down');
				}

				return ['hit-' . $config['filters']['schema'] . '-' . $config['limit']];
			}
		);

		$parties = $this->createMock(PartyService::class);
		$parties->method('partySchemas')->willReturn([$this->schema(7, 'persoon', 'uuid-7'), $this->schema(8, 'bedrijf', 'uuid-8'), $this->schema(9, 'org', 'uuid-9')]);

		$audit = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['recordPartyQueryRefusal'])
			->getMock();
		$audit->method('recordPartyQueryRefusal')->willReturnCallback(
			function (PartyQueryRefusal $refusal): AuditTrail {
				$this->trail[] = [$refusal->query, $refusal->cap, $refusal->would, $refusal->schema];
				return new AuditTrail();
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn($cap);

		return new PartySearchService($objects, $parties, $audit, $config);
	}//end service()

	/**
	 * A party schema entry.
	 *
	 * @param int $id The id.
	 * @param string $slug The slug.
	 * @param string $uuid The uuid.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function schema(int $id, string $slug, string $uuid): array {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setUuid($uuid);

		return ['schema' => $schema, 'definition' => new PartyDefinition()];
	}//end schema()

	/**
	 * Inside the cap the matches of every schema answer, in schema order; a failing find contributes nothing.
	 *
	 * @return void
	 */
	public function testInsideTheCapEverySchemaAnswers(): void {
		$result = $this->service([7 => 2, 8 => new RuntimeException('x'), 9 => 3])->search('  jan ');

		$this->assertSame(['results' => ['hit-7-10', 'hit-9-10'], 'total' => 5, 'cap' => 10], $result);
		$this->assertSame([7, 8, 9], $this->found);
		$this->assertSame([], $this->trail);
	}//end testInsideTheCapEverySchemaAnswers()

	/**
	 * Exactly at the cap is allowed; over it is refused with 403 and names no schema when several ran.
	 *
	 * @return void
	 */
	public function testOverTheCapIsRefusedNamingNoSchemaForSeveral(): void {
		$this->assertSame(10, $this->service([7 => 5, 8 => 0, 9 => 5])->search('jan')['total']);

		$this->found = [];
		try {
			$this->service([7 => 5, 8 => 1, 9 => 5])->search('jan');
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(403, $e->getCode());
			$this->assertSame(
				'The query matches 11 parties, over the administered cap of 10. Narrow the query: results are refused, never truncated.',
				$e->getMessage()
			);
		}

		$this->assertSame([], $this->found);
		$this->assertSame([['jan', 10, 11, null]], $this->trail);
	}//end testOverTheCapIsRefusedNamingNoSchemaForSeveral()

	/**
	 * A filter selects by id, slug or uuid; one schema searched is named on the trail.
	 *
	 * @return void
	 */
	public function testAFilterSelectsOneSchemaAndTheTrailNamesIt(): void {
		$this->assertSame(['hit-9-10'], $this->service([9 => 1])->search('jan', 'org')['results']);
		$this->assertSame(['hit-9-10'], $this->service([9 => 1])->search('jan', 'uuid-9')['results']);
		$this->assertSame(['hit-9-10'], $this->service([9 => 1])->search('jan', '9')['results']);
		$this->assertSame(['hit-7-10'], $this->service([7 => 1])->search('jan', 7)['results']);

		try {
			$this->service([7 => 99])->search('jan', 'persoon');
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(403, $e->getCode());
		}

		$this->assertSame([['jan', 10, 99, 7]], $this->trail);
	}//end testAFilterSelectsOneSchemaAndTheTrailNamesIt()

	/**
	 * A filter that names no party schema answers empty with the cap, and counts nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaAnswersEmpty(): void {
		$this->assertSame(['results' => [], 'total' => 0, 'cap' => 4], $this->service([], 4)->search('jan', 'nope'));
	}//end testAnUnknownSchemaAnswersEmpty()

	/**
	 * A blank term is refused with 400 before any schema is read.
	 *
	 * @return void
	 */
	public function testABlankTermIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);
		$this->expectExceptionMessage('A party query needs a search term');

		$this->service([])->search("  \t ");
	}//end testABlankTermIsRefused()
}//end class

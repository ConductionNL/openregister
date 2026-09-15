<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A person query over the administered cap is refused, never truncated.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */
class PartySearchServiceTest extends TestCase {

	/**
	 * The object layer.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objects;

	/**
	 * The party schemas.
	 *
	 * @var PartyService&MockObject
	 */
	private $parties;

	/**
	 * The audit trail.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private $audit;

	/**
	 * The administered cap.
	 *
	 * @var IAppConfig&MockObject
	 */
	private $config;

	/**
	 * The service under test.
	 *
	 * @var PartySearchService
	 */
	private PartySearchService $service;

	/**
	 * Build the service on doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->parties = $this->createMock(PartyService::class);
		$this->audit = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['createPartyQueryRefusalEntry'])
			->getMock();
		$this->config = $this->createMock(IAppConfig::class);

		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('persoon');
		$this->parties->method('partySchemas')->willReturn(
			[['schema' => $schema, 'definition' => new PartyDefinition()]]
		);

		$this->service = new PartySearchService(
			$this->objects,
			$this->parties,
			$this->audit,
			$this->config
		);
	}//end setUp()

	/**
	 * Set the administered cap.
	 *
	 * @param int $cap The cap.
	 *
	 * @return void
	 */
	private function capIs(int $cap): void {
		$this->config->method('getValueInt')->willReturn($cap);
	}//end capIs()

	/**
	 * A broad person search is refused, the message names the cap, and the
	 * response holds no party at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function testABroadSearchIsRefusedNotTruncated(): void {
		$this->capIs(cap: 10);
		$this->objects->method('count')->willReturn(40);
		// THE POINT OF THE REFUSAL: nothing is read, so nothing can leak as a
		// truncated page that looks like a complete answer.
		$this->objects->expects($this->never())->method('findAll');
		$this->audit->expects($this->once())->method('createPartyQueryRefusalEntry')->willReturn(new AuditTrail());

		$this->expectException(Exception::class);
		$this->expectExceptionCode(403);
		$this->expectExceptionMessage('over the administered cap of 10');

		$this->service->search(query: 'jan');
	}//end testABroadSearchIsRefusedNotTruncated()

	/**
	 * The refused attempt is on the trail with the actor, the query and the cap.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function testTheRefusedAttemptIsOnTheTrail(): void {
		$this->capIs(cap: 10);
		$this->objects->method('count')->willReturn(40);

		$recorded = [];
		$this->audit->method('createPartyQueryRefusalEntry')->willReturnCallback(
			function (string $query, int $cap, int $would, ?int $schema) use (&$recorded): AuditTrail {
				$recorded = ['query' => $query, 'cap' => $cap, 'would' => $would, 'schema' => $schema];

				return new AuditTrail();
			}
		);

		try {
			$this->service->search(query: 'jan', schemaId: 'persoon');
			$this->fail('The query should have been refused.');
		} catch (Exception) {
			// The refusal is the subject of the other test; here it is the
			// trail entry that matters.
		}

		$this->assertSame('jan', $recorded['query']);
		$this->assertSame(10, $recorded['cap']);
		$this->assertSame(40, $recorded['would']);
		$this->assertSame(7, $recorded['schema']);
	}//end testTheRefusedAttemptIsOnTheTrail()

	/**
	 * A query inside the cap answers, and writes no refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function testAQueryInsideTheCapAnswers(): void {
		$this->capIs(cap: 10);
		$this->objects->method('count')->willReturn(2);

		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$this->objects->method('findAll')->willReturn([$party, $party]);
		$this->audit->expects($this->never())->method('createPartyQueryRefusalEntry');

		$found = $this->service->search(query: 'jansen');

		$this->assertSame(2, $found['total']);
		$this->assertCount(2, $found['results']);
		$this->assertSame(10, $found['cap']);
	}//end testAQueryInsideTheCapAnswers()

	/**
	 * An empty query is a 400: a cap is not a licence to enumerate the whole
	 * register by asking for nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function testAnEmptyQueryIsRefused(): void {
		$this->capIs(cap: 10);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service->search(query: '   ');
	}//end testAnEmptyQueryIsRefused()

	/**
	 * A cap an administrator set to zero or below falls back to the default
	 * rather than refusing every query on the instance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function testANonsenseCapFallsBackToTheDefault(): void {
		$this->capIs(cap: 0);

		$this->assertSame(PartySearchService::DEFAULT_CAP, $this->service->cap());
	}//end testANonsenseCapFallsBackToTheDefault()
}//end class

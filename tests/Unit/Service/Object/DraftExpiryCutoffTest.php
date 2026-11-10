<?php

/**
 * Unit tests for the cutoff and removal budget a draft-expiry pass carries per schema.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\DraftExpiry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectDeletion;

/**
 * The pair a pass builds names the register, the schema and the cutoff the lists are asked with;
 * the batch carries the drafts found and the budget that decides whether they are removed now.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftExpiry
 * @covers \OCA\OpenRegister\Service\Object\DraftExpiryPair
 * @covers \OCA\OpenRegister\Service\Object\DraftExpiryBatch
 */
class DraftExpiryCutoffTest extends TestCase {

	/**
	 * The search queries the pass made.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The uuids the pass deleted.
	 *
	 * @var list<string>
	 */
	private array $deleted = [];

	/**
	 * A pass over register `crm` (id 3) holding schema `lead` (id 9), whose lists hold one stale draft.
	 *
	 * @return DraftExpiry
	 */
	private function expiry(): DraftExpiry {
		$stale = new ObjectEntity();
		$stale->setUuid('stale-1');
		$stale->setStatus(ObjectEntity::STATUS_DRAFT);
		$stale->setUpdated(new DateTime('2026-09-01T00:00:00+00:00'));
		$stale->setCreated(new DateTime('2026-08-01T00:00:00+00:00'));

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query) use ($stale): array {
				$this->queries[] = $query;
				return isset($query['@self']['updated']['lt']) === true ? [$stale] : [];
			}
		);
		$objects->method('find')->willReturn($stale);
		$objects->method('deleteObject')->willReturnCallback(
			function (ObjectDeletion $deletion, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false): bool {
				$uuid = $deletion->uuid;
				$this->deleted[] = $uuid;
				return true;
			}
		);

		$register = new Register();
		$register->setId(3);
		$register->setSlug('crm');
		$register->setSchemas([9]);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('findAll')->willReturn([$register]);

		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('lead');
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		return new DraftExpiry($objects, $registers, $schemas, $this->createMock(LoggerInterface::class));

	}//end expiry()

	/**
	 * The lists are asked with the 30-day cutoff for this register and schema; a preview removes nothing.
	 *
	 * @return void
	 */
	public function testThePreviewAsksWithTheCutoffAndDefersEverything(): void {
		$counts = $this->expiry()->preview(new DateTimeImmutable('2026-10-12T03:00:00+00:00'));

		$this->assertSame(['register' => '3', 'schema' => '9', 'updated' => ['lt' => '2026-09-12 03:00:00']], array_intersect_key($this->queries[0]['@self'], array_flip(['register', 'schema', 'updated'])));
		$this->assertSame('2026-09-12 03:00:00', $this->queries[1]['@self']['created']['lt']);
		$this->assertSame(
			[['register' => 'crm', 'schema' => 'lead', 'lifetimeDays' => 30, 'expired' => 1, 'removed' => 0, 'refused' => 0, 'deferred' => 1]],
			$counts['perSchema']
		);
		$this->assertSame([], $this->deleted);

	}//end testThePreviewAsksWithTheCutoffAndDefersEverything()

	/**
	 * A sweep has a budget, so the stale draft is removed.
	 *
	 * @return void
	 */
	public function testASweepRemovesWithinItsBudget(): void {
		$counts = $this->expiry()->sweep(new DateTimeImmutable('2026-10-12T03:00:00+00:00'));

		$this->assertSame(['stale-1'], $this->deleted);
		$this->assertSame(1, $counts['removed']);
		$this->assertSame(0, $counts['deferred']);

	}//end testASweepRemovesWithinItsBudget()
}//end class

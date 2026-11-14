<?php

/**
 * Unit tests for BulkObjectQuota and BulkQuotaTally: a schema's per-organisation cap on a bulk save.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectQuota;
use OCA\OpenRegister\Service\Quota\ObjectQuotaServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Creates up to the cap pass in order, the rest are refused; updates and rows
 * in no organisation are never counted; a failed count refuses the group.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectQuota
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects\BulkQuotaTally
 * @uses   \OCA\OpenRegister\Listener\ObjectQuotaListener
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 */
final class BulkObjectQuotaTest extends TestCase {

	private ObjectQuotaServiceInterface&MockObject $quotas;

	private LoggerInterface&MockObject $logger;

	/**
	 * The count calls made, as "register|schema|organisation".
	 *
	 * @var array<int, string>
	 */
	private array $counted = [];

	protected function setUp(): void {
		$this->quotas = $this->createMock(ObjectQuotaServiceInterface::class);
		$this->quotas->method('limitFor')->willReturnCallback(
			static fn (Schema $schema): ?int => $schema->getSlug() === 'schedule' ? 2 : null
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * Stored counts per organisation for the count double.
	 *
	 * @param array<string, int|\Throwable> $stored The count, or the failure, per organisation.
	 */
	private function storedCounts(array $stored): void {
		$this->quotas->method('count')->willReturnCallback(
			function (int $registerId, Schema $schema, string $organisationUuid) use ($stored): int {
				$this->counted[] = $registerId.'|'.$schema->getSlug().'|'.$organisationUuid;
				$answer = ($stored[$organisationUuid] ?? 0);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
	}

	/**
	 * A prepared row.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $uuid, string $organisation = 'org-a', string $schema = '7'): array {
		return ['title' => $uuid, '@self' => ['uuid' => $uuid, 'id' => $uuid, 'register' => '3', 'schema' => $schema, 'organisation' => $organisation]];
	}

	/**
	 * Screen rows; schema 7 is `schedule` (cap 2), schema 8 `note` (no cap).
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 * @param array<string, bool>              $stored Uuids of updates.
	 *
	 * @return array{0: array<int, string>, 1: array<int, array{object: array, error: string, code: string}>}
	 */
	private function screen(array $rows, array $stored = []): array {
		$schemaOf = static function (string $ref): Schema {
			if ($ref === 'missing') {
				throw new RuntimeException('no such schema');
			}

			$schema = new Schema();
			$schema->setSlug($ref === '7' ? 'schedule' : 'note');

			return $schema;
		};

		[$passed, $refused] = (new BulkObjectQuota($this->quotas, $this->logger))->screen(
			rows: $rows,
			storedUuids: $stored,
			schemaOf: $schemaOf,
			registerOf: static fn (string $ref): int => (int)$ref
		);

		return [array_map(static fn (array $row): string => (string)($row['@self']['uuid'] ?? $row['title']), $passed), $refused];
	}

	public function testCreatesUpToTheCapPassAndTheRestAreRefused(): void {
		$this->storedCounts(['org-a' => 1]);

		[$passed, $refused] = $this->screen([self::row('a1'), self::row('a2'), self::row('a3')]);

		$this->assertSame(['a1'], $passed);
		$this->assertCount(2, $refused);
		$this->assertSame('object-quota-exceeded', $refused[0]['code']);
		$this->assertSame('This organisation already holds 2 of at most 2 objects of schema "schedule".', $refused[0]['error']);
		$this->assertSame('a2', $refused[0]['object']['@self']['uuid']);
		$this->assertSame(['3|schedule|org-a'], $this->counted, 'one count per register, schema and organisation');
	}

	public function testEachOrganisationHasItsOwnCap(): void {
		$this->storedCounts(['org-a' => 2, 'org-b' => 0]);

		[$passed, $refused] = $this->screen([self::row('a1'), self::row('b1', 'org-b'), self::row('b2', 'org-b'), self::row('b3', 'org-b')]);

		$this->assertSame(['b1', 'b2'], $passed);
		$this->assertSame(['a1', 'b3'], array_map(static fn (array $r): string => $r['object']['@self']['uuid'], $refused));
	}

	public function testAnUpdateIsNeverCountedOrRefused(): void {
		$this->storedCounts(['org-a' => 2]);

		[$passed, $refused] = $this->screen([self::row('stored-1'), self::row('new-1')], ['stored-1' => true]);

		$this->assertSame(['stored-1'], $passed);
		$this->assertCount(1, $refused);
	}

	public function testRowsOutsideAnyCapAreNotCounted(): void {
		$this->quotas->expects($this->never())->method('count');

		[$passed, $refused] = $this->screen(
			[
				self::row('no-org', ''),
				self::row('uncapped', 'org-a', '8'),
				self::row('unknown', 'org-a', 'missing'),
				['title' => 'no self'],
			]
		);

		$this->assertSame([], $refused);
		$this->assertCount(4, $passed);
	}

	public function testAFailedCountRefusesTheGroupAndIsLogged(): void {
		$this->storedCounts(['org-a' => new RuntimeException('count failed'), 'org-b' => 0]);
		$this->logger->expects($this->once())->method('error')->with(
			'[BulkObjectQuota] The quota count failed, refusing the bulk creates: count failed',
			['app' => 'openregister', 'schema' => '7', 'organisation' => 'org-a']
		);

		[$passed, $refused] = $this->screen([self::row('a1'), self::row('a2'), self::row('b1', 'org-b')]);

		$this->assertSame(['b1'], $passed);
		$this->assertSame(['object-quota-unchecked', 'object-quota-unchecked'], array_column($refused, 'code'));
		$this->assertStringContainsString('could not be checked', $refused[0]['error']);
	}
}

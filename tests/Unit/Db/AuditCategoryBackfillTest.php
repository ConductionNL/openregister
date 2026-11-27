<?php

/**
 * AuditCategoryBackfillTest
 *
 * The `category` column (REQ-HSA-011): every writer sets it, the migration
 * backfills it by action, and it stays outside the hash, so a sealed chain
 * still verifies after the backfill.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Migration\Version1Date20261114230015;
use OCA\OpenRegister\Kernel\Contracts\AdministrativeRetentionPeriod;
use OCA\OpenRegister\Service\Audit\AdministrativeRetention;
use OCA\OpenRegister\Service\Audit\AuditCategory;
use OCA\OpenRegister\Service\Audit\AuditEntryWriter;
use OCA\OpenRegister\Service\Audit\EntityEditAuditor;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(AuditCategory::class)]
#[CoversClass(Version1Date20261114230015::class)]
#[UsesClass(AuditTrail::class)]
#[UsesClass(AuditTrailMapper::class)]
#[UsesClass(AuditTrailMapperServices::class)]
#[UsesClass(AuditHashService::class)]
#[UsesClass(AdministrativeRetention::class)]
#[UsesClass(AdministrativeRetentionPeriod::class)]
#[UsesClass(EntityEditAuditor::class)]
#[UsesClass(SettingsChangeAuditor::class)]
#[UsesClass(AuditEntryWriter::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry::class)]
#[UsesClass(Schema::class)]
final class AuditCategoryBackfillTest extends TestCase {

	/**
	 * A sealed row's canonical form, and so its hash, is the same before and
	 * after the backfill gives it a category.
	 *
	 * @return void
	 */
	public function testTheChainVerifiesAfterBackfill(): void {
		$hash = new AuditHashService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class)
		);

		$row = new AuditTrail();
		$row->setUuid('00000000-0000-4000-8000-000000000001');
		$row->setAction('schema.updated');
		$row->setUser('beheerder');
		$row->setCreated(new DateTime('2026-11-01 10:00:00'));
		$row->setChanged(['fields' => [['path' => 'name', 'old' => 'a', 'new' => 'b']]]);

		$before   = $hash->getCanonicalJson(entry: $row);
		$previous = $hash->getGenesisHash();
		$sealed   = $hash->computeHash(entry: $row, previousHash: $previous);

		$row->setCategory(AuditCategory::forAction(action: $row->getAction()));

		$this->assertSame(AuditCategory::ADMINISTRATIVE, $row->getCategory());
		$this->assertSame($before, $hash->getCanonicalJson(entry: $row));
		$this->assertSame($sealed, $hash->computeHash(entry: $row, previousHash: $previous));
		$this->assertStringNotContainsString('category', $before);
	}

	/**
	 * The migration sets administrative on the administrative actions first,
	 * then domain on every row still without a category, and writes nothing else.
	 *
	 * @return void
	 */
	public function testTheBackfillSetsTheCategoryByAction(): void {
		$statements = [];
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('escapeLikeParameter')->willReturnArgument(0);
		$connection->method('getQueryBuilder')->willReturnCallback(
			function () use (&$statements): IQueryBuilder {
				$statement = ['set' => [], 'where' => [], 'params' => []];
				$index     = count($statements);
				$statements[] = &$statement;

				$expr = $this->createMock(IExpressionBuilder::class);
				$expr->method('like')->willReturnCallback(static fn ($column, $param): string => $column . ' LIKE ' . $param);
				$expr->method('isNull')->willReturnCallback(static fn ($column): string => $column . ' IS NULL');
				$expr->method('orX')->willReturnCallback(
					function (...$parts) use (&$statement): ICompositeExpression {
						$statement['or'] = $parts;
						return $this->createMock(ICompositeExpression::class);
					}
				);

				$qb = $this->createMock(IQueryBuilder::class);
				$qb->method('expr')->willReturn($expr);
				$qb->method('createNamedParameter')->willReturnCallback(
					static function ($value) use (&$statement): string {
						$statement['params'][] = $value;
						return ':' . $value;
					}
				);
				$qb->method('update')->willReturnSelf();
				$qb->method('set')->willReturnCallback(
					static function ($column, $value) use (&$statement, $qb): IQueryBuilder {
						$statement['set'][$column] = $value;
						return $qb;
					}
				);
				$qb->method('where')->willReturnCallback(
					static function ($condition) use (&$statement, $qb): IQueryBuilder {
						$statement['where'][] = $condition;
						return $qb;
					}
				);
				$qb->method('andWhere')->willReturnCallback(
					static function ($condition) use (&$statement, $qb): IQueryBuilder {
						$statement['where'][] = $condition;
						return $qb;
					}
				);
				$qb->method('executeStatement')->willReturn(($index === 0) ? 3 : 7);

				unset($statement);
				return $qb;
			}
		);

		$counts = (new Version1Date20261114230015(connection: $connection))->backfill();

		$this->assertSame([AuditCategory::ADMINISTRATIVE => 3, AuditCategory::DOMAIN => 7], $counts);
		$this->assertCount(2, $statements);
		$this->assertSame(['category' => ':administrative'], $statements[0]['set']);
		$this->assertSame('category IS NULL', $statements[0]['where'][0]);
		$this->assertCount(2, $statements[0]['where']);
		$this->assertSame(
			['action LIKE :schema.%', 'action LIKE :register.%', 'action LIKE :organisation.%', 'action LIKE :settings.%'],
			$statements[0]['or']
		);
		$this->assertSame(['category' => ':domain'], $statements[1]['set']);
		$this->assertSame(['category IS NULL'], $statements[1]['where']);
	}

	/**
	 * The schema and settings writers mark their rows administrative, the
	 * object write path marks its rows domain, and the insert gives a row
	 * without one the category of its action.
	 *
	 * @return void
	 */
	public function testEveryWriterSetsACategory(): void {
		$written = [];
		$mapper  = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			static function (array $entries) use (&$written): array {
				array_push($written, ...$entries);
				return $entries;
			}
		);
		$session = $this->createMock(IUserSession::class);

		$old = new Schema();
		$old->setId(3);
		$old->setUuid('00000000-0000-4000-8000-000000000003');
		$old->setTitle('Meldingen');
		$new = clone $old;
		$new->setTitle('Meldingen 2');
		(new EntityEditAuditor($mapper, $session, $this->createMock(LoggerInterface::class)))->recordUpdated(old: $old, new: $new);

		// The settings writer goes through the Core audit port; the trail's insertAuditTrails() stamps
		// the category from the action, as the real mapper does.
		$stamping       = $this->mapper();
		$settingsMapper = $this->createMock(AuditTrailMapperInterface::class);
		$settingsMapper->method('insertAuditTrails')->willReturnCallback(
			static function (array $entries) use (&$written, $stamping): array {
				$entries = array_map(static fn (AuditTrail $row): AuditTrail => $stamping->stampCategory(auditTrail: $row), $entries);
				array_push($written, ...$entries);
				return $entries;
			}
		);
		(new SettingsChangeAuditor(trail: new AuditEntryWriter(mapper: $settingsMapper), userSession: $session, logger: $this->createMock(LoggerInterface::class)))
			->recordUpdate(app: 'openregister', before: ['register' => 'a'], after: ['register' => 'b']);

		$this->assertSame(['schema.updated', 'settings.updated'], array_map(static fn (AuditTrail $row): string => (string)$row->getAction(), $written));
		foreach ($written as $row) {
			$this->assertSame(AuditCategory::ADMINISTRATIVE, $row->getCategory(), (string)$row->getAction());
		}

		$real = $this->mapper();
		$object = new AuditTrail();
		$object->setAction('update');
		$this->assertSame(AuditCategory::DOMAIN, $real->stampCategory(auditTrail: $object)->getCategory());

		$organisation = new AuditTrail();
		$organisation->setAction('organisation.updated');
		$this->assertSame(AuditCategory::ADMINISTRATIVE, $real->stampCategory(auditTrail: $organisation)->getCategory());

		$chosen = new AuditTrail();
		$chosen->setAction('update');
		$chosen->setCategory(AuditCategory::ADMINISTRATIVE);
		$this->assertSame(AuditCategory::ADMINISTRATIVE, $real->stampCategory(auditTrail: $chosen)->getCategory(), 'A writer that set a category keeps it');

		$junk = new AuditTrail();
		$junk->setAction('register.deleted');
		$junk->setCategory('something-else');
		$this->assertSame(AuditCategory::ADMINISTRATIVE, $real->stampCategory(auditTrail: $junk)->getCategory(), 'An unknown category is replaced');
	}

	/**
	 * A real mapper over doubles; the container has no retention service, so the default period applies.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(): AuditTrailMapper {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));

		return new AuditTrailMapper(
			$this->createMock(IDBConnection::class),
			new AuditTrailMapperServices($container, $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);
	}
}

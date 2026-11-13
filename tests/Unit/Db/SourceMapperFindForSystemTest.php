<?php

/**
 * SourceMapperFindForSystemTest — the system lookup of a schema's configured
 * Source: by id or by uuid, without the RBAC verify and without the
 * active-organisation filter the ordinary find() applies.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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
 * @spec openspec/changes/dbal-source-resolution-system-context/specs/dbal-source-resolution-system-context/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SourceMapper::findForSystem
 * @uses   \OCA\OpenRegister\Db\SourceMapper
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyServices
 */
final class SourceMapperFindForSystemTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper whose session, groups, organisations and app config must never be consulted.
	 *
	 * @return SourceMapper
	 */
	private function mapper(): SourceMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		$session = $this->createMock(IUserSession::class);
		$session->expects($this->never())->method($this->anything());
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method($this->anything());
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->expects($this->never())->method($this->anything());
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method($this->anything());

		return new SourceMapper(
			$db,
			new TenancyServices($organisations, $session, $groups),
			$this->createMock(EntityEventsInterface::class),
			$appConfig
		);
	}

	/**
	 * A stored source row.
	 *
	 * @return array<string, mixed>
	 */
	private function row(): array {
		return [
			'id' => 7,
			'uuid' => '5c0f4b52-3e5a-4b8e-9f43-1d2c3b4a5e6f',
			'title' => 'Zaken database',
			'type' => 'database',
			'organisation' => 'org-other',
		];
	}

	public function testADigitIdIsLookedUpByIdWithoutAnyOrganisationFilter(): void {
		$this->queuedRows = [[$this->row()]];

		$source = $this->mapper()->findForSystem(sourceId: '7');

		$this->assertInstanceOf(Source::class, $source);
		$this->assertSame(7, $source->getId());
		$this->assertSame('org-other', $source->getOrganisation());
		$this->assertSame('Zaken database', $source->getTitle());

		$this->assertSame(
			[
				'#1 select(*)',
				'#1 from(openregister_sources, NULL)',
				'#1 param :p1 = 7, ' . IQueryBuilder::PARAM_INT,
				'#1 expr.eq(id, :p1, NULL)',
				'#1 where(eq(id, :p1, NULL))',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
		$this->assertSame(1, $this->builderCount);
	}

	public function testAUuidIsLookedUpByUuid(): void {
		$this->queuedRows = [[$this->row()]];

		$source = $this->mapper()->findForSystem(sourceId: '5c0f4b52-3e5a-4b8e-9f43-1d2c3b4a5e6f');

		$this->assertSame('5c0f4b52-3e5a-4b8e-9f43-1d2c3b4a5e6f', $source->getUuid());
		$this->assertSame(
			[
				'#1 select(*)',
				'#1 from(openregister_sources, NULL)',
				'#1 param :p1 = 5c0f4b52-3e5a-4b8e-9f43-1d2c3b4a5e6f, ' . IQueryBuilder::PARAM_STR,
				'#1 expr.eq(uuid, :p1, NULL)',
				'#1 where(eq(uuid, :p1, NULL))',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
	}

	public function testANumericLookingButNonDigitIdIsTreatedAsAUuid(): void {
		$this->queuedRows = [[$this->row()]];

		$this->mapper()->findForSystem(sourceId: '-7');

		$this->assertContains('#1 expr.eq(uuid, :p1, NULL)', $this->queryLog);
		$this->assertContains('#1 param :p1 = -7, ' . IQueryBuilder::PARAM_STR, $this->queryLog);
	}

	public function testAMissingSourceThrowsForTheCallerToTreatAsUnresolved(): void {
		$this->queuedRows = [[]];

		$this->expectException(DoesNotExistException::class);
		$this->mapper()->findForSystem(sourceId: '404');
	}
}

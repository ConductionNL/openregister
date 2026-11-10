<?php

/**
 * How a Tables row's relation cells resolve: the seeded-schema question is
 * asked once per target table per row, and a seeded target yields the
 * derived object uuid while an unseeded one keeps the raw row id.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\TablesProviderParts;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Relation tests for {@see TablesObjectSourceProvider}.
 */
class TablesObjectSourceProviderRelationTest extends TestCase {

	/**
	 * Two relation cells to the same seeded table ask once and both derive
	 * the uuid; a cell to an unseeded table keeps its row id.
	 *
	 * @return void
	 */
	public function testRelationTargetsAreAskedOncePerTableAndResolved(): void {
		$reader = $this->createMock(TablesTableReader::class);
		$reader->method('listColumns')->willReturn(
			[
				['id' => 1, 'title' => 'lead', 'type' => 'relation', 'relationTargetTableId' => 8],
				['id' => 2, 'title' => 'backup', 'type' => 'relation', 'relationTargetTableId' => 8],
				['id' => 3, 'title' => 'origin', 'type' => 'relation', 'relationTargetTableId' => 9],
			]
		);
		$reader->method('findRows')->willReturn(
			[['id' => 42, 'cells' => [['columnId' => 1, 'value' => 7], ['columnId' => 2, 'value' => 11], ['columnId' => 3, 'value' => 5]]]]
		);
		$sync = $this->createMock(TablesSchemaSyncService::class);
		$asked = [];
		$sync->method('hasManagedSchemaForTableId')->willReturnCallback(
			static function (int $tableId) use (&$asked): bool {
				$asked[] = $tableId;

				return $tableId === 8;
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$deriver = new TablesUuidDeriver();
		$provider = new TablesObjectSourceProvider(parts: new TablesProviderParts(reader: $reader, columnMapper: new TablesColumnMapper(uuidDeriver: $deriver, logger: new NullLogger()), uuidDeriver: $deriver, syncService: $sync), session: new \OCA\OpenRegister\Service\LinkSessionRuntime(userSession: $session, logger: new NullLogger()));

		$objects = $provider->findAll(register: new Register(), schema: new Schema(), query: [], config: ['tableId' => 5]);

		$data = $objects[0]->getObject();
		$this->assertSame($deriver->deriveObjectUuid(tableId: 8, rowId: 7), $data['lead']);
		$this->assertSame($deriver->deriveObjectUuid(tableId: 8, rowId: 11), $data['backup']);
		$this->assertSame(5, $data['origin']);
		$this->assertSame([8, 9], $asked);
	}//end testRelationTargetsAreAskedOncePerTableAndResolved()
}//end class

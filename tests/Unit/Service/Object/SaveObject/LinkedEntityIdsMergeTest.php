<?php

/**
 * Unit tests for how extracted linked-entity ids are merged into an object's metadata columns.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ids of one Nc* type, gathered across properties, are carried together and merged after the ad-hoc links.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityIds
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler
 */
class LinkedEntityIdsMergeTest extends TestCase {

	/**
	 * Two NcContact properties merge, after the existing sidebar links, into one column, logged once per type.
	 *
	 * @return void
	 */
	public function testIdsOfOneTypeMergeAfterTheExistingLinks(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'applicant' => ['type' => 'NcContact'],
				'witnesses' => ['type' => 'array', 'items' => ['type' => 'NcContact']],
				'note'      => ['type' => 'NcNote'],
			]
		);
		$object = new ObjectEntity();
		$object->setContacts(['ad-hoc-1', 'c-2']);

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[$context['ncType']] = $context['newIds'];
			}
		);

		$object->setObject(['applicant' => 'c-1', 'witnesses' => ['c-2', ['id' => 'c-3']], 'note' => ['id' => 'n-1']]);
		(new LinkedEntityPropertyHandler($logger))->extractAndPopulate($object, $schema);

		$this->assertSame(['ad-hoc-1', 'c-2', 'c-1', 'c-3'], $object->getContacts());
		$this->assertSame(['n-1'], $object->getNotes());
		$this->assertSame(['NcContact' => ['c-1', 'c-2', 'c-3'], 'NcNote' => ['n-1']], $logged);

	}//end testIdsOfOneTypeMergeAfterTheExistingLinks()
}//end class

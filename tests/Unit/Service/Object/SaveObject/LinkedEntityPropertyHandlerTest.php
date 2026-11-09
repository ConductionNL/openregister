<?php

/**
 * LinkedEntityPropertyHandler: Nextcloud-entity properties land in the object's metadata columns.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the handler extracts from each property shape, and how it merges.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityIds
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 */
class LinkedEntityPropertyHandlerTest extends TestCase {

	/**
	 * A schema with properties.
	 *
	 * @param array<string, mixed>|null $properties The properties.
	 *
	 * @return Schema
	 */
	private function schema(?array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * Single envelopes, plain ids and arrays of envelopes are merged per type into the existing column, de-duplicated.
	 *
	 * @return void
	 */
	public function testIdsFromEveryShapeAreMergedIntoTheirColumn(): void {
		$object = new ObjectEntity();
		$object->setMail(['ad-hoc', '1/6']);
		$properties = [
			'inbox' => ['type' => 'NcMail'],
			'thread' => ['type' => 'NcMail'],
			'notes' => ['type' => 'array', 'items' => ['type' => 'NcNote']],
			'deck' => ['type' => 'NcDeck'],
			'plain' => ['type' => 'string'],
			'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
			'broken' => 'not a config',
		];
		$data = [
			'inbox' => ['type' => 'NcMail', 'id' => '1/6', 'label' => 'Hello'],
			'thread' => '2/9',
			'notes' => [['id' => 41], 'n-42', '', ['label' => 'no id'], 7],
			'deck' => ['label' => 'no id'],
			'plain' => 'x',
			'tags' => ['a'],
		];
		$logger = $this->createMock(LoggerInterface::class);
		$logged = [];
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context) use (&$logged): void {
				$logged[] = $context;
			}
		);

		$result = (new LinkedEntityPropertyHandler($logger))->extractAndPopulate($object, $this->schema(properties: $properties), $data);

		$this->assertSame($object, $result);
		$this->assertSame(['ad-hoc', '1/6', '2/9'], $object->getMail());
		$this->assertSame(['41', 'n-42'], $object->getNotes());
		$this->assertSame([], $object->getDeck() ?? [], 'an envelope without an id adds nothing');
		$this->assertSame(
			[
				['ncType' => 'NcMail', 'newIds' => ['1/6', '2/9'], 'existing' => 2, 'merged' => 3],
				['ncType' => 'NcNote', 'newIds' => ['41', 'n-42'], 'existing' => 0, 'merged' => 2],
			],
			$logged
		);
	}//end testIdsFromEveryShapeAreMergedIntoTheirColumn()

	/**
	 * An array property whose value is not a list, and a missing value, add nothing.
	 *
	 * @return void
	 */
	public function testANonListOrMissingValueAddsNothing(): void {
		$object = new ObjectEntity();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('debug');
		$properties = ['files' => ['type' => 'array', 'items' => ['type' => 'NcFile']], 'talk' => ['type' => 'NcTalk']];

		(new LinkedEntityPropertyHandler($logger))->extractAndPopulate($object, $this->schema(properties: $properties), ['files' => 'one-file']);

		$this->assertSame([], $object->getFiles() ?? []);
		$this->assertSame([], $object->getTalk() ?? []);
	}//end testANonListOrMissingValueAddsNothing()
}//end class

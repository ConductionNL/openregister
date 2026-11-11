<?php

/**
 * Golden test for the object-uuid queries of the integration link mappers.
 *
 * Records the query and the result of findByObjectUuid(), deleteByObjectUuid()
 * and countByObjectUuid() on every link mapper that has them.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\AnalyticsLinkMapper
 * @covers \OCA\OpenRegister\Db\BookmarkLinkMapper
 * @covers \OCA\OpenRegister\Db\CalendarLinkMapper
 * @covers \OCA\OpenRegister\Db\CollectiveLinkMapper
 * @covers \OCA\OpenRegister\Db\ContactLinkMapper
 * @covers \OCA\OpenRegister\Db\CospendLinkMapper
 * @covers \OCA\OpenRegister\Db\DeckLinkMapper
 * @covers \OCA\OpenRegister\Db\EmailLinkMapper
 * @covers \OCA\OpenRegister\Db\FlowLinkMapper
 * @covers \OCA\OpenRegister\Db\FormLinkMapper
 * @covers \OCA\OpenRegister\Db\MapLinkMapper
 * @covers \OCA\OpenRegister\Db\OpenProjectLinkMapper
 * @covers \OCA\OpenRegister\Db\PhotoLinkMapper
 * @covers \OCA\OpenRegister\Db\PollLinkMapper
 * @covers \OCA\OpenRegister\Db\TalkLinkMapper
 * @covers \OCA\OpenRegister\Db\TimeTrackerLinkMapper
 * @covers \OCA\OpenRegister\Db\XwikiLinkMapper
 * @uses   \OCA\OpenRegister\Db\AnalyticsLink
 * @uses   \OCA\OpenRegister\Db\BookmarkLink
 * @uses   \OCA\OpenRegister\Db\CalendarLink
 * @uses   \OCA\OpenRegister\Db\EmailLink
 * @uses   \OCA\OpenRegister\Db\CollectiveLink
 * @uses   \OCA\OpenRegister\Db\ContactLink
 * @uses   \OCA\OpenRegister\Db\CospendLink
 * @uses   \OCA\OpenRegister\Db\DeckLink
 * @uses   \OCA\OpenRegister\Db\FlowLink
 * @uses   \OCA\OpenRegister\Db\FormLink
 * @uses   \OCA\OpenRegister\Db\MapLink
 * @uses   \OCA\OpenRegister\Db\OpenProjectLink
 * @uses   \OCA\OpenRegister\Db\PhotoLink
 * @uses   \OCA\OpenRegister\Db\PollLink
 * @uses   \OCA\OpenRegister\Db\TalkLink
 * @uses   \OCA\OpenRegister\Db\TimeTrackerLink
 * @uses   \OCA\OpenRegister\Db\XwikiLink
 * @uses   \OCA\OpenRegister\Db\QueryPage
 * @uses   \OCA\OpenRegister\Db\LinkColumn
 */
class LinkMapperObjectUuidGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/link-mapper-object-uuid-golden.json';

	private const MAPPERS = [
		'AnalyticsLink', 'BookmarkLink', 'CalendarLink', 'CollectiveLink', 'ContactLink', 'CospendLink',
		'DeckLink', 'EmailLink', 'FlowLink', 'FormLink', 'MapLink', 'OpenProjectLink', 'PhotoLink',
		'PollLink', 'TalkLink', 'TimeTrackerLink', 'XwikiLink',
	];

	public function testQueriesMatchGolden(): void {
		$actual = [];
		foreach (self::MAPPERS as $short) {
			$class = 'OCA\\OpenRegister\\Db\\' . $short . 'Mapper';
			foreach (['findByObjectUuid', 'deleteByObjectUuid', 'countByObjectUuid'] as $method) {
				if (method_exists($class, $method) === false) {
					continue;
				}

				foreach (['rows' => [['id' => 4], ['id' => 9]], 'empty' => []] as $rowName => $rows) {
					$actual["$short $method $rowName"] = $this->record(new $class($this->connection()), $method, $rows);
				}
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testQueriesMatchGolden()

	private function record(object $mapper, string $method, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [$rows];
		$result = $mapper->$method('obj-uuid-1');
		if (is_array($result) === true) {
			$result = array_map(static fn ($e) => [get_class($e), $e->getId()], $result);
		}

		return ['result' => $result, 'queries' => $this->queryLog];
	}//end record()

	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return $db;
	}//end connection()
}//end class

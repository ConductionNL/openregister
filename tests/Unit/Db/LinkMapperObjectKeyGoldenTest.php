<?php

/**
 * Golden test for the find and delete of one object link by a second key.
 *
 * Records every query-builder call and the result of each link mapper's
 * findByObjectAnd...() (row found and not found) and deleteByObjectAnd...().
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

use OCA\OpenRegister\Db\AnalyticsLinkMapper;
use OCA\OpenRegister\Db\BookmarkLinkMapper;
use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCA\OpenRegister\Db\CospendLinkMapper;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\FlowLinkMapper;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Db\MapLinkMapper;
use OCA\OpenRegister\Db\OpenProjectLinkMapper;
use OCA\OpenRegister\Db\PhotoLinkMapper;
use OCA\OpenRegister\Db\PollLinkMapper;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCA\OpenRegister\Db\XwikiLinkMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\AnalyticsLinkMapper
 * @covers \OCA\OpenRegister\Db\BookmarkLinkMapper
 * @covers \OCA\OpenRegister\Db\CollectiveLinkMapper
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
 * @covers \OCA\OpenRegister\Db\XwikiLinkMapper
 * @uses   \OCA\OpenRegister\Db\AnalyticsLink
 * @uses   \OCA\OpenRegister\Db\BookmarkLink
 * @uses   \OCA\OpenRegister\Db\CollectiveLink
 * @uses   \OCA\OpenRegister\Db\DeckLink
 * @uses   \OCA\OpenRegister\Db\EmailLink
 * @uses   \OCA\OpenRegister\Db\FlowLink
 * @uses   \OCA\OpenRegister\Db\MapLink
 * @uses   \OCA\OpenRegister\Db\OpenProjectLink
 * @uses   \OCA\OpenRegister\Db\PhotoLink
 * @uses   \OCA\OpenRegister\Db\PollLink
 * @uses   \OCA\OpenRegister\Db\TalkLink
 * @uses   \OCA\OpenRegister\Db\XwikiLink
 * @uses   \OCA\OpenRegister\Db\LinkColumn
 * @uses   \OCA\OpenRegister\Db\SelectByColumnTrait
 */
class LinkMapperObjectKeyGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/link-mapper-object-key-golden.json';

	private static function calls(): array {
		return [
			[AnalyticsLinkMapper::class, 'findByObjectAndReport', 'deleteByObjectAndReport', 5],
			[BookmarkLinkMapper::class, 'findByObjectAndBookmark', 'deleteByObjectAndBookmark', 6],
			[CollectiveLinkMapper::class, 'findByObjectAndPage', 'deleteByObjectAndPage', 7],
			[CospendLinkMapper::class, null, 'deleteByObjectAndId', 8],
			[DeckLinkMapper::class, 'findByObjectAndCard', 'deleteByObjectAndCard', 9],
			[EmailLinkMapper::class, 'findByObjectAndMessage', null, 10],
			[FlowLinkMapper::class, 'findByObjectAndOperation', 'deleteByObjectAndOperation', 11],
			[FormLinkMapper::class, null, 'deleteByObjectAndForm', 12],
			[MapLinkMapper::class, 'findByObjectAndFavorite', 'deleteByObjectAndFavorite', 13],
			[OpenProjectLinkMapper::class, 'findByObjectAndWorkPackage', 'deleteByObjectAndWorkPackage', 14],
			[PhotoLinkMapper::class, 'findByObjectAndAlbum', 'deleteByObjectAndAlbum', 15],
			[PollLinkMapper::class, 'findByObjectAndPoll', 'deleteByObjectAndPoll', 16],
			[TalkLinkMapper::class, 'findByObjectAndRoom', 'deleteByObjectAndRoom', 'room-tok'],
			[XwikiLinkMapper::class, 'findByObjectAndPage', 'deleteByObjectAndPage', 'Main.Page'],
		];
	}//end calls()

	public function testObjectKeyReadsAndDeletesMatchGolden(): void {
		$actual = [];
		foreach (self::calls() as [$class, $find, $delete, $key]) {
			$short = substr($class, strrpos($class, '\\') + 1);
			if ($find !== null) {
				$actual["{$short} {$find} found"] = $this->call(class: $class, call: [$find, $key, [[['id' => 3, 'object_uuid' => 'obj-1']]]]);
				$actual["{$short} {$find} missing"] = $this->call(class: $class, call: [$find, $key, [[]]]);
			}

			if ($delete !== null) {
				$actual["{$short} {$delete}"] = $this->call(class: $class, call: [$delete, $key, []]);
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
	}//end testObjectKeyReadsAndDeletesMatchGolden()

	private function call(string $class, array $call): array {
		[$method, $key, $rows] = $call;
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$outcome = [];
		try {
			$result = (new $class($db))->$method('obj-1', $key);
			$outcome['result'] = is_object($result) === true ? get_class($result) . '#' . $result->getId() : $result;
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end call()
}//end class

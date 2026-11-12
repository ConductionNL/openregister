<?php

/**
 * Golden test for the link reads of Cospend, Form and Deck that match more than one key.
 *
 * Records every query-builder call and the result of each read, with a row
 * and without. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\CospendEntryKey;
use OCA\OpenRegister\Db\CospendLinkMapper;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Db\FormSubmissionRef;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\CospendLinkMapper
 * @covers \OCA\OpenRegister\Db\DeckLinkMapper
 * @covers \OCA\OpenRegister\Db\FormLinkMapper
 * @uses   \OCA\OpenRegister\Db\CospendEntryKey
 * @uses   \OCA\OpenRegister\Db\CospendLink
 * @uses   \OCA\OpenRegister\Db\DeckLink
 * @uses   \OCA\OpenRegister\Db\FormLink
 * @uses   \OCA\OpenRegister\Db\FormSubmissionRef
 * @uses   \OCA\OpenRegister\Db\LinkColumn
 * @uses   \OCA\OpenRegister\Db\SelectByColumnTrait
 */
class LinkMapperCompositeKeyGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/link-mapper-composite-key-golden.json';

	private function cases(): array {
		return [
			'cospend project duplicate' => [CospendLinkMapper::class, fn (CospendLinkMapper $m) => $m->findDuplicate('obj-1', new CospendEntryKey('project', 'p-7'))],
			'cospend bill duplicate' => [CospendLinkMapper::class, fn (CospendLinkMapper $m) => $m->findDuplicate('obj-1', (new CospendEntryKey('bill', 'p-7'))->withBill(31))],
			'form link' => [FormLinkMapper::class, fn (FormLinkMapper $m) => $m->findFormLink('obj-2', 5)],
			'form submission link' => [FormLinkMapper::class, fn (FormLinkMapper $m) => $m->findSubmissionLink('obj-2', new FormSubmissionRef(5, 77))],
			'deck by id' => [DeckLinkMapper::class, fn (DeckLinkMapper $m) => $m->find(12)],
			'deck by board' => [DeckLinkMapper::class, fn (DeckLinkMapper $m) => $m->findByBoardId(4)],
		];
	}//end cases()

	public function testReadsMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => [$class, $call]) {
			foreach (['found' => [['id' => 3, 'object_uuid' => 'obj-1']], 'missing' => []] as $rowName => $rows) {
				$actual["{$name} / {$rowName}"] = $this->outcomeOf(mapper: new $class($this->connection()), call: [$call, $rows]);
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
	}//end testReadsMatchGolden()

	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return $db;
	}//end connection()

	private function outcomeOf(object $mapper, array $call): array {
		[$read, $rows] = $call;
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [$rows];

		$outcome = [];
		try {
			$result = $read($mapper);
			$outcome['result'] = is_array($result) === true ? array_map(static fn ($e) => get_class($e) . '#' . $e->getId(), $result) : ($result === null ? null : get_class($result) . '#' . $result->getId());
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end outcomeOf()
}//end class

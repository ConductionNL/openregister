<?php

/**
 * Golden test for PollLinkService::createAndLinkPoll.
 *
 * Records the poll and option rows written and the link returned, per scenario,
 * in fixtures/poll-link-create-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Db\PollLinkMapper;
use OCA\OpenRegister\Service\PollLinkService;
use OCA\OpenRegister\Service\PollDraft;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\PollLinkService
 * @covers \OCA\OpenRegister\Service\PollDraft
 * @uses   \OCA\OpenRegister\Db\PollLink
 * @uses   \OCA\OpenRegister\Service\LinkTarget
 */
class PollLinkServiceCreateGoldenTest extends TestCase {

	/**
	 * Scenarios: [title, description, type, options, deadline, lastInsertId, failOnInsert].
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$long = str_repeat('Lange titel ', 20);
		return [
			'text poll' => ['Lunch', 'Where?', 'textPoll', ['Pizza', ' ', 'Sushi '], null, 42, 0],
			'text alias, long title' => [$long, '', 'text', ['A', 'B', 'C'], null, 7, 0],
			'date poll with deadline' => ['Meet', 'd', 'date', ['2026-12-01 10:00', 'not a date'], '2026-12-24T12:00:00+00:00', 9, 0],
			'datePoll canonical' => ['Meet', '', 'DATEPOLL', ['2026-01-02'], null, 3, 0],
			'empty type' => ['T', '', '', [], null, 5, 0],
			'unknown type' => ['T', '', 'rankPoll', [12, ''], null, 5, 0],
			'no id' => ['T', '', 'textPoll', ['A'], null, 0, 0],
			'insert fails' => ['T', '', 'textPoll', ['A'], null, 5, 1],
			'option insert fails' => ['T', '', 'textPoll', ['A', 'B'], null, 5, 2],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testCreateAndLinkPollGolden(string $title, string $description, string $type, array $options, ?string $deadline, int $insertId, int $failOnInsert): void {
		$writes = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$writes, $failOnInsert) {
				return $this->recordingBuilder($writes, $failOnInsert);
			}
		);
		$db->method('lastInsertId')->willReturnCallback(
			function (string $table) use ($insertId) {
				$this->assertSame('*PREFIX*polls_polls_id_seq', $table);
				return $insertId;
			}
		);

		$mapper = $this->getMockBuilder(PollLinkMapper::class)->disableOriginalConstructor()->onlyMethods(['insert'])->getMock();
		$mapper->method('insert')->willReturnArgument(0);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logs = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message) use (&$logs): void {
				$logs[] = $message;
			}
		);

		$service = new PollLinkService($mapper, $db, $apps, $session, $logger);
		$deadlineValue = null;
		if ($deadline !== null) {
			$deadlineValue = new DateTimeImmutable($deadline);
		}

		$before = time();
		try {
			$link = $service->createAndLinkPoll(
				(new LinkTarget('obj-1', 3))->inSchema(4),
				(new PollDraft($title, $description))->withType($type)->withOptions($options, $deadlineValue)
			);
			$result = ['link' => $this->linkFields($link)];
		} catch (Throwable $e) {
			$result = ['error' => [get_class($e), $e->getMessage(), $e->getCode()]];
		}

		$actual = $this->normaliseTimes(['writes' => $writes, 'logs' => $logs] + $result, $before, time());
		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE), true);

		$file = __DIR__ . '/fixtures/poll-link-create-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame($all[$name], $actual);
	}

	/**
	 * A query builder that appends each insert to $writes.
	 *
	 * @param array $writes       The recorded inserts.
	 * @param int   $failOnInsert Throw on this insert number (1-based), 0 never.
	 *
	 * @return IQueryBuilder
	 */
	private function recordingBuilder(array &$writes, int $failOnInsert): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$row = ['table' => null, 'values' => null];
		$qb->method('createNamedParameter')->willReturnCallback(
			fn ($value, $type = IQueryBuilder::PARAM_STR) => json_encode([$value, $type])
		);
		$qb->method('insert')->willReturnCallback(
			function (string $table) use ($qb, &$row) {
				$row['table'] = $table;
				return $qb;
			}
		);
		$qb->method('values')->willReturnCallback(
			function (array $values) use ($qb, &$row) {
				$row['values'] = array_map(fn ($v) => json_decode($v, true), $values);
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$writes, &$row, $failOnInsert) {
				if (count($writes) + 1 === $failOnInsert) {
					throw new RuntimeException('db down');
				}

				$writes[] = $row;
				return 1;
			}
		);
		return $qb;
	}

	/**
	 * The link's fields, with linkedAt reduced to its type.
	 *
	 * @param PollLink $link The link.
	 *
	 * @return array
	 */
	private function linkFields(PollLink $link): array {
		$deadline = $link->getDeadline();
		return [
			'objectUuid' => $link->getObjectUuid(),
			'registerId' => $link->getRegisterId(),
			'schemaId' => $link->getSchemaId(),
			'pollId' => $link->getPollId(),
			'pollTitle' => $link->getPollTitle(),
			'pollType' => $link->getPollType(),
			'deadline' => $deadline instanceof DateTime ? $deadline->format('c') : $deadline,
			'voterCount' => $link->getVoterCount(),
			'optionCount' => $link->getOptionCount(),
			'closed' => $link->getClosed(),
			'linkedBy' => $link->getLinkedBy(),
			'linkedAt' => get_debug_type($link->getLinkedAt()),
		];
	}

	/**
	 * Replace created timestamps taken during the call with NOW.
	 *
	 * @param array $data   The recorded data.
	 * @param int   $before Time before the call.
	 * @param int   $after  Time after the call.
	 *
	 * @return array
	 */
	private function normaliseTimes(array $data, int $before, int $after): array {
		foreach ($data['writes'] as $i => $write) {
			$created = $write['values']['created'][0] ?? null;
			if (is_int($created) === true && $created >= $before && $created <= $after) {
				$data['writes'][$i]['values']['created'][0] = 'NOW';
			}
		}

		return $data;
	}
}

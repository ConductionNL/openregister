<?php

/**
 * Golden test for NotificationHistoryController.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use DateTime;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationHistoryControllerServices;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Notifications\Controller\NotificationHistoryController;
use OCA\OpenRegister\Notifications\Db\NotificationHistory;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * Pins every endpoint's status and body for fixed request parameters, and
 * what reaches the mapper and the clearing service: the filters, limit and
 * offset the list asks for, and the arguments of snooze and thread-read.
 * Snooze moments are fixed timestamps, never today's date. Expected values
 * were recorded from the code before its refactor and live in
 * `fixtures/notification-history-controller-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Notifications\Controller\NotificationHistoryController
 */
class NotificationHistoryControllerGoldenTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/notification-history-controller-golden.json';
	}//end goldenPath()

	/**
	 * Request parameter sets per endpoint: case => [endpoint, params, caller].
	 *
	 * @return array<string, array{string, array<string, mixed>, string|null}>
	 */
	public static function requests(): array {
		$cases = [
			'index-anon'          => ['index', [], null],
			'index-defaults'      => ['index', [], 'bob'],
			'index-admin'         => ['index', ['recipient' => 'alice', 'limit' => '10', 'offset' => '20'], 'admin'],
			'index-forced'        => ['index', ['recipient' => 'alice', 'ruleId' => 'r1', 'channel' => ''], 'bob'],
			'index-all-filters'   => [
				'index',
				[
					'ruleId'          => 'r',
					'channel'         => 'email',
					'objectUuid'      => 'o',
					'schemaId'        => '3',
					'registerId'      => '4',
					'status'          => 'sent',
					'subjectType'     => 'task',
					'subjectId'       => 's',
					'eventId'         => 'e',
					'unreadOnly'      => 'true',
					'includeArchived' => '1',
					'includeSnoozed'  => 'false',
					'bogus'           => 'x',
					'status2'         => 'y',
				],
				'admin',
			],
			'index-filter-types'  => ['index', ['ruleId' => 5, 'channel' => ['x'], 'status' => null, 'eventId' => '0'], 'admin'],
		];
		foreach (['', '0', '00', '1', '7', '499', '500', '501', '99999999999999999999', '-1', '1.5', 'abc', ' 5'] as $raw) {
			$cases['index-limit-'.$raw]  = ['index', ['limit' => $raw], 'admin'];
			$cases['index-offset-'.$raw] = ['index', ['offset' => $raw], 'admin'];
		}

		foreach (['int-0' => 0, 'int-3' => 3, 'int-600' => 600, 'int-neg' => -2, 'float' => 2.0, 'true' => true, 'array' => ['5']] as $name => $raw) {
			$cases['index-limit-'.$name]  = ['index', ['limit' => $raw], 'admin'];
			$cases['index-offset-'.$name] = ['index', ['offset' => $raw], 'admin'];
		}

		return $cases + [
			'snooze-missing'      => ['snooze', [], 'bob'],
			'snooze-empty'        => ['snooze', ['snoozedUntil' => ''], 'bob'],
			'snooze-int'          => ['snooze', ['snoozedUntil' => 5], 'bob'],
			'snooze-garbage'      => ['snooze', ['snoozedUntil' => 'not a date at all'], 'bob'],
			'snooze-ok'           => ['snooze', ['snoozedUntil' => '2026-01-02T03:04:05+00:00'], 'bob'],
			'snooze-forbidden'    => ['snooze', ['snoozedUntil' => '2026-01-02T03:04:05+00:00'], 'deny'],
			'archive-ok'          => ['archive', [], 'bob'],
			'archive-forbidden'   => ['archive', [], 'deny'],
			'thread-missing'      => ['markThreadRead', [], 'bob'],
			'thread-empty'        => ['markThreadRead', ['objectUuid' => ''], 'bob'],
			'thread-int'          => ['markThreadRead', ['objectUuid' => 5], 'bob'],
			'thread-ok'           => ['markThreadRead', ['objectUuid' => 'o-1'], 'bob'],
			'thread-subject'      => ['markThreadRead', ['objectUuid' => 'o-1', 'subjectId' => 's-1'], 'bob'],
			'thread-subject-empty' => ['markThreadRead', ['objectUuid' => 'o-1', 'subjectId' => ''], 'bob'],
			'thread-subject-int'  => ['markThreadRead', ['objectUuid' => 'o-1', 'subjectId' => 9], 'bob'],
			'thread-forbidden'    => ['markThreadRead', ['objectUuid' => 'o-1'], 'deny'],
		];
	}//end requests()

	/**
	 * Run one request and record the response and the collaborator calls.
	 *
	 * @param array{string, array<string, mixed>, string|null} $request Endpoint, params, caller.
	 *
	 * @return array<string, mixed>
	 */
	private function call(array $request): array {
		[$endpoint, $params, $caller] = $request;
		$calls = [];

		$httpRequest = $this->createMock(IRequest::class);
		$httpRequest->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($caller !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$mapper = $this->createMock(NotificationHistoryMapper::class);
		$mapper->method('findFiltered')->willReturnCallback(
			static function (ListQuery $query) use (&$calls): array {
				$calls[] = ['findFiltered', $query->filters, $query->limit, $query->offset];
				return [];
			}
		);
		$mapper->method('countFiltered')->willReturnCallback(
			static function (array $filters) use (&$calls): int {
				$calls[] = ['countFiltered', $filters];
				return 7;
			}
		);

		$clearing = $this->clearing(caller: $caller, calls: $calls);
		$services   = new NotificationHistoryControllerServices(
			mapper: $mapper,
			userSession: $session,
			groupManager: $groups,
			clearing: $clearing
		);
		$controller = new NotificationHistoryController($httpRequest, $services);

		$response = match ($endpoint) {
			'index'          => $controller->index(),
			'snooze'         => $controller->snooze(12),
			'archive'        => $controller->archive(13),
			'markThreadRead' => $controller->markThreadRead(),
		};

		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $calls];
	}//end run()

	/**
	 * A clearing service that records its calls and refuses the caller `deny`.
	 *
	 * @param string|null              $caller The caller.
	 * @param array<int, array<mixed>> $calls  Receives each call.
	 *
	 * @return NotificationClearingService
	 */
	private function clearing(?string $caller, array &$calls): NotificationClearingService {
		$row = new NotificationHistory();
		$row->setRuleId('rule-x');
		$clearing = $this->createMock(NotificationClearingService::class);
		$refuse   = static function () use ($caller): void {
			if ($caller === 'deny') {
				throw new NotAuthorizedException('not yours');
			}
		};
		$clearing->method('snooze')->willReturnCallback(
			static function (int $id, DateTime $until) use (&$calls, $refuse, $row) {
				$calls[] = ['snooze', $id, $until->format(DATE_ATOM)];
				$refuse();
				return $row;
			}
		);
		$clearing->method('archive')->willReturnCallback(
			static function (int $id) use (&$calls, $refuse, $row) {
				$calls[] = ['archive', $id];
				$refuse();
				return $row;
			}
		);
		$clearing->method('markThreadRead')->willReturnCallback(
			static function (string $objectUuid, ?string $subjectId = null) use (&$calls, $refuse): int {
				$calls[] = ['markThreadRead', $objectUuid, $subjectId];
				$refuse();
				return 4;
			}
		);

		return $clearing;
	}//end clearing()

	/**
	 * Everything pinned, per request.
	 *
	 * @return array<string, mixed>
	 */
	public function observe(): array {
		$out = [];
		foreach (self::requests() as $name => $request) {
			$out[$name] = $this->call(request: $request);
		}

		return $out;
	}//end observe()

	/**
	 * The controller still produces the recorded observations.
	 *
	 * @return void
	 */
	public function testMatchesGolden(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$actual = json_decode((string) json_encode($this->observe()), true);
		$this->assertSame($golden, $actual);
	}//end testMatchesGolden()

	/**
	 * The golden pins refusals, successes and the paging the mapper receives.
	 *
	 * @return void
	 */
	public function testGoldenIsNotTrivial(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$this->assertSame(400, $golden['snooze-garbage']['status']);
		$this->assertSame(403, $golden['thread-forbidden']['status']);
		$this->assertSame(200, $golden['thread-subject']['status']);
		$this->assertSame(500, $golden['index-limit-501']['data']['limit']);
		$this->assertSame('bob', $golden['index-forced']['calls'][0][1]['recipient']);
	}//end testGoldenIsNotTrivial()
}//end class

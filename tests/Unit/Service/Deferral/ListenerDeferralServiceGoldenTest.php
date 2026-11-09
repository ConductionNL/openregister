<?php

/**
 * Golden test for ListenerDeferralService buffering, dedupe and chunking.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Deferral
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Deferral;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralPlatform;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Support\ShutdownFunctionSpy;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../Support/ShutdownFunctionSpy.php';

/**
 * Each case runs a script of defer / flush / shutdown steps and records every
 * job enqueued (class and arguments, including the captured user and
 * organisation), every error and warning (message and context without the
 * file/line), and how many shutdown hooks were registered. Compared with
 * fixtures/listener-deferral-service-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Deferral\ListenerDeferralService
 */
class ListenerDeferralServiceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/listener-deferral-service-golden.json';

	protected function setUp(): void {
		parent::setUp();
		ShutdownFunctionSpy::reset();
	}

	/**
	 * @return array<string, array{0: array<int, array<int, mixed>>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$small = ['uuid' => 'u1', 'event' => 'created'];
		$big   = ['uuid' => 'u2', 'event' => 'updated', 'object' => ['n' => 'x'], 'oldData' => ['blob' => str_repeat('o', 300)]];
		$huge  = ['uuid' => 'u3', 'event' => 'updated', 'object' => ['blob' => str_repeat('n', 400)], 'oldData' => ['b' => 'y']];
		$hugeNoOld = ['uuid' => 'u4', 'object' => ['blob' => str_repeat('n', 400)]];
		$noUuid = ['object' => ['blob' => str_repeat('n', 400)]];
		$entry = static fn (int $i): array => ['uuid' => 'e' . $i, 'pad' => str_repeat('p', 40)];
		return [
			'one entry flushed'              => [[['defer', 'JobA', $small], ['flush']], []],
			'nothing deferred'               => [[['flush']], []],
			'chunk size reached'             => [[['defer', 'JobA', $entry(1), 2], ['defer', 'JobA', $entry(2), 2], ['defer', 'JobA', $entry(3), 2], ['flush']], []],
			'chunk size below one'           => [[['defer', 'JobA', $entry(1), 0], ['defer', 'JobA', $entry(2), -3], ['flush']], []],
			'first chunk size sticks'        => [[['defer', 'JobA', $entry(1), 5], ['defer', 'JobA', $entry(2), 1], ['defer', 'JobA', $entry(3), 1], ['flush']], []],
			'two job classes'                => [[['defer', 'JobA', $entry(1)], ['defer', 'JobB', $entry(2)], ['defer', 'JobA', $entry(3)], ['flush']], []],
			'dedupe key'                     => [[['defer', 'JobA', $entry(1), 100, 'k'], ['defer', 'JobA', $entry(2), 100, 'k'], ['defer', 'JobA', $entry(3), 100, 'other'], ['defer', 'JobA', $entry(4), 100, null], ['flush']], []],
			'dedupe resets after flush'      => [[['defer', 'JobA', $entry(1), 100, 'k'], ['flush'], ['defer', 'JobA', $entry(2), 100, 'k'], ['flush']], []],
			'dedupe resets after full chunk' => [[['defer', 'JobA', $entry(1), 1, 'k'], ['defer', 'JobA', $entry(2), 1, 'k'], ['flush']], []],
			'dedupe is per job class'        => [[['defer', 'JobA', $entry(1), 100, 'k'], ['defer', 'JobB', $entry(2), 100, 'k'], ['flush']], []],
			'dedupe empty string key'        => [[['defer', 'JobA', $entry(1), 100, ''], ['defer', 'JobA', $entry(2), 100, ''], ['flush']], []],
			'split by argument limit'        => [[['defer', 'JobA', $entry(1)], ['defer', 'JobA', $entry(2)], ['defer', 'JobA', $entry(3)], ['defer', 'JobA', $entry(4)], ['flush']], ['limit' => 220]],
			'exact fit'                      => [[['defer', 'JobA', $entry(1)], ['defer', 'JobA', $entry(2)], ['flush']], ['limit' => 184]],
			'one over the fit'               => [[['defer', 'JobA', $entry(1)], ['defer', 'JobA', $entry(2)], ['flush']], ['limit' => 183]],
			'old data dropped'               => [[['defer', 'JobA', $small], ['defer', 'JobA', $big], ['defer', 'JobA', $small], ['flush']], ['limit' => 300]],
			'too large even without old'     => [[['defer', 'JobA', $huge], ['defer', 'JobA', $small], ['flush']], ['limit' => 300]],
			'too large and no old data'      => [[['defer', 'JobA', $hugeNoOld], ['flush']], ['limit' => 300]],
			'too large without uuid'         => [[['defer', 'JobA', $noUuid], ['flush']], ['limit' => 300]],
			'all entries too large'          => [[['defer', 'JobA', $hugeNoOld], ['defer', 'JobA', $huge], ['flush']], ['limit' => 100]],
			'large entry starts new chunk'   => [[['defer', 'JobA', $small], ['defer', 'JobA', $entry(9)], ['defer', 'JobA', $small], ['flush']], ['limit' => 150]],
			'enqueue fails'                  => [[['defer', 'JobA', $small], ['defer', 'JobB', $small], ['flush']], ['addThrowsFor' => 'JobA']],
			'enqueue fails in defer'         => [[['defer', 'JobA', $small, 1], ['defer', 'JobA', $entry(2), 1]], ['addThrowsFor' => 'JobA']],
			'shutdown flushes'               => [[['defer', 'JobA', $small], ['defer', 'JobB', $small], ['shutdown']], []],
			'shutdown after flush'           => [[['defer', 'JobA', $small], ['flush'], ['shutdown']], []],
			'anonymous actor'                => [[['defer', 'JobA', $small], ['flush']], ['user' => null, 'org' => null]],
			'organisation lookup throws'     => [[['defer', 'JobA', $small], ['flush']], ['org' => 'throw']],
			'actor captured once'            => [[['defer', 'JobA', $small], ['switchUser', 'bob'], ['defer', 'JobA', $entry(2)], ['flush']], []],
			'session throws in defer'        => [[['defer', 'JobA', $small], ['flush']], ['user' => 'throw']],
		];
	}

	/**
	 * @param array<int, array<int, mixed>> $steps    The script.
	 * @param array<string, mixed>          $scenario Collaborator behaviour.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testScript(array $steps, array $scenario): void {
		$record = ['jobs' => [], 'errors' => [], 'warnings' => []];
		$userId = (array_key_exists('user', $scenario) === true) ? $scenario['user'] : 'alice';

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(
			function () use (&$userId): ?IUser {
				if ($userId === 'throw') {
					throw new \RuntimeException('session gone');
				}

				if ($userId === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($userId);
				return $user;
			}
		);

		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturnCallback(
			static function () use ($scenario): ?Organisation {
				$org = (array_key_exists('org', $scenario) === true) ? $scenario['org'] : 'org-1';
				if ($org === 'throw') {
					throw new \RuntimeException('no organisation');
				}

				if ($org === null) {
					return null;
				}

				$organisation = new Organisation();
				$organisation->setUuid($org);
				return $organisation;
			}
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			static function (string $job, mixed $argument) use ($scenario, &$record): void {
				if (($scenario['addThrowsFor'] ?? null) === $job) {
					throw new \RuntimeException('job list full');
				}

				$record['jobs'][] = [$job, $argument];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$strip = static function (array $context): array {
			unset($context['file'], $context['line']);
			return $context;
		};
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context = []) use (&$record, $strip): void {
				$record['errors'][] = [$message, $strip($context)];
			}
		);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$record, $strip): void {
				$record['warnings'][] = [$message, $strip($context)];
			}
		);

		$service = new ListenerDeferralService(
			userSession: $userSession,
			organisation: $organisations,
			platform: new ListenerDeferralPlatform(
				jobList: $jobList,
				appConfig: $this->createMock(IAppConfig::class),
				logger: $logger
			),
			argumentLimit: ($scenario['limit'] ?? 4000),
		);

		foreach ($steps as $step) {
			match ($step[0]) {
				'defer' => $service->defer($step[1], $step[2], ($step[3] ?? ListenerDeferralService::DEFAULT_CHUNK_SIZE), ($step[4] ?? null)),
				'flush' => $service->flushAll(),
				'switchUser' => $userId = $step[1],
				default => ShutdownFunctionSpy::runAll(),
			};
			$record['jobs'][] = '-- after ' . $step[0];
		}

		$record['shutdownHooks'] = count(ShutdownFunctionSpy::callbacks());
		ShutdownFunctionSpy::reset();

		$actual = json_decode((string) json_encode($record), true);
		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}

	/**
	 * Mutation check: every enqueued job carries the actor captured at the
	 * first defer, never a later one.
	 *
	 * @return void
	 */
	public function testJobsCarryTheFirstCapturedActor(): void {
		$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		$jobs = array_values(array_filter($golden['actor captured once']['jobs'], 'is_array'));
		$this->assertCount(1, $jobs);
		$this->assertSame('alice', $jobs[0][1]['userId']);
		$this->assertSame('org-1', $jobs[0][1]['organisationUuid']);
		$anonymous = array_values(array_filter($golden['anonymous actor']['jobs'], 'is_array'));
		$this->assertNull($anonymous[0][1]['userId']);
	}
}

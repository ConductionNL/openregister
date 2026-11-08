<?php

/**
 * Golden test for the TransferListService review state machine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pins which review transitions are ALLOWED (an `in_review` list may be
 * approved, rejected with a reason, or have objects excluded) and which are
 * REFUSED (any other status, a rejection without a reason, an empty list),
 * with the exact refusal message, what is persisted and what is logged —
 * whatever shape the service's constructor takes.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\TransferListService
 */
class TransferListServiceGoldenTest extends TestCase {

	/**
	 * The collaborators, keyed by the type the service asks for.
	 *
	 * @var array<string, object>
	 */
	private array $deps = [];

	/**
	 * Every recorded call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The configured package format.
	 *
	 * @var string|null
	 */
	private ?string $format = null;

	/**
	 * Whether persisting throws.
	 *
	 * @var bool
	 */
	private bool $saveFails = false;

	/**
	 * Whether notifying throws.
	 *
	 * @var bool
	 */
	private bool $notifyFails = false;

	protected function setUp(): void {
		parent::setUp();

		$records = $this->createMock(TransferRecordService::class);
		$records->method('saveTransferList')->willReturnCallback(
			function (array $list): array {
				$this->calls[] = ['save', $this->normalise(value: $list)];
				if ($this->saveFails === true) {
					throw new \RuntimeException('store down');
				}

				return $list + ['saved' => true];
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->format ?? $default)
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$notifications->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->calls[] = ['notify'];
				if ($this->notifyFails === true) {
					throw new \RuntimeException('push down');
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['line'], $context['file']);
					$this->calls[] = [$level, (string)$message, $this->normalise(value: $context)];
				}
			);
		}

		$this->deps = [
			MagicMapperInterface::class => $this->createMock(MagicMapperInterface::class),
			AuditTrailMapperInterface::class => $this->createMock(AuditTrailMapperInterface::class),
			TransferRecordService::class => $records,
			IAppConfig::class => $config,
			INotificationManager::class => $notifications,
			LoggerInterface::class => $logger,
		];
	}//end setUp()

	/**
	 * A notification that records what is set on it.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($setter, $notification): INotification {
					$this->calls[] = [$setter, $args];
					return $notification;
				}
			);
		}

		$notification->method('setDateTime')->willReturnSelf();

		return $notification;
	}//end notification()

	/**
	 * Replace timestamps and generated uuids by markers.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
			return '<now>';
		}

		if (is_string($value) === true && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1) {
			return '<uuid>';
		}

		return $value;
	}//end normalise()

	/**
	 * Build the service from the collaborator map.
	 *
	 * @return TransferListService
	 */
	private function service(): TransferListService {
		$reflection = new ReflectionClass(TransferListService::class);
		$args       = [];
		foreach (($reflection->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$args[] = $this->deps[$type->getName()];
		}

		return $reflection->newInstanceArgs($args);
	}//end service()

	/**
	 * Run one call and record its outcome: the returned value or the refusal.
	 *
	 * @param callable $call The call.
	 *
	 * @return void
	 */
	private function outcome(callable $call): void {
		try {
			$this->calls[] = ['returned', $this->normalise(value: $call($this->service()))];
		} catch (\InvalidArgumentException $e) {
			$this->calls[] = ['refused', $e->getMessage()];
		}
	}//end outcome()

	/**
	 * A list in a given status.
	 *
	 * @param string $status The status.
	 *
	 * @return array<string, mixed>
	 */
	private static function listIn(string $status): array {
		return [
			'uuid' => 'l1',
			'status' => $status,
			'objectReferences' => [['uuid' => 'a'], ['uuid' => 'b'], ['uuid' => 'c']],
			'exclusions' => [['uuid' => 'z', 'reason' => 'earlier', 'excludedAt' => 'then']],
			'objectCount' => 3,
		];
	}//end listIn()

	/**
	 * Every status a list can hold, plus an unknown one.
	 *
	 * @return list<string>
	 */
	private static function statuses(): array {
		return [
			TransferListService::STATUS_IN_REVIEW,
			TransferListService::STATUS_APPROVED,
			TransferListService::STATUS_REJECTED,
			TransferListService::STATUS_IN_PROGRESS,
			TransferListService::STATUS_COMPLETED,
			TransferListService::STATUS_PARTIALLY_FAILED,
			TransferListService::STATUS_FAILED,
			'bogus',
		];
	}//end statuses()

	public function testApproveIsAllowedOnlyInReview(): void {
		foreach (self::statuses() as $status) {
			$this->outcome(fn (TransferListService $s): array => $s->approveTransferList(self::listIn(status: $status), 'archivist-1'));
		}

		$this->assertMatchesGolden(name: 'approve');
	}

	public function testRejectIsAllowedOnlyInReviewAndWithAReason(): void {
		foreach (self::statuses() as $status) {
			$this->outcome(fn (TransferListService $s): array => $s->rejectTransferList(self::listIn(status: $status), 'archivist-1', 'not ready'));
		}

		$this->outcome(fn (TransferListService $s): array => $s->rejectTransferList(self::listIn(status: 'in_review'), 'archivist-1', ''));
		$this->outcome(fn (TransferListService $s): array => $s->rejectTransferList(self::listIn(status: 'approved'), 'archivist-1', ''));
		$this->outcome(fn (TransferListService $s): array => $s->rejectTransferList(self::listIn(status: 'in_review'), 'archivist-1', '0'));

		$this->assertMatchesGolden(name: 'reject');
	}

	public function testExcludeIsAllowedOnlyInReview(): void {
		foreach (self::statuses() as $status) {
			$this->outcome(fn (TransferListService $s): array => $s->excludeObjects(self::listIn(status: $status), ['b', 'x'], 'legal hold'));
		}

		$single = ['uuid' => 'l2', 'status' => 'in_review', 'objectReferences' => [['uuid' => 'a']]];
		$this->outcome(fn (TransferListService $s): array => $s->excludeObjects($single, [], 'nothing'));

		$this->assertMatchesGolden(name: 'exclude');
	}

	public function testCreateRefusesNoObjectsAndFallsBackToZip(): void {
		$this->outcome(fn (TransferListService $s): array => $s->createTransferList([]));

		foreach ([null, 'bagit', 'tar'] as $format) {
			$this->format = $format;
			$this->outcome(fn (TransferListService $s): array => $s->createTransferList([$this->entity(uuid: 'a'), $this->entity(uuid: 'b')]));
		}

		$this->assertMatchesGolden(name: 'create');
	}

	public function testAFailedSaveStillReturnsTheList(): void {
		$this->saveFails = true;
		$this->outcome(fn (TransferListService $s): array => $s->approveTransferList(self::listIn(status: 'in_review'), 'archivist-2'));

		$this->assertMatchesGolden(name: 'save-failure');
	}

	public function testActiveListsAreReviewAndApprovedOnly(): void {
		$lists = [];
		foreach (self::statuses() as $status) {
			$lists[] = ['status' => $status, 'objectReferences' => [['uuid' => $status.'-1'], ['uuid' => ''], ['uuid' => 'shared']]];
		}

		$lists[] = ['objectReferences' => [['uuid' => 'no-status']]];
		$lists[] = ['status' => 'approved'];

		$this->calls[] = ['active', $this->service()->getObjectsOnActiveTransferLists($lists)];

		$this->assertMatchesGolden(name: 'active');
	}

	public function testArchivistsAreNotifiedAndAFailureIsLogged(): void {
		$this->service()->notifyArchivists(self::listIn(status: 'in_review'));
		$this->notifyFails = true;
		$this->service()->notifyArchivists(self::listIn(status: 'in_review'));

		$this->assertMatchesGolden(name: 'notify');
	}

	/**
	 * An object with a uuid, schema and register.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('7');
		$object->setRegister('3');

		return $object;
	}//end entity()

	/**
	 * Compare the recorded calls with the stored golden file.
	 *
	 * @param string $name The golden name.
	 *
	 * @return void
	 */
	private function assertMatchesGolden(string $name): void {
		$actual = json_encode($this->calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
		$path   = __DIR__.'/fixtures/transfer-list-golden/'.$name.'.json';
		if (getenv('GOLDEN_WRITE') === '1') {
			@mkdir(dirname($path), 0777, true);
			file_put_contents($path, $actual);
		}

		$this->assertFileExists($path);
		$this->assertSame(file_get_contents($path), $actual);
	}//end assertMatchesGolden()
}//end class

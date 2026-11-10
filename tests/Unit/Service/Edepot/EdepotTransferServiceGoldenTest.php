<?php

/**
 * Golden test for EdepotTransferService::executeAttempt().
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

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\PackagedFileChecksum;
use OCA\OpenRegister\Service\Edepot\SipPackageBuilder;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pins what one transfer attempt writes to an object's retention metadata,
 * which proofs it creates, which audit entries it makes and which status it
 * returns, whatever shape the service's constructor takes: the service is
 * built by reflection from a map of the collaborators below.
 *
 * Both outcomes are pinned: an object the e-Depot ACCEPTED is marked
 * transferred (and only it gets a proof and an audit entry); an object it
 * REFUSED gets a transfer error and keeps its archival state; an object
 * already transferred is never resent.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\EdepotTransferService
 */
class EdepotTransferServiceGoldenTest extends TestCase {

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
	 * The stored objects, keyed by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $store = [];

	/**
	 * What the SIP builder does: a list of package paths, or null to throw.
	 *
	 * @var list<string>|null
	 */
	private ?array $packages = [];

	/**
	 * The results the transport returns, one per send.
	 *
	 * @var list<TransportResult>
	 */
	private array $results = [];

	/**
	 * Whether proof creation throws.
	 *
	 * @var bool
	 */
	private bool $proofFails = false;

	/**
	 * A file one object references.
	 *
	 * @var string
	 */
	private string $file = '';

	protected function setUp(): void {
		parent::setUp();

		$this->file = (string)tempnam(sys_get_temp_dir(), 'edepot-golden');
		file_put_contents($this->file, 'archival bytes');

		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $id): ObjectEntity {
				$this->calls[] = ['find', $id];
				if (isset($this->store[$id]) === false) {
					throw new \RuntimeException('not found');
				}

				return clone $this->store[$id];
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $object): ObjectEntity {
				$this->calls[] = ['update', $object->getUuid(), $this->normalise(value: $object->getRetention())];
				return $object;
			}
		);

		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('createAuditTrail')->willReturnCallback(
			function (?ObjectEntity $old = null, ?ObjectEntity $new = null, ?string $action = null): AuditTrail {
				$this->calls[] = ['audit', $old?->getUuid(), $new?->getUuid(), $action];
				return new AuditTrail();
			}
		);

		$records = $this->createMock(TransferRecordService::class);
		$records->method('createProof')->willReturnCallback(
			function (array $proof): array {
				$this->calls[] = ['proof', $this->normalise(value: $proof)];
				if ($this->proofFails === true) {
					throw new \RuntimeException('proof store down');
				}

				return ['uuid' => 'proof-'.$proof['objectUuid']];
			}
		);

		$builder = $this->createMock(SipPackageBuilder::class);
		$builder->method('package')->willReturnCallback(
			function (\OCA\OpenRegister\Service\Edepot\TransferPackageRequest $request): array {
				[$transferId, $objectsWithFiles, $maxPackageSize, $format] = [$request->transferId, $request->objectsWithFiles, $request->maxPackageSize, $request->format];
				$uuids = array_map(static fn (array $item): ?string => $item['object']->getUuid(), $objectsWithFiles);
				$files = array_map(static fn (array $item): int => count($item['files']), $objectsWithFiles);
				$this->calls[] = ['build', $transferId, $uuids, $files, $maxPackageSize, $format];
				if ($this->packages === null) {
					throw new \RuntimeException('disk full');
				}

				return $this->packages;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'edepot_target_archive' => 'archive-x',
				'edepot_auth_type' => 'api_key',
				'edepot_api_key' => 'k',
				default => $default,
			}
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$notifications->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->calls[] = ['notify'];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['line'], $context['file']);
					$this->calls[] = [$level, (string)$message, $this->normalise(value: $context)];
				}
			);
		}

		$this->deps = [
			SipPackageBuilder::class => $builder,
			TransferListService::class => $this->createMock(TransferListService::class),
			TransferRecordService::class => $records,
			MagicMapperInterface::class => $mapper,
			AuditTrailMapperInterface::class => $audit,
			IAppConfig::class => $config,
			INotificationManager::class => $notifications,
			LoggerInterface::class => $logger,
			PackagedFileChecksum::class => new PackagedFileChecksum(),
		];
	}//end setUp()

	protected function tearDown(): void {
		@unlink($this->file);
		parent::tearDown();
	}//end tearDown()

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
	 * Replace every timestamp-shaped string by a marker, and the temp path by a name.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		}

		if (is_string($value) === true) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
				return '<now>';
			}

			return str_replace($this->file, '<file>', $value);
		}

		return $value;
	}//end normalise()

	/**
	 * Build a class from the collaborator map, constructing any type the map
	 * lacks from its own constructor.
	 *
	 * @param string $class The class to build.
	 *
	 * @return object
	 */
	private function build(string $class): object {
		if (isset($this->deps[$class]) === true) {
			return $this->deps[$class];
		}

		$reflection = new ReflectionClass($class);
		$args       = [];
		foreach (($reflection->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type, $class.'::'.$parameter->getName());
			$args[] = $this->build($type->getName());
		}

		return $reflection->newInstanceArgs($args);
	}//end build()

	/**
	 * Store an object.
	 *
	 * @param string               $uuid      The uuid.
	 * @param array<string, mixed> $retention The retention metadata.
	 * @param array<string, mixed> $data      The object data.
	 *
	 * @return void
	 */
	private function object(string $uuid, array $retention = [], array $data = []): void {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRetention($retention);
		$object->setObject($data);
		$this->store[$uuid] = $object;
	}//end object()

	/**
	 * Run one attempt.
	 *
	 * @param list<string> $uuids   The object references.
	 * @param int          $attempt The attempt number.
	 * @param array<string, mixed> $extra Extra list keys.
	 *
	 * @return array<string, mixed> The normalised returned list.
	 */
	private function attempt(array $uuids, int $attempt = 1, array $extra = []): array {
		$transport = $this->createMock(TransportInterface::class);
		$transport->method('getName')->willReturn('rest_api');
		$transport->method('send')->willReturnCallback(
			function (string $path, array $config): TransportResult {
				$this->calls[] = ['send', $path, $config['authenticationType'], $config['apiKey'], $config['targetArchive']];
				return array_shift($this->results);
			}
		);

		$list = array_merge(
			[
				'uuid' => 'list-1',
				'status' => TransferListService::STATUS_APPROVED,
				'objectReferences' => array_map(static fn (string $uuid): array => ['uuid' => $uuid, 'schema' => 1, 'register' => 2], $uuids),
			],
			$extra
		);

		$service = $this->build(EdepotTransferService::class);

		return $this->normalise(value: $service->executeAttempt($list, $transport, $attempt));
	}//end attempt()

	public function testAcceptedAndRefusedObjectsGetTheirOwnOutcome(): void {
		$this->object(uuid: 'a', data: ['_files' => [['path' => $this->file, 'name' => 'a.pdf', 'size' => 3, 'mimeType' => 'application/pdf']]]);
		$this->object(uuid: 'b', retention: ['archiefstatus' => 'semi_static']);
		$this->packages = ['/nonexistent/sip-1.zip'];
		$this->results  = [
			new TransportResult(
				transferReference: 'ref-1',
				objectResults: [
					'a' => ['accepted' => true, 'reference' => 'ed-a', 'error' => null, 'receipt' => 'r-a'],
					'b' => ['accepted' => false, 'reference' => null, 'error' => 'bad metadata'],
				]
			),
		];

		$list = $this->attempt(uuids: ['a', 'b'], extra: ['packageFormat' => 'bagit']);

		$this->assertSame(TransferListService::STATUS_PARTIALLY_FAILED, $list['status']);
		$this->assertMatchesGolden(name: 'accepted-and-refused', list: $list);
	}

	public function testAnAlreadyTransferredObjectIsNeverResent(): void {
		$this->object(uuid: 'a', retention: ['archiefstatus' => 'overgebracht']);
		$this->object(uuid: 'b');
		$this->packages = ['/nonexistent/sip-1.zip'];
		$this->results  = [new TransportResult(transferReference: 'ref-2')];

		$list = $this->attempt(uuids: ['a', 'b'], attempt: 2);

		$this->assertSame(TransferListService::STATUS_COMPLETED, $list['status']);
		$this->assertMatchesGolden(name: 'already-transferred', list: $list);
	}

	public function testEverythingConfirmedCompletesWithoutSending(): void {
		$this->object(uuid: 'a', retention: ['archiefstatus' => 'transferred']);

		$list = $this->attempt(uuids: ['a']);

		$this->assertSame(TransferListService::STATUS_COMPLETED, $list['status']);
		$this->assertMatchesGolden(name: 'all-confirmed', list: $list);
	}

	public function testAnUnloadableObjectFailsTheList(): void {
		$this->object(uuid: 'a', retention: ['archiefstatus' => 'transferred']);

		$list = $this->attempt(uuids: ['a', 'ghost']);

		$this->assertSame(TransferListService::STATUS_FAILED, $list['status']);
		$this->assertMatchesGolden(name: 'unloadable', list: $list);
	}

	public function testAnEmptyListFails(): void {
		$list = $this->attempt(uuids: []);

		$this->assertSame(TransferListService::STATUS_FAILED, $list['status']);
		$this->assertMatchesGolden(name: 'empty', list: $list);
	}

	public function testABuildFailureFailsTheListAndMarksNothing(): void {
		$this->object(uuid: 'a');
		$this->packages = null;

		$list = $this->attempt(uuids: ['a']);

		$this->assertSame(TransferListService::STATUS_FAILED, $list['status']);
		$this->assertMatchesGolden(name: 'build-failure', list: $list);
	}

	public function testAnOverallRefusalWithoutPerObjectResultsFailsEveryObject(): void {
		$this->object(uuid: 'a');
		$this->object(uuid: 'b');
		$this->packages = ['/nonexistent/sip-1.zip', '/nonexistent/sip-2.zip'];
		$this->results  = [
			(new TransportResult(transferReference: 'ref-3'))->failed('endpoint said no'),
			new TransportResult(transferReference: 'ref-4'),
		];

		$list = $this->attempt(uuids: ['a', 'b']);

		$this->assertSame(TransferListService::STATUS_FAILED, $list['status']);
		$this->assertMatchesGolden(name: 'overall-refusal', list: $list);
	}

	public function testAProofFailureNeverUnwindsTheTransfer(): void {
		$this->object(uuid: 'a');
		$this->packages   = ['/nonexistent/sip-1.zip'];
		$this->results    = [new TransportResult(objectResults: ['a' => ['accepted' => true, 'reference' => 'ed-a', 'error' => null]])];
		$this->proofFails = true;

		$list = $this->attempt(uuids: ['a']);

		$this->assertSame(TransferListService::STATUS_COMPLETED, $list['status']);
		$this->assertMatchesGolden(name: 'proof-failure', list: $list);
	}

	public function testAnObjectMissingFromThePerObjectResultsIsRefused(): void {
		$this->object(uuid: 'a');
		$this->object(uuid: 'b', retention: ['transferErrors' => [['error' => 'earlier', 'timestamp' => 'then']]]);
		$this->packages = ['/nonexistent/sip-1.zip'];
		$this->results  = [new TransportResult(objectResults: ['a' => ['accepted' => true, 'reference' => null, 'error' => null]])];

		$list = $this->attempt(uuids: ['a', 'b']);

		$this->assertSame(TransferListService::STATUS_PARTIALLY_FAILED, $list['status']);
		$this->assertMatchesGolden(name: 'missing-result', list: $list);
	}

	/**
	 * Compare the returned list and the recorded calls with the stored golden file.
	 *
	 * @param string               $name The golden name.
	 * @param array<string, mixed> $list The returned list.
	 *
	 * @return void
	 */
	private function assertMatchesGolden(string $name, array $list): void {
		$actual = json_encode(['list' => $list, 'calls' => $this->calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
		$path   = __DIR__.'/fixtures/transfer-golden/'.$name.'.json';
		if (getenv('GOLDEN_WRITE') === '1') {
			@mkdir(dirname($path), 0777, true);
			file_put_contents($path, $actual);
		}

		$this->assertFileExists($path);
		$this->assertSame(file_get_contents($path), $actual);
	}//end assertMatchesGolden()
}//end class

<?php

/**
 * Golden test for ScheduledReportService::runOne delivery.
 *
 * Records every call the run makes on Files, the mailer, the export-run recorder,
 * the report mapper and the notification manager, per scenario, in
 * fixtures/scheduled-report-run-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ScheduledReportService
 * @covers \OCA\OpenRegister\Service\ScheduledReportRun
 * @uses   \OCA\OpenRegister\Notifications\Db\ScheduledReport
 * @uses   \OCA\OpenRegister\Db\ExportRun
 * @uses   \OCA\OpenRegister\Db\Register
 */
class ScheduledReportServiceRunGoldenTest extends TestCase {

	/**
	 * Every call made on a spy, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * Scenarios: [mode, options].
	 *
	 * Options: big (oversize export), fileExists, folder, recipients, ownerEmail,
	 * sendFails, recorder (none|ok|throws), newFileFails.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		return [
			'files new file' => ['files', []],
			'files existing file' => ['files', ['fileExists' => true]],
			'files empty folder' => ['files', ['folder' => '/']],
			'files traversal' => ['files', ['folder' => '../x']],
			'files no recorder' => ['files', ['recorder' => 'none']],
			'files recorder throws' => ['files', ['recorder' => 'throws']],
			'email small' => ['email', []],
			'email recipients' => ['email', ['recipients' => ['a@x.nl', 'b@x.nl']]],
			'email oversize' => ['email', ['big' => true]],
			'email oversize fallback fails' => ['email', ['big' => true, 'newFileFails' => true]],
			'email no recipient' => ['email', ['ownerEmail' => null]],
			'email send fails' => ['email', ['sendFails' => true]],
			'both small' => ['both', []],
			'both oversize' => ['both', ['big' => true]],
			'both send fails' => ['both', ['sendFails' => true]],
			'both no recipient' => ['both', ['ownerEmail' => null]],
			'null mode' => ['', []],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testRunGolden(string $mode, array $opt): void {
		$this->calls = [];
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$owner->method('getDisplayName')->willReturn('Alice');
		$owner->method('getEMailAddress')->willReturn(array_key_exists('ownerEmail', $opt) === true ? $opt['ownerEmail'] : 'alice@example.com');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($owner);

		$csv = "id,name\n1,a\n2,b\n";
		if (($opt['big'] ?? false) === true) {
			$csv .= str_repeat('x', 11 * 1024 * 1024);
		}

		$export = $this->createMock(ExportService::class);
		$export->method('exportToCsv')->willReturn($csv);
		$register = new Register();
		$register->setTitle('Cases');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$mapper = $this->spy(ScheduledReportMapper::class, 'mapper', ['update' => fn ($e) => $e]);
		$mailer = $this->spy(
			IMailer::class,
			'mailer',
			[
				'createEMailTemplate' => fn () => $this->spy(IEMailTemplate::class, 'template'),
				'createMessage' => fn () => $this->spy(IMessage::class, 'message'),
				'createAttachment' => fn () => $this->createMock(IAttachment::class),
				'send' => function () use ($opt) {
					if (($opt['sendFails'] ?? false) === true) {
						throw new RuntimeException('smtp down');
					}

					return [];
				},
			]
		);
		$notifications = $this->spy(IManager::class, 'notifications', ['createNotification' => fn () => $this->spy(INotification::class, 'notification')]);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn ($key, $default = null) => $default);

		$recorder = null;
		if (($opt['recorder'] ?? 'ok') !== 'none') {
			$recorder = $this->getMockBuilder(ExportRunRecorder::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock();
			$recorder->method('record')->willReturnCallback(
				function (...$args) use ($opt) {
					// The fixture pins the run's fields in the order record() took
					// them before they were grouped; flatten the groups back.
					[$origin, $content, $file, $retention] = $args;
					$fields = [
						$origin->source,
						$origin->actor,
						$content->format,
						$content->rowCount,
						$origin->profile,
						$file->filename,
						$content->registerName,
						$content->schemaName,
						$file->fileId,
						$file->filePath,
						$retention,
						$file->downloadCount,
						$origin->status,
					];
					$this->calls[] = ['recorder.record', $this->plain($fields)];
					if (($opt['recorder'] ?? 'ok') === 'throws') {
						throw new RuntimeException('no table');
					}

					return new \OCA\OpenRegister\Db\ExportRun();
				}
			);
		}

		$service = new ScheduledReportService(
			$mapper,
			$registers,
			$this->createMock(SchemaMapper::class),
			$export,
			$this->rootFolderFor($opt),
			$users,
			$this->createMock(IUserSession::class),
			$notifications,
			$this->createMock(LoggerInterface::class),
			$mailer,
			$config,
			null,
			$recorder
		);

		$report = $this->report($mode, $opt);
		$service->runOne($report);
		$this->calls[] = ['report', [$report->getLastStatus(), $report->getLastError()]];

		$recorded = json_encode($this->calls, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
		$actual = json_decode(preg_replace('/(weekly-cases_)\d{4}-\d{2}-\d{2}/', '$1TODAY', $recorded), true);
		$file = __DIR__ . '/fixtures/scheduled-report-run-golden.json';
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
	 * A stored report row.
	 *
	 * @param string $mode Delivery mode, '' for none.
	 * @param array  $opt  Scenario options.
	 *
	 * @return ScheduledReport
	 */
	private function report(string $mode, array $opt): ScheduledReport {
		$report = new ScheduledReport();
		$prop = (new ReflectionClass($report))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($report, 7);
		$report->setOwner('alice');
		$report->setName('Weekly cases');
		$report->setRegisterId(1);
		$report->setFilters('[]');
		$report->setFormat('csv');
		$report->setScheduleType('weekly');
		$report->setDeliveryFolder($opt['folder'] ?? 'Reports/');
		if ($mode !== '') {
			$report->setDeliveryMode($mode);
		}

		$report->setRecipients(json_encode($opt['recipients'] ?? []));
		return $report;
	}

	/**
	 * A root folder whose user folder records its calls.
	 *
	 * @param array $opt Scenario options.
	 *
	 * @return IRootFolder
	 */
	private function rootFolderFor(array $opt): IRootFolder {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(55);
		$file->method('getPath')->willReturn('/alice/files/Reports/x.csv');
		$file->method('putContent')->willReturnCallback(
			function ($data) {
				$this->calls[] = ['file.putContent', [strlen($data)]];
			}
		);
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn($opt['fileExists'] ?? false);
		$folder->method('get')->willReturn($file);
		$folder->method('newFile')->willReturnCallback(
			function ($path, $content) use ($file, $opt) {
				$this->calls[] = ['folder.newFile', [$path, strlen((string)$content)]];
				if (($opt['newFileFails'] ?? false) === true) {
					throw new RuntimeException('quota');
				}

				return $file;
			}
		);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->method('newFolder')->willReturnCallback(
			function ($path) use ($folder) {
				$this->calls[] = ['userFolder.newFolder', [$path]];
				return $folder;
			}
		);
		$userFolder->method('get')->willReturnCallback(
			function ($path) use ($folder) {
				$this->calls[] = ['userFolder.get', [$path]];
				return $folder;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function ($uid) use ($userFolder) {
				$this->calls[] = ['root.getUserFolder', [$uid]];
				return $userFolder;
			}
		);
		return $root;
	}

	/**
	 * A mock of $class recording every call of a void, untyped or fluent method, or of a method in $returns.
	 *
	 * @param string $class   Class or interface.
	 * @param string $label   Name used in the record.
	 * @param array  $returns Method => callable giving its return value.
	 *
	 * @return mixed
	 */
	private function spy(string $class, string $label, array $returns = []): mixed {
		$mock = $this->createMock($class);
		foreach ((new ReflectionClass($class))->getMethods() as $method) {
			$name = $method->getName();
			if ($method->isConstructor() === true || $method->isStatic() === true || $method->isPublic() === false) {
				continue;
			}

			$type = $method->getReturnType();
			$typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
			$fluent = in_array($typeName, ['self', 'static', $class], true);
			if (isset($returns[$name]) === false && $fluent === false && $typeName !== 'void' && $type !== null) {
				continue;
			}

			$mock->method($name)->willReturnCallback(
				function (...$args) use ($label, $name, $returns, $fluent, $mock) {
					$this->calls[] = [$label . '.' . $name, $this->plain($args)];
					if (isset($returns[$name]) === true) {
						return $returns[$name](...$args);
					}

					return $fluent === true ? $mock : null;
				}
			);
		}

		return $mock;
	}

	/**
	 * Arguments reduced to JSON-safe values: objects to their class, long strings to their length.
	 *
	 * @param array $args The arguments.
	 *
	 * @return array
	 */
	private function plain(array $args): array {
		$out = [];
		foreach ($args as $key => $arg) {
			if ($arg instanceof ScheduledReport) {
				$out[$key] = ['report', $arg->getLastStatus(), $arg->getLastError()];
			} elseif (is_object($arg) === true) {
				$out[$key] = 'object';
			} elseif (is_string($arg) === true && strlen($arg) > 200) {
				$out[$key] = 'bytes:' . strlen($arg);
			} elseif (is_array($arg) === true) {
				$out[$key] = $this->plain($arg);
			} else {
				$out[$key] = $arg;
			}
		}

		return $out;
	}
}

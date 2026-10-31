<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Listener;

use OCA\OpenRegister\Listener\FileChangeListener;
use OCA\OpenRegister\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden test for FileChangeListener::handle().
 *
 * Each case runs one or more file events through the listener; the recording holds
 * every log call (level, message, context without file/line/trace), every queued job,
 * how often the settings were read and any exception that left handle().
 * Compared with fixtures/file-change-listener-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Listener\FileChangeListener
 */
class FileChangeListenerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/file-change-listener-golden.json';

	private const OR_PATH = '/admin/files/Open Registers/test.pdf';

	/**
	 * Case name => [settings (array, or 'throw'), add behaviour ('ok'|'throw'), events].
	 * An event is [kind, path, name, id] where kind is created|written|folder|other.
	 *
	 * @return array<string, array{0: array<string, string>|string, 1: string, 2: list<array{0: string, 1?: string, 2?: string, 3?: int}>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['immediate', 'background', 'cron', 'manual', 'unknown-mode'] as $mode) {
			foreach (['ok', 'throw'] as $add) {
				$cases["objects/$mode/add-$add"] = [
					['extractionScope' => 'objects', 'extractionMode' => $mode],
					$add,
					[['created', self::OR_PATH, 'test.pdf', 42]],
				];
			}
		}

		$cases['settings throw, defaults'] = ['throw', 'ok', [['created', self::OR_PATH, 'test.pdf', 7]]];
		$cases['settings empty, defaults'] = [[], 'ok', [['written', '/u/files/OpenRegister/files/a.txt', 'a.txt', 8]]];
		$cases['scope all, outside path'] = [
			['extractionScope' => 'all', 'extractionMode' => 'background'],
			'ok',
			[['written', '/u/files/Documents/x.pdf', 'x.pdf', 9]],
		];
		$cases['scope objects, outside path'] = [
			['extractionScope' => 'objects', 'extractionMode' => 'background'],
			'ok',
			[['created', '/u/files/Documents/x.pdf', 'x.pdf', 10]],
		];
		$cases['scope none'] = [
			['extractionScope' => 'none', 'extractionMode' => 'immediate'],
			'ok',
			[['created', self::OR_PATH, 'test.pdf', 11]],
		];
		$cases['anonymized file'] = [
			['extractionScope' => 'all', 'extractionMode' => 'background'],
			'ok',
			[['created', self::OR_PATH, 'doc_anonymized.pdf', 12]],
		];
		$cases['folder node'] = [
			['extractionScope' => 'all', 'extractionMode' => 'background'],
			'ok',
			[['folder']],
		];
		$cases['other event'] = [
			['extractionScope' => 'all', 'extractionMode' => 'background'],
			'ok',
			[['other']],
		];
		$cases['settings cached across events'] = [
			['extractionScope' => 'all', 'extractionMode' => 'immediate'],
			'ok',
			[
				['created', self::OR_PATH, 'one.pdf', 13],
				['written', '/u/files/Documents/two.pdf', 'two.pdf', 14],
			],
		];

		return $cases;
	}//end cases()

	/**
	 * @param array<string, string>|string                            $settings Settings the service returns, or 'throw'
	 * @param string                                                  $add      Job list behaviour, 'ok' or 'throw'
	 * @param list<array{0: string, 1?: string, 2?: string, 3?: int}> $events   Events to handle, in order
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testHandleMatchesGolden(array|string $settings, string $add, array $events): void {
		foreach (['cachedExtractScope', 'cachedExtractionMode'] as $property) {
			$ref = new \ReflectionProperty(FileChangeListener::class, $property);
			$ref->setValue(null, null);
		}

		$trace = [];
		$logger = new class($trace) extends AbstractLogger {
			/** @param list<mixed> $trace */
			public function __construct(private array &$trace) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				unset($context['file'], $context['line'], $context['trace']);
				if (isset($context['event_type']) === true) {
					$context['event_type'] = get_parent_class($context['event_type']);
				}

				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		$settingsService = $this->createMock(SettingsService::class);
		$settingsReads = 0;
		$settingsService->method('getFileSettingsOnly')->willReturnCallback(
			static function () use ($settings, &$settingsReads): array {
				$settingsReads++;
				if ($settings === 'throw') {
					throw new \RuntimeException('settings unavailable');
				}

				return $settings;
			}
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			static function ($job, $argument = null) use ($add, &$trace): void {
				$trace[] = ['add', $job, $argument];
				if ($add === 'throw') {
					throw new \RuntimeException('job list down');
				}
			}
		);

		$listener = new FileChangeListener($settingsService, $jobList, $logger);
		foreach ($events as $spec) {
			try {
				$listener->handle($this->event($spec));
			} catch (\Throwable $e) {
				$trace[] = ['thrown', get_class($e), $e->getMessage()];
			}
		}

		$trace[] = ['settingsReads', $settingsReads];
		$actual = json_decode(json_encode($trace), true);

		$name = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testHandleMatchesGolden()

	/**
	 * @param array{0: string, 1?: string, 2?: string, 3?: int} $spec Event kind, path, name and id
	 *
	 * @return Event
	 */
	private function event(array $spec): Event {
		if ($spec[0] === 'other') {
			return $this->createMock(Event::class);
		}

		if ($spec[0] === 'folder') {
			$event = $this->createMock(NodeCreatedEvent::class);
			$event->method('getNode')->willReturn($this->createMock(Folder::class));
			return $event;
		}

		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($spec[1]);
		$file->method('getName')->willReturn($spec[2]);
		$file->method('getId')->willReturn($spec[3]);

		$class = NodeCreatedEvent::class;
		if ($spec[0] === 'written') {
			$class = NodeWrittenEvent::class;
		}

		$event = $this->createMock($class);
		$event->method('getNode')->willReturn($file);
		return $event;
	}//end event()
}//end class

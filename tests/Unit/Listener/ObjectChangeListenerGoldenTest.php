<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Listener\ObjectChangeListener;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden recording of ObjectChangeListener::handle(): every log call (level,
 * message, context without file/line/trace), every extraction or queued job,
 * and how often the settings were read, per extraction mode and failure.
 * Recorded on the code before the A29 refactor.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
class ObjectChangeListenerGoldenTest extends TestCase {

	/**
	 * Case name => [settings ('throw' or the array), work behaviour ('ok'|'throw'), event kind, object id].
	 *
	 * @return array<string, array{0: array<string, mixed>|string, 1: string, 2: string, 3: int|null}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['immediate', 'background', 'cron', 'manual', 'other'] as $mode) {
			foreach (['ok', 'throw'] as $work) {
				$cases["$mode/$work"] = [['extractionMode' => $mode], $work, 'created', 42];
			}
		}

		$cases['default mode, updated'] = [[], 'ok', 'updated', 7];
		$cases['settings throw'] = ['throw', 'ok', 'created', 8];
		$cases['no object id'] = [['extractionMode' => 'immediate'], 'ok', 'created', null];
		$cases['unrelated event'] = [['extractionMode' => 'immediate'], 'ok', 'other', 9];

		return $cases;
	}//end cases()

	/**
	 * The expected recording per case.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function golden(): array {
		$detected = static fn (string $event, ?int $id): array => [
			'log', 'debug', '[ObjectChangeListener] Object event detected',
			['event_type' => $event, 'object_id' => $id, 'object_uuid' => 'uuid-1'],
		];
		$created = ObjectCreatedEvent::class;
		$fail = static fn (string $message): array => ['log', 'error', $message, ['object_id' => 42, 'error' => 'work failed']];
		$immediateStart = ['log', 'info', '[ObjectChangeListener] Immediate mode - processing synchronously', ['object_id' => 42, 'object_uuid' => 'uuid-1']];
		$backgroundStart = ['log', 'info', '[ObjectChangeListener] Background mode - queueing extraction job', ['object_id' => 42, 'object_uuid' => 'uuid-1']];
		$unknown = ['log', 'warning', '[ObjectChangeListener] Unknown extraction mode, defaulting to background', ['object_id' => 42, 'extraction_mode' => 'other']];
		$job = ['add', 'OCA\\OpenRegister\\BackgroundJob\\ObjectTextExtractionJob', ['object_id' => 42]];
		$cron = ['log', 'debug', '[ObjectChangeListener] Cron mode - skipping, will be processed by scheduled job', ['object_id' => 42]];
		$manual = ['log', 'debug', '[ObjectChangeListener] Manual mode - skipping, requires manual trigger', ['object_id' => 42]];

		return [
			'immediate/ok' => [
				$detected($created, 42), $immediateStart, ['extract', 42, false],
				['log', 'info', '[ObjectChangeListener] Immediate extraction completed', ['object_id' => 42]], ['reads', 1],
			],
			'immediate/throw' => [$detected($created, 42), $immediateStart, ['extract', 42, false], $fail('[ObjectChangeListener] Immediate extraction failed'), ['reads', 1]],
			'background/ok' => [
				$detected($created, 42), $backgroundStart, $job,
				['log', 'debug', '[ObjectChangeListener] Background extraction job queued', ['object_id' => 42]], ['reads', 1],
			],
			'background/throw' => [$detected($created, 42), $backgroundStart, $job, $fail('[ObjectChangeListener] Failed to queue background job'), ['reads', 1]],
			'cron/ok' => [$detected($created, 42), $cron, ['reads', 1]],
			'cron/throw' => [$detected($created, 42), $cron, ['reads', 1]],
			'manual/ok' => [$detected($created, 42), $manual, ['reads', 1]],
			'manual/throw' => [$detected($created, 42), $manual, ['reads', 1]],
			'other/ok' => [$detected($created, 42), $unknown, $job, ['reads', 1]],
			'other/throw' => [
				$detected($created, 42), $unknown, $job,
				['log', 'error', '[ObjectChangeListener] Error determining extraction mode', ['object_id' => 42, 'error' => 'work failed']], ['reads', 1],
			],
			'default mode, updated' => [
				$detected(ObjectUpdatedEvent::class, 7),
				['log', 'info', '[ObjectChangeListener] Background mode - queueing extraction job', ['object_id' => 7, 'object_uuid' => 'uuid-1']],
				['add', 'OCA\\OpenRegister\\BackgroundJob\\ObjectTextExtractionJob', ['object_id' => 7]],
				['log', 'debug', '[ObjectChangeListener] Background extraction job queued', ['object_id' => 7]],
				['reads', 1],
			],
			'settings throw' => [
				$detected($created, 8),
				['log', 'error', '[ObjectChangeListener] Error determining extraction mode', ['object_id' => 8, 'error' => 'settings unavailable']],
				['reads', 1],
			],
			'no object id' => [
				$detected($created, null),
				['log', 'debug', '[ObjectChangeListener] Skipping extraction for object without ID (magic mapper?)', []],
				['reads', 1],
			],
			'unrelated event' => [['reads', 0]],
		];
	}//end golden()

	/**
	 * Run the case and compare with the recording.
	 *
	 * @param array<string, mixed>|string $settings The settings, or 'throw'.
	 * @param string $work Extraction and job behaviour.
	 * @param string $kind Event kind.
	 * @param int|null $objectId The object id.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testHandleMatchesGolden(array|string $settings, string $work, string $kind, ?int $objectId): void {
		$trace = [];
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Record into the trace.
			 *
			 * @param array<int, mixed> $trace The trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record one log call.
			 *
			 * @param mixed $level The level.
			 * @param \Stringable|string $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, \Stringable|string $message, array $context = []): void {
				unset($context['file'], $context['line'], $context['trace']);
				$this->trace[] = ['log', $level, (string)$message, $context];
			}
		};

		$reads = 0;
		$settingsService = $this->createMock(AppSettingsInterface::class);
		$settingsService->method('getFileSettingsOnly')->willReturnCallback(
			static function () use ($settings, &$reads): array {
				$reads++;
				if ($settings === 'throw') {
					throw new RuntimeException('settings unavailable');
				}

				return $settings;
			}
		);
		$extract = $this->createMock(TextExtractionService::class);
		$extract->method('extractObject')->willReturnCallback(
			static function (int $objectId, bool $forceReExtract) use ($work, &$trace): void {
				$trace[] = ['extract', $objectId, $forceReExtract];
				if ($work === 'throw') {
					throw new RuntimeException('work failed');
				}
			}
		);
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			static function ($job, $argument = null) use ($work, &$trace): void {
				$trace[] = ['add', $job, $argument];
				if ($work === 'throw') {
					throw new RuntimeException('work failed');
				}
			}
		);

		$object = new ObjectEntity();
		$object->setId($objectId);
		$object->setUuid('uuid-1');
		$event = match ($kind) {
			'created' => new ObjectCreatedEvent($object),
			'updated' => new ObjectUpdatedEvent($object, new ObjectEntity()),
			default => $this->createMock(Event::class),
		};

		// Kill switch on (`listenerDeferral=inline`): the per-object behaviour this recording holds.
		$deferral = $this->createStub(ListenerDeferralInterface::class);
		$deferral->method('isDeferralEnabled')->willReturn(false);
		(new ObjectChangeListener($extract, $settingsService, $jobList, $logger, $deferral, $this->createStub(IAppConfig::class)))->handle($event);
		$trace[] = ['reads', $reads];

		$this->assertSame(self::golden()[$this->dataName()], $trace);
	}//end testHandleMatchesGolden()
}//end class

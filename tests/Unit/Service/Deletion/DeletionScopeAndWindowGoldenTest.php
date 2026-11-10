<?php

/**
 * Golden tests for the retention window, the declared destruction scope and
 * the destruction act.
 *
 * Pins the outcomes the deletion guards hand out: which retention rule wins
 * and when a value is ignored, which declared members are kept, reported or
 * dropped, the refusal of a scope nobody can honour (rule, status, message,
 * context) and a destruction that carries on past a member that fails,
 * reporting and logging it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Service\Deletion\DeletionWindow;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Deletion\DestructionCompanions;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScope;
use OCA\OpenRegister\Service\Deletion\DestructionScopeReader;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Edepot\AuditRecording;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class DeletionScopeAndWindowGoldenTest extends TestCase {

	/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> Log calls, as level, message, context. */
	private array $logs = [];

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->logs[] = [$level, $message, $context];
			});
		}

		return $logger;
	}

	private function windows(mixed $instance): DeletionWindowService {
		$handler = $this->createMock(ObjectRetentionHandler::class);
		if ($instance instanceof RuntimeException) {
			$handler->method('getRetentionSettingsOnly')->willThrowException($instance);
		} else {
			$handler->method('getRetentionSettingsOnly')->willReturn($instance === null ? [] : ['objectDeleteRetention' => $instance]);
		}

		return new DeletionWindowService($handler, $this->logger());
	}

	private function schema(array $archive): Schema {
		$schema = new Schema();
		$schema->setArchive($archive);

		return $schema;
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-7');

		return $object;
	}

	public function testASchemaRetentionWinsWhenItIsAPositiveNumber(): void {
		$this->assertSame(['days' => 12, 'source' => DeletionWindow::SOURCE_SCHEMA], $this->windows(864000000)->retentionFor($this->schema(['deleteRetention' => '12'])));
		$this->assertSame(['days' => 3, 'source' => DeletionWindow::SOURCE_SCHEMA], $this->windows(864000000)->retentionFor($this->schema(['deleteRetention' => 3.9])));
	}

	public function testANonNumericOrSubDaySchemaRetentionFallsToTheInstance(): void {
		foreach (['soon', '0.5', -4, null, true] as $declared) {
			$this->assertSame(
				['days' => 10, 'source' => DeletionWindow::SOURCE_INSTANCE],
				$this->windows(864000000)->retentionFor($this->schema(['deleteRetention' => $declared])),
				var_export($declared, true)
			);
		}
	}

	public function testAnInstanceRetentionUnderADayFallsToTheDefault(): void {
		$default = ['days' => DeletionWindowService::DEFAULT_RETENTION_DAYS, 'source' => DeletionWindow::SOURCE_DEFAULT];

		$this->assertSame($default, $this->windows(86399999)->retentionFor(null));
		$this->assertSame($default, $this->windows('a while')->retentionFor(null));
		$this->assertSame($default, $this->windows(-86400000)->retentionFor(null));
		$this->assertSame(['days' => 1, 'source' => DeletionWindow::SOURCE_INSTANCE], $this->windows('172799999')->retentionFor(null));
		$this->assertSame([], $this->logs);
	}

	public function testAFailingSettingsReadIsLoggedAndFallsToTheDefault(): void {
		$this->assertSame(
			['days' => DeletionWindowService::DEFAULT_RETENTION_DAYS, 'source' => DeletionWindow::SOURCE_DEFAULT],
			$this->windows(new RuntimeException('config down'))->retentionFor($this->schema([]))
		);
		$this->assertSame(
			[['warning', '[DeletionWindow] Could not read objectDeleteRetention, using the default', ['error' => 'config down']]],
			$this->logs
		);
	}

	public function testAScopeIsReadKeepingKnownMembersOnceAndReportingTheRest(): void {
		$reader = new DestructionScopeReader();
		$none = ['scope' => [], 'unknown' => []];

		$this->assertSame($none, $reader->declaredOn(null));
		$this->assertSame($none, $reader->declaredOn($this->schema([])));
		$this->assertSame($none, $reader->declaredOn($this->schema([DestructionScope::SCHEMA_KEY => 'notes'])));
		$this->assertSame(
			['scope' => [DestructionScope::NOTES, DestructionScope::FILES], 'unknown' => ['everything', '']],
			$reader->declaredOn(
				$this->schema(
					[
						DestructionScope::SCHEMA_KEY => [
							' ' . DestructionScope::NOTES . ' ',
							42,
							'everything',
							DestructionScope::FILES,
							DestructionScope::NOTES,
							['nested'],
							'everything',
							'  ',
						],
					]
				)
			)
		);
	}

	public function testAScopeNobodyCanHonourIsRefusedWithItsRuleAndMembers(): void {
		$service = new DestructionScopeService(new AuditRecording($this->createMock(AuditTrailMapper::class), $this->logger()), new DestructionScopeReader(), new DestructionCompanions());

		try {
			$service->destroy($this->object(), $this->schema([DestructionScope::SCHEMA_KEY => ['everything', DestructionScope::NOTES, DestructionScope::VERSIONS]]));
			$this->fail('A scope naming members nobody can honour must be refused.');
		} catch (DestructionRefusedException $refusal) {
			$this->assertSame('destruction-scope-unclear', $refusal->getRule());
			$this->assertSame(409, $refusal->getStatusCode());
			$this->assertSame(
				[
					'error' => 'DESTRUCTION_REFUSED',
					'rule' => 'destruction-scope-unclear',
					'message' => 'The declared destruction scope names members this instance cannot honour: everything, notes.',
					'unknown' => ['everything'],
					'unavailable' => [DestructionScope::NOTES],
				],
				$refusal->toResponseBody()
			);
		}

		$this->assertSame([], $this->logs);
	}

	public function testAFailingMemberIsReportedAndLoggedWhileTheOthersStillGo(): void {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('countObjectTrail')->willReturn(3);
		$mapper->method('tombstoneObjectTrail')->willReturn(3);

		$notes = $this->createMock(NoteService::class);
		$notes->method('countNotesForObject')->willReturn(2);
		$notes->method('deleteNotesForObject')->willThrowException(new RuntimeException('notes locked'));

		$service = new DestructionScopeService(new AuditRecording($mapper, $this->logger()), new DestructionScopeReader(), new DestructionCompanions($notes));

		$report = $service->destroy(
			$this->object(),
			$this->schema([DestructionScope::SCHEMA_KEY => [DestructionScope::VERSIONS, DestructionScope::NOTES, DestructionScope::AUDIT_CONTENT]])
		);

		$this->assertSame(
			[
				'scope' => [DestructionScope::VERSIONS, DestructionScope::NOTES, DestructionScope::AUDIT_CONTENT],
				'destroyed' => [DestructionScope::VERSIONS => 3, DestructionScope::AUDIT_CONTENT => 3],
				'total' => 6,
				'failed' => [DestructionScope::NOTES => 'notes locked'],
			],
			$report
		);
		$errors = array_values(array_filter($this->logs, static fn (array $log): bool => $log[0] === 'error'));
		$this->assertSame(
			[['error', '[DestructionScope] Failed to destroy scope member', ['member' => DestructionScope::NOTES, 'uuid' => 'zaak-7', 'error' => 'notes locked']]],
			$errors
		);
	}
}//end class

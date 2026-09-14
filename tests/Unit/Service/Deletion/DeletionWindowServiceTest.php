<?php

/**
 * Unit tests for DeletionWindowService — the stated recovery window.
 *
 * Covers the three-step retention rule (schema, instance, default), the
 * window published on a soft-deleted object, the refusal a reader gets from
 * the normal object path, and the legacy row that carries no window at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DeletionWindow;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DeletionWindowServiceTest extends TestCase {
	private function service(?int $instanceMilliseconds = null): DeletionWindowService {
		$handler = $this->createMock(ObjectRetentionHandler::class);
		$settings = [];
		if ($instanceMilliseconds !== null) {
			$settings = ['objectDeleteRetention' => $instanceMilliseconds];
		}

		$handler->method('getRetentionSettingsOnly')->willReturn($settings);

		return new DeletionWindowService($handler, new NullLogger());
	}//end service()

	private function schemaWithRetention(?int $days): Schema {
		$schema = new Schema();
		$archive = [];
		if ($days !== null) {
			$archive = ['deleteRetention' => $days];
		}

		$schema->setArchive($archive);

		return $schema;
	}//end schemaWithRetention()

	private function deletedObject(array $metadata): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('melding-1');
		$object->setDeleted($metadata);

		return $object;
	}//end deletedObject()

	public function testTheWindowIsPerSchemaWithADefault(): void {
		// The schema wins.
		self::assertSame(
			['days' => 365, 'source' => DeletionWindow::SOURCE_SCHEMA],
			$this->service(2592000000)->retentionFor($this->schemaWithRetention(365))
		);

		// The instance answers when the schema does not. 30 days in ms.
		self::assertSame(
			['days' => 30, 'source' => DeletionWindow::SOURCE_INSTANCE],
			$this->service(2592000000)->retentionFor($this->schemaWithRetention(null))
		);

		// Neither: the built-in default.
		self::assertSame(
			['days' => DeletionWindowService::DEFAULT_RETENTION_DAYS, 'source' => DeletionWindow::SOURCE_DEFAULT],
			$this->service(null)->retentionFor(null)
		);
	}//end testTheWindowIsPerSchemaWithADefault()

	public function testANonPositiveRetentionFallsBackRatherThanDestroyingImmediately(): void {
		self::assertSame(
			DeletionWindowService::DEFAULT_RETENTION_DAYS,
			$this->service(0)->retentionFor($this->schemaWithRetention(0))['days']
		);
	}//end testANonPositiveRetentionFallsBackRatherThanDestroyingImmediately()

	public function testACaseworkerSeesHowLongTheyHave(): void {
		$deletedAt = new DateTimeImmutable('2026-03-19T09:00:00+00:00');
		$service = $this->service(2592000000);

		$metadata = $service->openWindow($this->schemaWithRetention(null), $deletedAt);
		self::assertSame(30, $metadata['retentionPeriod']);
		self::assertSame('2026-04-18T09:00:00+00:00', $metadata['destroyableFrom']);
		self::assertSame($metadata['destroyableFrom'], $metadata['purgeDate']);

		$object = $this->deletedObject(
			array_merge(['deletedAt' => $deletedAt->format(DATE_ATOM), 'deletedBy' => 'admin'], $metadata)
		);

		$window = $service->windowFor($object, null, $deletedAt);
		self::assertNotNull($window);
		self::assertSame(30, $window->daysRemaining());
		self::assertSame('2026-04-18T09:00:00+00:00', $window->destroyableFrom()->format(DATE_ATOM));
		self::assertFalse($window->hasLapsed());
	}//end testACaseworkerSeesHowLongTheyHave()

	public function testTheWindowLapsesAndThenReportsZero(): void {
		$deletedAt = new DateTimeImmutable('2026-03-19T09:00:00+00:00');
		$service = $this->service(2592000000);
		$object = $this->deletedObject(
			array_merge(
				['deletedAt' => $deletedAt->format(DATE_ATOM)],
				$service->openWindow(null, $deletedAt)
			)
		);

		$window = $service->windowFor($object, null, new DateTimeImmutable('2026-05-01T09:00:00+00:00'));
		self::assertNotNull($window);
		self::assertSame(0, $window->daysRemaining());
		self::assertTrue($window->hasLapsed());
	}//end testTheWindowLapsesAndThenReportsZero()

	public function testALiveObjectHasNoWindow(): void {
		$object = new ObjectEntity();
		$object->setUuid('melding-2');

		self::assertNull($this->service(2592000000)->windowFor($object));
	}//end testALiveObjectHasNoWindow()

	public function testALegacyRowWithoutAWindowStillGetsOne(): void {
		$deletedAt = new DateTimeImmutable('2026-03-19T09:00:00+00:00');
		$object = $this->deletedObject(['deletedAt' => $deletedAt->format(DATE_ATOM), 'deletedBy' => 'admin']);

		$window = $this->service(2592000000)->windowFor($object, null, $deletedAt);
		self::assertNotNull($window);
		self::assertSame(30, $window->daysRemaining());
		self::assertSame(DeletionWindow::SOURCE_INSTANCE, $window->source());
	}//end testALegacyRowWithoutAWindowStillGetsOne()

	public function testTheRefusalSaysWhereTheObjectWent(): void {
		$deletedAt = new DateTimeImmutable('2026-03-19T09:00:00+00:00');
		$service = $this->service(2592000000);
		$object = $this->deletedObject(
			array_merge(
				['deletedAt' => $deletedAt->format(DATE_ATOM)],
				$service->openWindow(null, $deletedAt)
			)
		);

		$body = $service->refusalBody($object);
		self::assertSame('OBJECT_DELETED', $body['code']);
		self::assertStringContainsString('2026-04-18', $body['message']);
		self::assertSame('2026-04-18T09:00:00+00:00', $body['deleted']['destroyableFrom']);
	}//end testTheRefusalSaysWhereTheObjectWent()

	public function testAnAbsentObjectKeepsThePlainNotFoundAnswer(): void {
		$object = new ObjectEntity();
		$object->setUuid('melding-3');

		$body = $this->service(2592000000)->refusalBody($object);
		self::assertArrayNotHasKey('code', $body);
		self::assertStringContainsString('not found', $body['error']);
	}//end testAnAbsentObjectKeepsThePlainNotFoundAnswer()
}//end class

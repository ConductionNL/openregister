<?php

/**
 * DeletionWindowService — states the recovery window instead of only
 * computing it.
 *
 * OpenRegister has soft-deleted objects with a `purgeDate` since the
 * deletion-audit-trail spec landed, and no surface ever published it. Two
 * things were wrong at once: the main soft-delete path
 * ({@see \OCA\OpenRegister\Service\Object\DeleteObject::delete()}) wrote no
 * purge date at all, and {@see \OCA\OpenRegister\Db\ObjectEntity::delete()}
 * added a hard-coded 31 days regardless of the retention it was handed.
 *
 * One rule resolves the retention, in this order: the schema's
 * `archive.deleteRetention` in days, then the instance setting
 * `objectDeleteRetention` in milliseconds, then the built-in default. The
 * answer carries the rule that produced it, because a date without its rule
 * is the thing a caseworker cannot argue with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves, writes and publishes the recovery window of a soft-deleted object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 */
class DeletionWindowService {
	/**
	 * The retention applied when neither the schema nor the instance states
	 * one, in days.
	 *
	 * @var int
	 */
	public const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Milliseconds in a day, for reading the instance setting.
	 *
	 * @var int
	 */
	private const MS_PER_DAY = 86400000;

	/**
	 * Wire the retention settings reader.
	 *
	 * @param ObjectRetentionHandler $retentionHandler Reads `objectDeleteRetention`.
	 * @param LoggerInterface        $logger           PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectRetentionHandler $retentionHandler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the retention in days and name the rule that produced it.
	 *
	 * A schema-level `archive.deleteRetention` wins over the instance
	 * setting, which wins over the built-in default. A value at or below zero
	 * is not a window and is ignored at every level, so a misconfigured
	 * schema falls back rather than destroying the object immediately.
	 *
	 * @param Schema|null $schema The object's schema, when it resolves.
	 *
	 * @return array{days: int, source: string} The retention and its rule.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function retentionFor(?Schema $schema): array {
		if ($schema !== null) {
			$declared = ($schema->getArchive()['deleteRetention'] ?? null);
			if (is_numeric($declared) === true && (int)$declared > 0) {
				return [
					'days' => (int)$declared,
					'source' => DeletionWindow::SOURCE_SCHEMA,
				];
			}
		}

		$configured = null;
		try {
			$configured = ($this->retentionHandler->getRetentionSettingsOnly()['objectDeleteRetention'] ?? null);
		} catch (Throwable $e) {
			// A settings read that fails must not make objects destroyable
			// sooner than the default: fall through to the default below.
			$this->logger->warning(
				message: '[DeletionWindow] Could not read objectDeleteRetention, using the default',
				context: ['error' => $e->getMessage()]
			);
		}

		if (is_numeric($configured) === true && (int)$configured > 0) {
			$days = (int)floor(((int)$configured / self::MS_PER_DAY));
			if ($days > 0) {
				return [
					'days' => $days,
					'source' => DeletionWindow::SOURCE_INSTANCE,
				];
			}
		}

		return [
			'days' => self::DEFAULT_RETENTION_DAYS,
			'source' => DeletionWindow::SOURCE_DEFAULT,
		];
	}//end retentionFor()

	/**
	 * The window keys to merge into an object's deletion metadata when it is
	 * soft-deleted.
	 *
	 * Returned rather than written so the caller keeps one `setDeleted()`
	 * call and one audit snapshot.
	 *
	 * @param Schema|null            $schema    The object's schema, when it resolves.
	 * @param DateTimeImmutable|null $deletedAt The moment of deletion, now when omitted.
	 *
	 * @return array<string, mixed> Keys for the `deleted` metadata blob.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function openWindow(?Schema $schema, ?DateTimeImmutable $deletedAt = null): array {
		$moment = ($deletedAt ?? new DateTimeImmutable());
		$retention = $this->retentionFor(schema: $schema);
		$destroyableFrom = $moment->modify('+' . $retention['days'] . ' days');

		return [
			'retentionPeriod' => $retention['days'],
			'retentionSource' => $retention['source'],
			'purgeDate' => $destroyableFrom->format(DATE_ATOM),
			'destroyableFrom' => $destroyableFrom->format(DATE_ATOM),
		];
	}//end openWindow()

	/**
	 * The window of a soft-deleted object, or null when it is not in the trash.
	 *
	 * Reads the stored `destroyableFrom`/`purgeDate` first, so an object keeps
	 * the window it was deleted under even if the setting changes afterwards.
	 * An object soft-deleted before this change carries neither, and its
	 * window is derived from `deletedAt` plus the retention that applies now,
	 * which is the only honest answer available for those rows.
	 *
	 * @param ObjectEntity $object The object to describe.
	 * @param Schema|null  $schema The object's schema, when it resolves.
	 * @param DateTimeImmutable|null $now Reference moment, now when omitted.
	 *
	 * @return DeletionWindow|null The window, or null when the object is live.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function windowFor(
		ObjectEntity $object,
		?Schema $schema = null,
		?DateTimeImmutable $now = null,
	): ?DeletionWindow {
		if ($object->isSoftDeleted() === false) {
			return null;
		}

		$metadata = ($object->getDeleted() ?? []);
		$moment = ($now ?? new DateTimeImmutable());
		$deletedAt = $this->parseDate(
			value: ($metadata['deletedAt'] ?? $metadata['deleted'] ?? null),
			fallback: $moment
		);

		$retentionDays = null;
		if (is_numeric($metadata['retentionPeriod'] ?? null) === true
			&& (int)$metadata['retentionPeriod'] > 0
		) {
			$retentionDays = (int)$metadata['retentionPeriod'];
		}

		$source = (string)($metadata['retentionSource'] ?? '');
		if ($retentionDays === null || $source === '') {
			$resolved = $this->retentionFor(schema: $schema);
			if ($retentionDays === null) {
				$retentionDays = $resolved['days'];
			}

			$source = $resolved['source'];
		}

		$destroyableFrom = $this->parseDate(
			value: ($metadata['destroyableFrom'] ?? $metadata['purgeDate'] ?? null),
			fallback: $deletedAt->modify('+' . $retentionDays . ' days')
		);

		return new DeletionWindow(
			destroyableFrom: $destroyableFrom,
			daysRemaining: $this->daysBetween(from: $moment, to: $destroyableFrom),
			retentionDays: $retentionDays,
			source: $source,
			deletedAt: $deletedAt
		);
	}//end windowFor()

	/**
	 * The refusal body a reader gets from the normal object path, naming where
	 * the object went and until when it can come back.
	 *
	 * @param ObjectEntity $object The soft-deleted object.
	 * @param Schema|null  $schema The object's schema, when it resolves.
	 *
	 * @return array<string, mixed> The refusal body.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function refusalBody(ObjectEntity $object, ?Schema $schema = null): array {
		$window = $this->windowFor(object: $object, schema: $schema);
		if ($window === null) {
			return [
				'error' => 'Object with id ' . (string)$object->getUuid() . ' not found',
			];
		}

		return [
			'error' => 'Object with id ' . (string)$object->getUuid() . ' is deleted',
			'code' => 'OBJECT_DELETED',
			'message' => 'This object is in the trash. It can be restored until '
				. $window->destroyableFrom()->format('Y-m-d')
				. ', after which it may be destroyed.',
			'deleted' => $window->toArray(),
		];
	}//end refusalBody()

	/**
	 * Parse a stored date, falling back when it is missing or unreadable.
	 *
	 * @param mixed             $value    The stored value.
	 * @param DateTimeImmutable $fallback Used when the value cannot be read.
	 *
	 * @return DateTimeImmutable The parsed date.
	 */
	private function parseDate(mixed $value, DateTimeImmutable $fallback): DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return $fallback;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return $fallback;
		}
	}//end parseDate()

	/**
	 * Whole days between two moments, never below zero.
	 *
	 * Rounds up, so an object deleted a minute ago under a 30-day retention
	 * reports 30 days remaining rather than 29.
	 *
	 * @param DateTimeImmutable $from The reference moment.
	 * @param DateTimeImmutable $to   The target moment.
	 *
	 * @return int Whole days remaining.
	 */
	private function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int {
		$seconds = ($to->getTimestamp() - $from->getTimestamp());
		if ($seconds <= 0) {
			return 0;
		}

		return (int)ceil(($seconds / 86400));
	}//end daysBetween()
}//end class

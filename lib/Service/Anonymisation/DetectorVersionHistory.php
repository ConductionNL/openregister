<?php

/**
 * OpenRegister Anonymisation Detector Version History
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCP\IAppConfig;

/**
 * The detector version records each backend has reported, with the moment each
 * was first seen.
 *
 * A record that differs from the backend's last one starts a new entry. The
 * current record's `changedAt` is its own when the backend states one (a
 * pattern set knows the day it changed), else the moment its entry started.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
 */
final class DetectorVersionHistory {

	/**
	 * The app config key holding the history of every backend, as JSON.
	 */
	public const CONFIG_KEY = 'anonymisation_detector_history';

	/**
	 * The most entries kept per backend; the oldest go first.
	 */
	public const MAX_ENTRIES = 20;

	/**
	 * Build the history over the app configuration.
	 *
	 * @param IAppConfig $appConfig Where the history is kept.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Note what a backend reports now and return it with its `changedAt`.
	 *
	 * @param string          $backend The backend method.
	 * @param DetectorVersion $record  What the backend reports.
	 * @param string          $now     The moment of the report (ISO 8601).
	 *
	 * @return DetectorVersion The record with its `changedAt`.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
	 */
	public function observe(string $backend, DetectorVersion $record, string $now): DetectorVersion {
		$all     = $this->all();
		$entries = ($all[$backend] ?? []);
		$last    = end($entries);

		if ($last === false || (new DetectorVersion(fields: $last['record']))->sameDetectorAs(other: $record) === false) {
			$entries[] = ['record' => $record->jsonSerialize(), 'firstSeen' => $now];
			$all[$backend] = array_slice($entries, -self::MAX_ENTRIES);
			$this->appConfig->setValueString('openregister', self::CONFIG_KEY, (string)json_encode($all));
			$last = end($all[$backend]);
		}

		if ($record->changedAt !== DetectorVersion::UNKNOWN) {
			return $record;
		}

		return $record->withChangedAt(changedAt: $last['firstSeen']);
	}//end observe()

	/**
	 * The entries of one backend, oldest first, each a record and its first-seen moment.
	 *
	 * @param string $backend The backend method.
	 *
	 * @return array<int, array{record: array<string, mixed>, firstSeen: string}>
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-detector-says-when-it-last-changed-req-adi-004
	 */
	public function entries(string $backend): array {
		return ($this->all()[$backend] ?? []);
	}//end entries()

	/**
	 * Every backend's entries as stored; an unreadable store reads as empty.
	 *
	 * @return array<string, array<int, array{record: array<string, mixed>, firstSeen: string}>>
	 */
	private function all(): array {
		$decoded = json_decode($this->appConfig->getValueString('openregister', self::CONFIG_KEY, ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$all = [];
		foreach ($decoded as $backend => $entries) {
			if (is_string($backend) === false || is_array($entries) === false) {
				continue;
			}

			$all[$backend] = array_values(
				array_filter(
					$entries,
					static fn (mixed $entry): bool => is_array($entry) === true
						&& is_array($entry['record'] ?? null) === true
						&& is_string($entry['firstSeen'] ?? null) === true
				)
			);
		}

		return $all;
	}//end all()
}//end class

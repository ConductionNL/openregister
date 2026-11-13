<?php

/**
 * ArchiveBounds
 *
 * The bounds an archive is enumerated under (REQ-AMS-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/archive-members-are-searchable/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * How far an archive is opened: members, uncompressed bytes, the expansion
 * ratio per member and the nesting depth.
 *
 * @spec openspec/changes/archive-members-are-searchable/specs
 */
final class ArchiveBounds {

	/**
	 * The file settings key the bounds live under.
	 *
	 * @var string
	 */
	public const SETTINGS_KEY = 'archive';

	/**
	 * The default bounds, as the file settings return them.
	 *
	 * @var array{maxMembers: int, maxTotalBytes: int, maxRatio: int, maxDepth: int}
	 */
	public const DEFAULTS = [
		'maxMembers'    => 2000,
		'maxTotalBytes' => 1073741824,
		'maxRatio'      => 100,
		'maxDepth'      => 2,
	];

	/**
	 * Constructor.
	 *
	 * @param int $maxMembers    The most members listed, nested ones included.
	 * @param int $maxTotalBytes The most uncompressed bytes, nested ones included.
	 * @param int $maxRatio      The highest uncompressed-to-compressed ratio of one member.
	 * @param int $maxDepth      The deepest nesting opened: 1 is the archive itself.
	 */
	public function __construct(
		public readonly int $maxMembers=self::DEFAULTS['maxMembers'],
		public readonly int $maxTotalBytes=self::DEFAULTS['maxTotalBytes'],
		public readonly int $maxRatio=self::DEFAULTS['maxRatio'],
		public readonly int $maxDepth=self::DEFAULTS['maxDepth'],
	) {
	}//end __construct()

	/**
	 * The bounds from the file settings' `archive` block; a missing, non-numeric
	 * or non-positive value keeps its default, and the depth is at most 2.
	 *
	 * @param array<string, mixed> $fileSettings The file settings.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public static function fromSettings(array $fileSettings): self {
		$stored = $fileSettings[self::SETTINGS_KEY] ?? [];
		if (is_array($stored) === false) {
			$stored = [];
		}

		$values = [];
		foreach (self::DEFAULTS as $key => $default) {
			$value = $stored[$key] ?? null;
			if (is_numeric($value) === false || (int) $value < 1) {
				$value = $default;
			}

			$values[$key] = (int) $value;
		}

		return new self(
			maxMembers: $values['maxMembers'],
			maxTotalBytes: $values['maxTotalBytes'],
			maxRatio: $values['maxRatio'],
			maxDepth: min($values['maxDepth'], self::DEFAULTS['maxDepth'])
		);
	}//end fromSettings()

	/**
	 * The bounds as the file settings return them.
	 *
	 * @return array{maxMembers: int, maxTotalBytes: int, maxRatio: int, maxDepth: int}
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function toArray(): array {
		return [
			'maxMembers'    => $this->maxMembers,
			'maxTotalBytes' => $this->maxTotalBytes,
			'maxRatio'      => $this->maxRatio,
			'maxDepth'      => $this->maxDepth,
		];
	}//end toArray()
}//end class

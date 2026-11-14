<?php

/**
 * OpenRegister ArchiveExtraction
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * What reading an archive produced: the text of each member that could be
 * read, and every member that could not, with the reason.
 *
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
 */
final class ArchiveExtraction {

	/**
	 * Every member was read.
	 *
	 * @var string
	 */
	public const COMPLETE = 'complete';

	/**
	 * Some member was refused, encrypted, over a bound, unsupported or unreadable.
	 *
	 * @var string
	 */
	public const PARTIAL = 'partial';

	/**
	 * The archive type itself is not opened (tar, 7z, rar).
	 *
	 * @var string
	 */
	public const UNSUPPORTED = 'unsupported';

	/**
	 * The text of each readable member, in archive order.
	 *
	 * @var array<int, array{path: string, text: string}>
	 */
	private array $texts = [];

	/**
	 * The members that were not read.
	 *
	 * @var array<int, array{path: string, reason: string}>
	 */
	private array $unread = [];

	/**
	 * Constructor.
	 *
	 * @param string $archiveType The archive MIME type.
	 * @param bool   $supported   Whether the archive type is opened at all.
	 */
	public function __construct(
		public readonly string $archiveType,
		public readonly bool $supported=true,
	) {
	}//end __construct()

	/**
	 * Record the text of a member.
	 *
	 * @param string $path The member path.
	 * @param string $text Its text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public function addText(string $path, string $text): void {
		$this->texts[] = ['path' => $path, 'text' => $text];
	}//end addText()

	/**
	 * Record a member that was not read.
	 *
	 * @param string $path   The member path.
	 * @param string $reason `refused`, `encrypted`, `over-bound:<bound>`, `unsupported` or `unreadable`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public function addUnread(string $path, string $reason): void {
		$this->unread[] = ['path' => $path, 'reason' => $reason];
	}//end addUnread()

	/**
	 * The text of each readable member.
	 *
	 * @return array<int, array{path: string, text: string}>
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public function texts(): array {
		return $this->texts;
	}//end texts()

	/**
	 * The members that were not read.
	 *
	 * @return array<int, array{path: string, reason: string}>
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public function unread(): array {
		return $this->unread;
	}//end unread()

	/**
	 * The archive's extraction state.
	 *
	 * @return string One of the class constants.
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public function status(): string {
		if ($this->supported === false) {
			return self::UNSUPPORTED;
		}

		if ($this->unread !== []) {
			return self::PARTIAL;
		}

		return self::COMPLETE;
	}//end status()

	/**
	 * The report the file's metadata chunk keeps and the extraction status returns.
	 *
	 * @return array{type: string, status: string, members: int, unread: array<int, array{path: string, reason: string}>}
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-what-could-not-be-opened-is-reported-req-ams-003
	 */
	public function report(): array {
		return [
			'type'    => $this->archiveType,
			'status'  => $this->status(),
			'members' => count($this->texts),
			'unread'  => $this->unread,
		];
	}//end report()
}//end class

<?php

/**
 * A request to write a Talk conversation as a transcript file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\Integration;

use DateTimeImmutable;

/**
 * The conversation, its messages, and who saved it when.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */
final class ChatTranscriptRequest {
	/**
	 * Constructor.
	 *
	 * @param string                           $conversation The conversation's name.
	 * @param array<int, array<string, mixed>> $messages     Each: `actorDisplayName`, `timestamp` (unix), `message`.
	 * @param string                           $savedBy      Who saved it.
	 * @param DateTimeImmutable                $savedAt      When.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
	 */
	public function __construct(
		public readonly string $conversation,
		public readonly array $messages,
		public readonly string $savedBy,
		public readonly DateTimeImmutable $savedAt,
	) {
	}//end __construct()

	/**
	 * The transcript's title: the trimmed conversation name, or `Chat`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
	 */
	public function title(): string {
		$title = trim($this->conversation);
		if ($title === '') {
			return 'Chat';
		}

		return $title;
	}//end title()
}//end class

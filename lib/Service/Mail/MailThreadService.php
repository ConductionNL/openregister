<?php

/**
 * MailThreadService: the conversation a collected `.eml` file belongs to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mail
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mail;

use OCA\OpenRegister\Files\Db\MailHeader;
use OCA\OpenRegister\Files\Db\MailHeaderMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;

/**
 * Assembles the thread of one mail over the `.eml` files beside it.
 *
 * The set is the files of the asked file's folder: an object's files share
 * its folder, so this is the object's files, and a loose mail's folder
 * otherwise. Only files the caller may read join the set; an unreadable
 * one is left out, so a reply that answers it shows its parent as a gap
 * naming only the `Message-ID` the reply itself carries.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
 */
class MailThreadService {

	/**
	 * Constructor.
	 *
	 * @param MailHeaderMapper    $headers    The stored thread headers.
	 * @param IRootFolder         $rootFolder The file tree, to find the folder of a file.
	 * @param MailThreadAssembler $assembler  Builds threads from header rows.
	 */
	public function __construct(
		private readonly MailHeaderMapper $headers,
		private readonly IRootFolder $rootFolder,
		private readonly MailThreadAssembler $assembler,
	) {
	}//end __construct()

	/**
	 * The thread of a file, in date order, and the asked file's place in it.
	 *
	 * The caller has already checked that it may read the asked file.
	 *
	 * @param int      $fileId  The asked file.
	 * @param callable $mayRead `fn (int $fileId): bool`, whether the caller may read a file.
	 *
	 * @return array{thread: list<array<string, mixed>>, position: int|null} The thread; empty with a
	 *         null position when the file carries no thread headers.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
	 */
	public function threadOf(int $fileId, callable $mayRead): array {
		$files = $this->readableSiblings(fileId: $fileId, mayRead: $mayRead);
		$messages = [];
		foreach ($this->headers->findByFileIds(fileIds: array_keys($files)) as $row) {
			$messages[] = $this->message(row: $row, name: $files[$row->getFileId()]);
		}

		foreach ($this->assembler->assemble(messages: $messages) as $thread) {
			foreach ($thread as $index => $node) {
				if ($node['fileId'] === $fileId) {
					return ['thread' => $thread, 'position' => $index];
				}
			}
		}

		return ['thread' => [], 'position' => null];
	}//end threadOf()

	/**
	 * The `.eml` files in the asked file's folder that the caller may read, the asked one included.
	 *
	 * @param int      $fileId  The asked file.
	 * @param callable $mayRead Whether the caller may read a file.
	 *
	 * @return array<int, string> The file names by file id.
	 */
	private function readableSiblings(int $fileId, callable $mayRead): array {
		$nodes = $this->rootFolder->getById($fileId);
		$node = ($nodes[0] ?? null);
		if (($node instanceof Node) === false) {
			return [];
		}

		$files = [$fileId => $node->getName()];
		try {
			$siblings = $node->getParent()->getDirectoryListing();
		} catch (\Throwable) {
			return $files;
		}

		foreach ($siblings as $sibling) {
			$id = (int) $sibling->getId();
			if ($id === $fileId || ($sibling instanceof File) === false || self::isMail(name: $sibling->getName()) === false) {
				continue;
			}

			if ($mayRead($id) === true) {
				$files[$id] = $sibling->getName();
			}
		}

		return $files;
	}//end readableSiblings()

	/**
	 * One stored header row as the assembler reads it.
	 *
	 * The table holds no sender or subject; the subject is the file name
	 * without `.eml`, which is how a collected mail is named.
	 *
	 * @param MailHeader $row  The row.
	 * @param string     $name The file name.
	 *
	 * @return array<string, mixed> The message.
	 */
	private function message(MailHeader $row, string $name): array {
		return [
			'fileId' => $row->getFileId(),
			'messageId' => $row->getMessageId(),
			'inReplyTo' => $row->getInReplyTo(),
			'references' => $row->references(),
			'sentAt' => $row->getSentAt()?->format(DATE_ATOM),
			'from' => '',
			'subject' => preg_replace('/\.eml$/i', '', $name),
		];
	}//end message()

	/**
	 * Whether a file name is a collected mail.
	 *
	 * @param string $name The name.
	 *
	 * @return bool True for `.eml`.
	 */
	private static function isMail(string $name): bool {
		return preg_match('/\.eml$/i', $name) === 1;
	}//end isMail()
}//end class

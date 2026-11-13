<?php

/**
 * MailThreadAssembler: group collected .eml files into reply threads by their headers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mail;

/**
 * Assembles threads over a set of collected mail header rows by `In-Reply-To`
 * first and `References` from its last entry, never by subject.
 *
 * Each input row is an associative array:
 *   - `messageId`  string   the RFC Message-ID, brackets stripped (may be '')
 *   - `inReplyTo`  string   the one id this answers, or ''
 *   - `references` string[] the ancestry, oldest first (last entry is the nearest parent)
 *   - `sentAt`     ?string  the sent date, ISO 8601, for ordering
 *   - `from`       string   the sender
 *   - `subject`    string   the subject (carried, never used to thread)
 *   - `fileId`     ?int     the file this message was read from
 *
 * A thread is a list of nodes in sent-date order:
 *   `{fileId, messageId, parent, sentAt, from, subject, gap}`
 * A node for a parent referenced by a message but absent from the set is a
 * gap: `gap` is true and it carries only its `messageId` (the parent id).
 */
class MailThreadAssembler {

	/**
	 * Group the messages into threads.
	 *
	 * Two messages with no header link are two threads even when their
	 * subjects are equal; a referenced-but-absent parent becomes a gap in its
	 * child's thread; two files with the same Message-ID are one node.
	 *
	 * @param array<int, array<string, mixed>> $messages The header rows.
	 *
	 * @return array<int, array<int, array<string, mixed>>> The threads, each a date-ordered list of nodes.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
	 */
	public function assemble(array $messages): array {
		$byId    = $this->dedupeByMessageId(messages: $messages);
		$parents = $this->parentOf(byId: $byId);
		$groups  = $this->group(byId: $byId, parents: $parents);

		$threads = [];
		foreach ($groups as $group) {
			$threads[] = $this->orderThread(group: $group, byId: $byId, parents: $parents);
		}

		return $threads;
	}//end assemble()

	/**
	 * Keep one message per Message-ID (the first seen); messages without an id keep a synthetic key.
	 *
	 * @param array<int, array<string, mixed>> $messages The header rows.
	 *
	 * @return array<string, array<string, mixed>> The messages by their key.
	 */
	private function dedupeByMessageId(array $messages): array {
		$byId = [];
		foreach ($messages as $index => $message) {
			$id  = trim((string)($message['messageId'] ?? ''));
			$key = $id;
			if ($id === '') {
				$key = 'anon:' . $index;
			}
			if (array_key_exists($key, $byId) === false) {
				$byId[$key] = $message;
			}
		}

		return $byId;
	}//end dedupeByMessageId()

	/**
	 * The parent id of each message: `In-Reply-To`, else the last `References` entry, else ''.
	 *
	 * @param array<string, array<string, mixed>> $byId The messages by key.
	 *
	 * @return array<string, string> The parent id per key.
	 */
	private function parentOf(array $byId): array {
		$parents = [];
		foreach ($byId as $key => $message) {
			$inReplyTo  = trim((string)($message['inReplyTo'] ?? ''));
			$references = array_values(array_filter(array_map('strval', ($message['references'] ?? []))));
			if ($inReplyTo !== '') {
				$parents[$key] = $inReplyTo;
			} else if ($references !== []) {
				$parents[$key] = (string)end($references);
			} else {
				$parents[$key] = '';
			}
		}

		return $parents;
	}//end parentOf()

	/**
	 * Union the messages into connected components by their parent links.
	 *
	 * @param array<string, array<string, mixed>> $byId    The messages by key.
	 * @param array<string, string>               $parents The parent id per key.
	 *
	 * @return array<int, array<int, string>> The component members (keys), by component.
	 */
	private function group(array $byId, array $parents): array {
		$find = [];
		foreach (array_keys($byId) as $key) {
			$find[$key] = $key;
		}

		$root = static function (string $key) use (&$find): string {
			while ($find[$key] !== $key) {
				$find[$key] = $find[$find[$key]];
				$key        = $find[$key];
			}

			return $key;
		};

		foreach ($parents as $key => $parentId) {
			if ($parentId !== '' && array_key_exists($parentId, $byId) === true) {
				$find[$root($key)] = $root($parentId);
			}
		}

		$components = [];
		foreach (array_keys($byId) as $key) {
			$components[$root($key)][] = $key;
		}

		return array_values($components);
	}//end group()

	/**
	 * Build and date-order one thread's nodes, inserting a gap before the earliest child of an absent parent.
	 *
	 * @param array<int, string>                  $group   The component's message keys.
	 * @param array<string, array<string, mixed>> $byId    The messages by key.
	 * @param array<string, string>               $parents The parent id per key.
	 *
	 * @return array<int, array<string, mixed>> The thread nodes in date order.
	 */
	private function orderThread(array $group, array $byId, array $parents): array {
		$nodes = [];
		foreach ($group as $key) {
			$nodes[] = $this->nodeFor(message: $byId[$key], parent: $parents[$key]);
		}

		foreach ($this->absentParents(group: $group, byId: $byId, parents: $parents) as $parentId) {
			$nodes[] = ['fileId' => null, 'messageId' => $parentId, 'parent' => '', 'sentAt' => null, 'from' => '', 'subject' => '', 'gap' => true];
		}

		usort($nodes, [$this, 'byDate']);

		return $nodes;
	}//end orderThread()

	/**
	 * One real node for a message.
	 *
	 * @param array<string, mixed> $message The header row.
	 * @param string               $parent  Its resolved parent id.
	 *
	 * @return array<string, mixed>
	 */
	private function nodeFor(array $message, string $parent): array {
		return [
			'fileId' => ($message['fileId'] ?? null),
			'messageId' => trim((string)($message['messageId'] ?? '')),
			'parent' => $parent,
			'sentAt' => ($message['sentAt'] ?? null),
			'from' => (string)($message['from'] ?? ''),
			'subject' => (string)($message['subject'] ?? ''),
			'gap' => false,
		];
	}//end nodeFor()

	/**
	 * The parent ids referenced inside a component but not present in the whole set.
	 *
	 * @param array<int, string>                  $group   The component's message keys.
	 * @param array<string, array<string, mixed>> $byId    The messages by key.
	 * @param array<string, string>               $parents The parent id per key.
	 *
	 * @return array<int, string> The absent parent ids, without duplicates.
	 */
	private function absentParents(array $group, array $byId, array $parents): array {
		$absent = [];
		foreach ($group as $key) {
			$parentId = $parents[$key];
			if ($parentId !== '' && array_key_exists($parentId, $byId) === false) {
				$absent[$parentId] = true;
			}
		}

		return array_keys($absent);
	}//end absentParents()

	/**
	 * Order two nodes by sent date; a node without a date (a gap) sorts before dated ones.
	 *
	 * @param array<string, mixed> $left  One node.
	 * @param array<string, mixed> $right The other.
	 *
	 * @return int
	 */
	private function byDate(array $left, array $right): int {
		$a = (string)($left['sentAt'] ?? '');
		$b = (string)($right['sentAt'] ?? '');
		if ($a === $b) {
			return strcmp((string)($left['messageId'] ?? ''), (string)($right['messageId'] ?? ''));
		}

		if ($a === '') {
			return -1;
		}

		if ($b === '') {
			return 1;
		}

		return strcmp($a, $b);
	}//end byDate()
}//end class

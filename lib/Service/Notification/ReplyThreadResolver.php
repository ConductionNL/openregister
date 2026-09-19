<?php

/**
 * Which object a reply belongs to, decided by the headers and never guessed.
 *
 * 🔑 THE SUBJECT LINE IS NOT A THREAD. A citizen who edits the subject loses
 * the `[ZAAK-…]` tag the inbound job matches on, and the reply lands nowhere.
 * `In-Reply-To` and `References` are what a mail client actually threads on,
 * and they survive an edited subject.
 *
 * 🔴 A WRONG GUESS PUTS ONE CITIZEN'S REPLY ON ANOTHER CITIZEN'S CASE. That is
 * the failure this class is shaped around, and it is why there is no fuzzy
 * match, no prefix match and no subject fallback anywhere in it. A reference
 * either equals a `Message-ID` this instance recorded when it sent or linked a
 * mail, or it resolves nothing.
 *
 * 🔴 A CHAIN THAT POINTS AT TWO OBJECTS RESOLVES NEITHER. `References`
 * accumulates every ancestor, and a person who replies to a mail about case A
 * while quoting a mail about case B hands us both. Picking the first, the
 * last, or the most recent is a coin flip with a disclosure on one side, so
 * the answer is `ambiguous` and a human decides.
 *
 * 🔴 NO USABLE REFERENCE IS ITS OWN ANSWER, NAMED. `unthreaded` is not a
 * failure to be swallowed into "no match": the reply is real, it arrived, and
 * somebody has to see it. An empty answer would leave it in a queue nobody
 * reads, which is how a citizen's reply goes unanswered while the system looks
 * healthy.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Resolves a reply onto the object its headers point at.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
class ReplyThreadResolver {

	/**
	 * The headers are read in this order.
	 *
	 * `In-Reply-To` names the direct parent and is the strongest claim a mail
	 * client makes. `References` is the whole ancestry, and its LAST entry is
	 * the nearest ancestor, which is why it is walked from the end.
	 *
	 * @var array<int,string>
	 */
	public const HEADER_ORDER = ['In-Reply-To', 'References'];

	/**
	 * The reply belongs to exactly one object.
	 *
	 * @var string
	 */
	public const THREADED = 'threaded';

	/**
	 * Nothing in the headers matches anything this instance recorded.
	 *
	 * @var string
	 */
	public const UNTHREADED = 'unthreaded';

	/**
	 * The headers point at more than one object.
	 *
	 * @var string
	 */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * How many references are followed before the rest are ignored.
	 *
	 * A `References` chain grows by one per reply and mail clients do not trim
	 * it; a thread forwarded around an office for a year arrives with hundreds.
	 * The nearest ancestors are the ones that matter, and they are at the end.
	 *
	 * @var int
	 */
	public const MAX_REFERENCES = 25;

	/**
	 * Which object this reply threads onto.
	 *
	 * @param array<string,mixed>                          $headers The reply's headers.
	 * @param callable                                     $lookup  `fn(string $messageId): ?array` — the recorded link, or null.
	 *
	 * @return array{state:string,objectUuid:string,matchedOn:string,messageId:string,candidates:array<int,string>}
	 *         What it threads onto, which header decided it, and which objects were in play when nothing could.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function resolve(array $headers, callable $lookup): array {
		$objects = [];
		$firstMatch = null;

		foreach (self::HEADER_ORDER as $header) {
			foreach ($this->referencesIn(headers: $headers, header: $header) as $messageId) {
				$link = $lookup($messageId);
				if (is_array($link) === false) {
					continue;
				}

				$objectUuid = trim((string)($link['objectUuid'] ?? ''));
				if ($objectUuid === '') {
					continue;
				}

				if (isset($objects[$objectUuid]) === false) {
					$objects[$objectUuid] = true;
				}

				if ($firstMatch === null) {
					$firstMatch = ['objectUuid' => $objectUuid, 'matchedOn' => $header, 'messageId' => $messageId];
				}
			}

			// `In-Reply-To` is the direct parent, so a single unambiguous hit
			// there is the answer and `References` is not consulted. Walking
			// on would only add ancestors that can disagree with it.
			if (count($objects) === 1 && $firstMatch !== null && $firstMatch['matchedOn'] === $header) {
				return [
					'state' => self::THREADED,
					'objectUuid' => $firstMatch['objectUuid'],
					'matchedOn' => $header,
					'messageId' => $firstMatch['messageId'],
					'candidates' => [],
				];
			}

			if (count($objects) > 1) {
				break;
			}
		}

		if (count($objects) > 1) {
			// Two objects in one chain. Picking either is a coin flip with a
			// disclosure on one side: the reply would be filed on a case its
			// author has nothing to do with, and read by that case's handler.
			return [
				'state' => self::AMBIGUOUS,
				'objectUuid' => '',
				'matchedOn' => '',
				'messageId' => '',
				'candidates' => array_keys($objects),
			];
		}

		if ($firstMatch !== null) {
			return [
				'state' => self::THREADED,
				'objectUuid' => $firstMatch['objectUuid'],
				'matchedOn' => $firstMatch['matchedOn'],
				'messageId' => $firstMatch['messageId'],
				'candidates' => [],
			];
		}

		// Named, never empty: the reply is real and somebody has to see it.
		return [
			'state' => self::UNTHREADED,
			'objectUuid' => '',
			'matchedOn' => '',
			'messageId' => '',
			'candidates' => [],
		];
	}//end resolve()

	/**
	 * The message ids one header carries, nearest ancestor first.
	 *
	 * @param array<string,mixed> $headers The headers.
	 * @param string              $header  Which one to read.
	 *
	 * @return array<int,string> The ids, normalised.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function referencesIn(array $headers, string $header): array {
		$raw = '';
		foreach ($headers as $name => $value) {
			// Header names are case-insensitive per RFC 5322, and a client
			// that writes `in-reply-to` is not malformed. Matching case
			// sensitively would drop the thread for that client alone, which
			// is the kind of bug nobody reproduces.
			if (strcasecmp((string)$name, $header) === 0) {
				$raw = (string)$value;
				if (is_array($value) === true) {
					$raw = implode(' ', $value);
				}
				break;
			}
		}

		if (trim($raw) === '') {
			return [];
		}

		if (preg_match_all('/<[^<>\s]+>/', $raw, $matches) < 1) {
			return [];
		}

		// Reversed: the LAST entry of References is the nearest ancestor, and
		// the nearest ancestor is the one a reply is actually about.
		$ids = array_reverse(array_values(array_unique($matches[0])));

		return array_slice($ids, 0, self::MAX_REFERENCES);
	}//end referencesIn()

	/**
	 * Whether this outcome may be filed automatically.
	 *
	 * Only one of the three may. The other two are a person's decision, and a
	 * caller that treated them as "nothing to do" would leave real replies in
	 * a queue nobody reads.
	 *
	 * @param string $state The resolved state.
	 *
	 * @return bool True when the reply may be attached without a human.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function mayFileAutomatically(string $state): bool {
		return ($state === self::THREADED);
	}//end mayFileAutomatically()
}//end class

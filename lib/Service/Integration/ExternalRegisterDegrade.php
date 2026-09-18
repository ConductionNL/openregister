<?php

/**
 * What the external-register leaf says when it cannot show the record.
 *
 * 🔑 A MUNICIPALITY IS OBLIGED TO CONSULT THE BASISREGISTRATIES, so the
 * question "what does the BAG say about this address" is statutory rather than
 * decorative. A widget that answers it with a blank panel has not failed
 * politely: it has told a handler that the BAG holds nothing about an address
 * the BAG certainly holds something about.
 *
 * 🔴 EVERY WAY OF NOT SHOWING A RECORD IS A DIFFERENT SENTENCE, and this class
 * exists so they cannot collapse into one. The source is not installed; it is
 * installed and not configured; it is configured and unreachable; it answered
 * and refused this caller; it answered and holds no such record; the host
 * object carries no key to look one up by. Six states, one of which — no such
 * record — is the ONLY one that means the register has nothing, and the other
 * five are about us rather than about the register.
 *
 * 🔴 "NO DATA" IS THE ANSWER THAT MUST NEVER BE GUESSED. The same reasoning
 * that makes {@see ActivityFeedService} name an unreadable source rather than
 * merge it as nothing: an empty answer and an unavailable one render
 * identically, and only one of them is a fact about the world.
 *
 * 🔴 A REFUSAL IS NOT AN OUTAGE. A source that answered and said no tells the
 * caller they may not see this record, which is actionable; folding it into
 * "temporarily unavailable" sends them to phone an administrator about a
 * system that is working exactly as configured.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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
 * @spec openspec/changes/external-register-view-leaf/specs/integration-external-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * The degrade contract for a leaf that renders an external register record.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/integration-external-register/spec.md
 */
class ExternalRegisterDegrade {

	/**
	 * The record is there and was read.
	 *
	 * @var string
	 */
	public const OK = 'ok';

	/**
	 * The host object carries no value in the key property, so there is
	 * nothing to look up. Not a failure: a case with no address has no BAG
	 * record, and saying "unavailable" would send somebody looking for one.
	 *
	 * @var string
	 */
	public const NO_KEY = 'no-key';

	/**
	 * The app that would serve this source is not installed.
	 *
	 * @var string
	 */
	public const SOURCE_ABSENT = 'source-absent';

	/**
	 * The app is installed and nobody has configured the source yet. An
	 * administrator can act on this; a handler cannot, and the two need
	 * different sentences.
	 *
	 * @var string
	 */
	public const NOT_CONFIGURED = 'not-configured';

	/**
	 * It is configured and did not answer: down, slow, or refusing the
	 * connection.
	 *
	 * @var string
	 */
	public const UNREACHABLE = 'unreachable';

	/**
	 * It answered and refused THIS caller. Working as configured.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * It answered, and holds no such record. The one state that is a fact
	 * about the register rather than about us.
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'not-found';

	/**
	 * Every state, so a caller can enumerate them rather than guess.
	 *
	 * @var array<int,string>
	 */
	public const STATES = [
		self::OK,
		self::NO_KEY,
		self::SOURCE_ABSENT,
		self::NOT_CONFIGURED,
		self::UNREACHABLE,
		self::REFUSED,
		self::NOT_FOUND,
	];

	/**
	 * The states an administrator, rather than the reader, can do something
	 * about.
	 *
	 * @var array<int,string>
	 */
	public const ADMIN_ACTIONABLE = [self::SOURCE_ABSENT, self::NOT_CONFIGURED, self::UNREACHABLE];

	/**
	 * The state of one lookup.
	 *
	 * The order of the tests is the order of the causes: a key that is missing
	 * is checked before an app that is absent, because a case with no address
	 * has no BAG record whether or not the BAG app is installed, and reporting
	 * the installation instead would send an administrator to fix something
	 * that is not broken.
	 *
	 * @param array<string,mixed> $lookup `key`, `appInstalled`, `configured`, `answered`, `refused`, `record`.
	 *
	 * @return array{state:string,adminActionable:bool,record:array<string,mixed>|null}
	 *         The state, whether an administrator can act on it, and the record when there is one.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/integration-external-register/spec.md
	 */
	public function evaluate(array $lookup): array {
		$state = $this->stateOf(lookup: $lookup);
		$record = null;
		if ($state === self::OK) {
			$record = (is_array($lookup['record'] ?? null) === true ? $lookup['record'] : []);
		}

		return [
			'state' => $state,
			'adminActionable' => in_array($state, self::ADMIN_ACTIONABLE, true),
			'record' => $record,
		];
	}//end evaluate()

	/**
	 * Which of the seven states this lookup is in.
	 *
	 * @param array<string,mixed> $lookup The lookup.
	 *
	 * @return string The state.
	 */
	private function stateOf(array $lookup): string {
		if (trim((string)($lookup['key'] ?? '')) === '') {
			return self::NO_KEY;
		}

		if (($lookup['appInstalled'] ?? false) !== true) {
			return self::SOURCE_ABSENT;
		}

		if (($lookup['configured'] ?? false) !== true) {
			return self::NOT_CONFIGURED;
		}

		if (($lookup['answered'] ?? false) !== true) {
			// It did not answer. NOT folded into "no such record": an
			// unreachable register and an empty one render the same and only
			// one of them is a fact about the world.
			return self::UNREACHABLE;
		}

		if (($lookup['refused'] ?? false) === true) {
			// It answered and said no. A refusal is not an outage, and telling
			// a caller to phone an administrator about a system working
			// exactly as configured wastes both of them.
			return self::REFUSED;
		}

		$record = ($lookup['record'] ?? null);
		if (is_array($record) === false || $record === []) {
			return self::NOT_FOUND;
		}

		return self::OK;
	}//end stateOf()

	/**
	 * Whether this state means the register itself holds nothing.
	 *
	 * Exactly one does. Every caller that wants to say "this address is not in
	 * the BAG" has to ask THIS rather than test for an empty record, because
	 * five other states also carry no record.
	 *
	 * @param string $state The state.
	 *
	 * @return bool True only for a register that answered and had nothing.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/integration-external-register/spec.md
	 */
	public function meansTheRegisterHasNothing(string $state): bool {
		return ($state === self::NOT_FOUND);
	}//end meansTheRegisterHasNothing()

	/**
	 * How long an answer may be reused before it is asked for again.
	 *
	 * A failure is cached BRIEFLY and an answer for longer: a source that came
	 * back a minute after an outage should be visible on the next page view,
	 * while a BAG record does not change while somebody reads a case. Caching
	 * a failure as long as a success is how a widget stays broken for an hour
	 * after the thing it depends on is fixed.
	 *
	 * @param string $state The state.
	 *
	 * @return int Seconds, 0 when the answer must not be reused at all.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/integration-external-register/spec.md
	 */
	public function cacheSecondsFor(string $state): int {
		return match ($state) {
			self::OK => 900,
			self::NOT_FOUND => 300,
			self::UNREACHABLE => 30,
			// A refusal is about this caller and may change the moment their
			// rights do, and the two configuration states change the moment an
			// administrator acts. None of them is worth holding.
			default => 0,
		};
	}//end cacheSecondsFor()
}//end class

<?php

/**
 * Writes the collected reveals to the hash-chained trail (ledger row 5.6).
 *
 * `sensitive-field-reveal-audit` shipped its collector and its collection point
 * in openregister#3882 and deliberately did not persist: the flush writes to
 * the append-only chain, and the phase that built the collector had nothing to
 * verify a chain against. This is the other half. Until it existed the feature
 * COLLECTED INTO NOTHING, which is the failure mode that change's own PR body
 * named rather than left to be discovered.
 *
 * 🔑 IT DOES NOT CHAIN ANYTHING ITSELF, and that is the whole reason it is
 * short. `AuditTrailMapper::insertAuditTrails()` inserts a chunk and then seals
 * that chunk into the chain in one batched pass, so a second implementation of
 * the hashing here would be a second answer to "what is this row's hash" over a
 * structure whose entire value is that there is one. The earlier caution about
 * this flush "touching the chain" was conservative: the chain is touched by the
 * mapper, through the one path that already owns it.
 *
 * 🔴 A FAILED FLUSH MUST NOT FAIL THE REQUEST. The reads have already happened
 * and the data is already on its way to the reader; throwing here would turn a
 * missing audit row into a 500 on a page somebody is entitled to see, which
 * trades a recording problem for an availability one. It is logged at ERROR
 * instead, with the count, so a trail that is incomplete says so somewhere
 * rather than being quietly short.
 *
 * WHY ONE ENTRY PER REVEAL AND NOT ONE PER REQUEST. A list of forty objects
 * reveals forty BSNs, and that count is the finding a data protection officer
 * came for. The batching is about how many INSERTs it costs, not about how many
 * facts are recorded.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns collected reveals into hash-chained audit rows, once per request.
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */
class RevealFlusher implements RevealFlusherInterface {

	/**
	 * How many rows go into one insert.
	 *
	 * The mapper chunks and seals per chunk, so this is the size of one sealed
	 * window rather than an arbitrary batch: a list read of forty is one.
	 *
	 * @var integer
	 */
	public const CHUNK = 100;

	/**
	 * Constructor.
	 *
	 * @param RevealRecording           $recording What the read path collected, and the logger.
	 * @param AuditTrailMapperInterface $mapper    The trail, which owns the chain.
	 */
	public function __construct(
		private readonly RevealRecording $recording,
		private readonly AuditTrailMapperInterface $mapper,
	) {
	}//end __construct()

	/**
	 * Write everything collected, and leave the collector empty.
	 *
	 * @return int How many rows were written.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function flush(): int {
		$collector = $this->recording->reveals;
		if ($collector === null) {
			return 0;
		}

		$pending = $this->takePending(collector: $collector);
		if ($pending === []) {
			return 0;
		}

		// See the class docblock: the reads have already happened, so a
		// failure here is a recording problem and must not become an
		// availability one.
		return (new AuditRowWriter(mapper: $this->mapper, logger: $this->recording->logger))->write(
			rows: $this->rowsFor(pending: $pending),
			failure: '[RevealFlusher] Could not write the reveal entries; they are lost for this request'
		);
	}//end flush()

	/**
	 * Empty the collector, and say so when it overflowed before anything is written.
	 *
	 * @param RevealCollector $collector The request's collector.
	 *
	 * @return array<int, array<string, mixed>> The entries taken.
	 */
	private function takePending(RevealCollector $collector): array {
		$overflowed = $collector->overflowed();

		// TAKEN BEFORE ANYTHING CAN FAIL. `take()` empties the collector, so a
		// flush that threw halfway after reading without taking would try the
		// same rows again on the next flush of the same request and write the
		// ones that did land a second time.
		$pending = $collector->take();
		if ($pending !== []) {
			$this->reportOverflow(overflowed: $overflowed);
		}

		return $pending;
	}//end takePending()

	/**
	 * Say at ERROR that the collector stopped recording, when it did.
	 *
	 * Said out loud rather than inferred from a count nobody compares. A trail
	 * that is quietly incomplete is worse than one that names where it stopped.
	 *
	 * @param bool $overflowed Whether more reveals happened than the collector records.
	 *
	 * @return void
	 */
	private function reportOverflow(bool $overflowed): void {
		if ($overflowed === false) {
			return;
		}

		$this->recording->logger->error(
			message: '[RevealFlusher] More reveals happened this request than the collector records; the trail for it is incomplete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'bound' => RevealCollector::MAX_PER_REQUEST,
			]
		);
	}//end reportOverflow()

	/**
	 * The collected reveals, as rows the mapper can seal.
	 *
	 * @param array<int, array<string, mixed>> $pending The entries taken from the collector.
	 *
	 * @return array<int, AuditTrail> The rows.
	 */
	private function rowsFor(array $pending): array {
		$rows = [];
		foreach ($pending as $entry) {
			$rows[] = $this->rowFor(entry: $entry);
		}

		return $rows;
	}//end rowsFor()

	/**
	 * One collected reveal, as a row the mapper can seal.
	 *
	 * 🔴 `changed` CARRIES THE PROPERTY NAME AND NEVER ITS VALUE. The point of
	 * the row is that somebody saw a BSN; putting the BSN in the row would copy
	 * the very thing the property is protected for into a table built to be
	 * readable by auditors and impossible to delete. The whole feature would
	 * then be a second, permanent disclosure of everything it audits.
	 *
	 * @param array<string, mixed> $entry One entry from the collector.
	 *
	 * @return AuditTrail The row, unsealed; the mapper seals it.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function rowFor(array $entry): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setAction(RevealCollector::ACTION);
		$row->setUser((string)($entry['user'] ?? ''));
		$row->setUserName((string)($entry['user'] ?? ''));
		$row->setCreated(new DateTime());

		$this->placeRow(row: $row, entry: $entry);
		$row->setChanged(self::changedFor(entry: $entry));

		return $row;
	}//end rowFor()

	/**
	 * Point the row at the object, schema and register the entry names, where it names them.
	 *
	 * @param AuditTrail           $row   The row being built.
	 * @param array<string, mixed> $entry One entry from the collector.
	 *
	 * @return void
	 */
	private function placeRow(AuditTrail $row, array $entry): void {
		$objectUuid = (string)($entry['object'] ?? '');
		if ($objectUuid !== '') {
			$row->setObjectUuid($objectUuid);
		}

		if (($entry['schema'] ?? null) !== null) {
			$row->setSchema((int)$entry['schema']);
		}

		if (($entry['register'] ?? null) !== null) {
			$row->setRegister((int)$entry['register']);
		}
	}//end placeRow()

	/**
	 * What the row records as changed: the property name, or the process run.
	 *
	 * Never the property's value; see rowFor().
	 *
	 * @param array<string, mixed> $entry One entry from the collector.
	 *
	 * @return array<string, int|string> The changed field.
	 */
	private static function changedFor(array $entry): array {
		$changed = ['property' => (string)($entry['property'] ?? '')];

		// D-3's process entry carries the run rather than an object, so the
		// two shapes are distinguishable in the trail without a second action.
		if (($entry['process'] ?? null) !== null) {
			$changed['process'] = (string)$entry['process'];
			$changed['run'] = (string)($entry['run'] ?? '');
			$changed['revealed'] = (int)($entry['revealed'] ?? 0);
			unset($changed['property']);
		}

		return $changed;
	}//end changedFor()
}//end class

<?php

/**
 * Records when a protected field is SHOWN to somebody (ledger row 5.6).
 *
 * Field-level security hides a property from users outside its group, and
 * `row-field-level-security` says of its own audit that decisions are logged
 * "at debug level via LoggerInterface but ... not integrated with Nextcloud's
 * audit log". A denial is therefore on record in a place nobody reads, and a
 * REVEAL is on record nowhere at all. What a data protection officer asks is
 * not who was refused the BSN. It is who saw it.
 *
 * D-1: THE ENTRY IS WRITTEN WHERE THE VALUE SURVIVES THE FILTER, not where the
 * check runs. Those are the same line of code today and they will not always
 * be, and only one of them is the fact being recorded.
 *
 * D-2: ONCE PER REQUEST, BATCHED. A detail read reveals a field once; a list
 * read reveals it per row, and that is the finding the officer wants — forty
 * citizens' numbers on one screen is the event, not forty unrelated ones. So
 * every row is counted and the whole lot goes in as one insert at the end of
 * the request. Writing per row would put forty round trips inside a list render
 * and make the feature something an administrator switches off.
 *
 * 🔴 IT DEDUPLICATES ON (user, object, property, request) AND NOT MORE. Two
 * reveals of one field on one object in one request are one look; two objects
 * are two, even in the same list. Deduplicating more widely would collapse the
 * list case into a single entry and lose exactly the number the officer came
 * for, and deduplicating less would count a re-render as a second look.
 *
 * WHAT IT DOES NOT DO. It never decides whether a reveal may happen. That is
 * `PropertyRbacHandler`'s, and a collector that could refuse would be a second
 * enforcement path for a question already answered.
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

/**
 * Collects the reveals of audited properties, for one request.
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */
class RevealCollector {

	/**
	 * The action an entry carries.
	 *
	 * @var string
	 */
	public const ACTION = 'property.revealed';

	/**
	 * The key a property's authorization block declares the audit under.
	 *
	 * @var string
	 */
	public const AUDIT_KEY = 'audit';

	/**
	 * How many reveals one request may collect before it stops counting.
	 *
	 * A bulk export of a hundred thousand rows would otherwise assemble a
	 * hundred thousand entries in memory to describe one act. Past the bound
	 * the collector stops and says so, and D-3's process entry is the better
	 * name for what happened anyway.
	 *
	 * @var integer
	 */
	public const MAX_PER_REQUEST = 5000;

	/**
	 * The pending reveals, keyed by their identity so a repeat is one look.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $pending = [];

	/**
	 * Whether this request passed the bound.
	 *
	 * @var bool
	 */
	private bool $overflowed = false;

	/**
	 * Whether a property's authorization declares that reveals are audited.
	 *
	 * @param array<string, mixed>|null $propertyAuthorization The property's block.
	 *
	 * @return bool True only on an explicit true.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function isAudited(?array $propertyAuthorization): bool {
		if ($propertyAuthorization === null) {
			return false;
		}

		return (($propertyAuthorization[self::AUDIT_KEY] ?? null) === true);
	}//end isAudited()

	/**
	 * Record that one property of one object was shown to one user.
	 *
	 * @param string $userId Who saw it.
	 * @param string $objectUuid Which object.
	 * @param string $property Which property.
	 * @param int|null $schemaId The schema, for the entry.
	 * @param int|null $registerId The register, for the entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function record(
		string $userId,
		string $objectUuid,
		string $property,
		?int $schemaId = null,
		?int $registerId = null,
	): void {
		if ($userId === '' || $objectUuid === '' || $property === '') {
			// A reveal that cannot name all three is not an answer to "who saw
			// what". Recording it would put a row in the trail that no
			// question can reach.
			return;
		}

		$key = $userId . '|' . $objectUuid . '|' . $property;
		if (array_key_exists($key, $this->pending) === true) {
			return;
		}

		if (count($this->pending) >= self::MAX_PER_REQUEST) {
			$this->overflowed = true;
			return;
		}

		$this->pending[$key] = [
			'action' => self::ACTION,
			'user' => $userId,
			'object' => $objectUuid,
			'property' => $property,
			'schema' => $schemaId,
			'register' => $registerId,
		];
	}//end record()

	/**
	 * Record that a trusted internal run read audited properties (D-3).
	 *
	 * ONE ENTRY PER RUN, not per row. An export job or a retention sweep reads
	 * every object, and an entry per row would swamp the chain with a fact that
	 * has a better name: the job. The officer's question about a job is which
	 * job ran, not which of its million rows carried a BSN.
	 *
	 * @param string $process The process identity.
	 * @param string $runId The run, so the entries of one run are a set.
	 * @param int $revealed How many reveals the run made.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function recordProcess(string $process, string $runId, int $revealed): void {
		if ($process === '') {
			return;
		}

		$this->pending['process|' . $process . '|' . $runId] = [
			'action' => self::ACTION,
			'user' => $process,
			'object' => '',
			'property' => '',
			'process' => $process,
			'run' => $runId,
			'revealed' => $revealed,
		];
	}//end recordProcess()

	/**
	 * Take everything collected, leaving the collector empty.
	 *
	 * TAKING RATHER THAN READING is deliberate: the flush is the only consumer,
	 * and a collector that still held its rows after one would write them twice
	 * the next time anything flushed.
	 *
	 * @return array<int, array<string, mixed>> The entries, in the order they were seen.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function take(): array {
		$entries = array_values($this->pending);
		$this->pending = [];
		$this->overflowed = false;

		return $entries;
	}//end take()

	/**
	 * How many reveals are waiting.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function count(): int {
		return count($this->pending);
	}//end count()

	/**
	 * Whether this request stopped counting.
	 *
	 * Read by the flush so the overflow is LOGGED rather than silent: a trail
	 * that is quietly incomplete is worse than one that says where it stopped.
	 *
	 * @return bool True when the bound was passed.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function overflowed(): bool {
		return $this->overflowed;
	}//end overflowed()
}//end class

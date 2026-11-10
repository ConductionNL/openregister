<?php

/**
 * Unit tests for the FROZEN v1 audit canonicaliser.
 *
 * This class exists for exactly one job: to let the v1 → v2 migration check
 * what it is about to overwrite. A re-chain rewrites every stored hash, so it
 * makes an intact chain and a tampered one verify identically afterwards —
 * the pre-check is the only moment the difference is still knowable.
 *
 * Which means a canonicaliser that has quietly DRIFTED does not fail loudly. It
 * reports every row broken, the operator sees a scary warning that is really
 * just drift, the re-chain proceeds, and a genuine tamper is buried under a
 * false one. These tests are what keep it frozen.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
 */

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditCanonicalV1;
use PHPUnit\Framework\TestCase;

class AuditCanonicalV1Test extends TestCase {

	/**
	 * A row with a couple of fields set and everything else at its default.
	 */
	private function row(): AuditTrail {
		$entry = new AuditTrail();
		$entry->setId(42);
		$entry->setAction('create');

		return $entry;
	}//end row()

	/**
	 * 🔒 THE FREEZE. The exact bytes a v1 row was hashed over.
	 *
	 * If this assertion fails, the v1 form has moved and every hash sealed under
	 * seed v1 has become unverifiable. The correct response is to restore the
	 * old behaviour, NOT to update the expectation — updating it is how the
	 * check silently stops meaning anything.
	 */
	public function testTheV1CanonicalFormIsFrozen(): void {
		$expected = '{"action":"create","changed":null,"confidentiality":null,"created":null,'
			. '"expires":null,"id":42,"ipAddress":null,"object":null,"objectUuid":null,'
			. '"organisationId":null,"organisationIdType":null,"paramsDigest":null,'
			. '"processingActivityId":null,"processingActivityUrl":null,"processingId":null,'
			. '"register":null,"registerUuid":null,"request":null,"resultSummary":null,'
			. '"retentionPeriod":null,"schema":null,"schemaUuid":null,"session":null,'
			. '"size":null,"toolId":null,"user":null,"userName":null,"uuid":null,"version":null}';

		$this->assertSame($expected, AuditCanonicalV1::canonicalJson(entry: $this->row()));
	}//end testTheV1CanonicalFormIsFrozen()

	/**
	 * The v1 form does not contain the fields v2 added.
	 *
	 * Stated separately from the freeze above because it is the specific
	 * regression this class was written to prevent, and a separate failure
	 * message says so directly.
	 */
	public function testTheV1FormExcludesTheFlowAttributionFields(): void {
		$canonical = AuditCanonicalV1::canonicalJson(entry: $this->row());

		$this->assertStringNotContainsString('flowRun', $canonical);
		$this->assertStringNotContainsString('flowNode', $canonical);
		$this->assertStringNotContainsString('flowStep', $canonical);
	}//end testTheV1FormExcludesTheFlowAttributionFields()

	/**
	 * 🔑 THE PROPERTY THE MIGRATION DEPENDS ON.
	 *
	 * Two rows differing ONLY in flow attribution must canonicalise identically
	 * under v1. That is what makes a pre-existing row — which has no attribution
	 * — verify against a hash sealed before the columns existed.
	 */
	public function testAttributionDoesNotChangeTheV1Form(): void {
		$without = $this->row();

		$with = $this->row();
		$with->setFlowRun('run-abc');
		$with->setFlowNode('node-1');
		$with->setFlowStep(3);

		$this->assertSame(
			AuditCanonicalV1::canonicalJson(entry: $without),
			AuditCanonicalV1::canonicalJson(entry: $with),
			'The v1 form must be blind to fields v2 introduced, or no pre-existing row can verify.'
		);
	}//end testAttributionDoesNotChangeTheV1Form()

	/**
	 * A change to any v1 field DOES change the form — the canonicaliser is
	 * blind to the new fields, not blind in general.
	 */
	public function testAV1FieldStillChangesTheForm(): void {
		$before = AuditCanonicalV1::canonicalJson(entry: $this->row());

		$changed = $this->row();
		$changed->setAction('delete');

		$this->assertNotSame($before, AuditCanonicalV1::canonicalJson(entry: $changed));
	}//end testAV1FieldStillChangesTheForm()

	/**
	 * The v1 genesis seed is the v1 seed, not the current one.
	 */
	public function testTheGenesisHashIsTheV1Seed(): void {
		$this->assertSame('openregister-genesis-v1', AuditCanonicalV1::GENESIS_SEED);
		$this->assertSame(
			hash('sha256', 'openregister-genesis-v1'),
			AuditCanonicalV1::genesisHash()
		);
	}//end testTheGenesisHashIsTheV1Seed()

	/**
	 * Hashing chains the predecessor in, so the same row after a different
	 * predecessor hashes differently.
	 */
	public function testTheHashChainsThePredecessor(): void {
		$row = $this->row();

		$this->assertNotSame(
			AuditCanonicalV1::computeHash(entry: $row, previousHash: 'aaa'),
			AuditCanonicalV1::computeHash(entry: $row, previousHash: 'bbb')
		);
	}//end testTheHashChainsThePredecessor()
}//end class

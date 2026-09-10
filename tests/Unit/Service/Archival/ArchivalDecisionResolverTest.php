<?php

declare(strict_types=1);

/**
 * ArchivalDecisionResolver tests.
 *
 * Pins the merge that fills `@self._retention` — the slot that was declared on
 * the entity and written by nothing outside a unit test, while the facts it
 * should carry sat unmerged in three sub-blocks of `retention`.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ArchivalDecisionResolver.
 */
class ArchivalDecisionResolverTest extends TestCase {

	private ArchivalDecisionResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ArchivalDecisionResolver();
	}

	/**
	 * Build an entity carrying the given retention block.
	 *
	 * @param array<string, mixed>|null $retention The stored retention block.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entityWith(?array $retention): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRetention($retention);
		return $entity;
	}

	/**
	 * An object with no retention block has no decision, and the key is omitted
	 * rather than reported as an empty object — "no obligation declared" and
	 * "we looked and found none" must stay distinguishable.
	 */
	public function testReturnsNullWhenThereIsNoRetentionBlock(): void {
		$this->assertNull($this->resolver->resolve(entity: $this->entityWith(retention: null)));
		$this->assertNull($this->resolver->resolve(entity: $this->entityWith(retention: [])));
	}

	/**
	 * A block carrying only keys this resolver does not read establishes
	 * nothing, and is reported as no decision.
	 */
	public function testReturnsNullWhenNothingIsEstablished(): void {
		$entity = $this->entityWith(retention: ['somethingElse' => 'value']);

		$this->assertNull($this->resolver->resolve(entity: $entity));
	}

	/**
	 * The RetentionService shape — a selectielijst-backed obligation — resolves
	 * into the abstract keys, with its authority named.
	 */
	public function testResolvesTheStoredSelectielijstDecision(): void {
		$entity = $this->entityWith(
			retention: [
				'archiefnominatie' => 'vernietigen',
				'archiefstatus' => 'nog_te_archiveren',
				'classification' => '4.1.2',
				'bewaartermijn' => 'P10Y',
				'archiefactiedatum' => '2036-09-10',
				'selectielijstBron' => 'Selectielijst gemeenten 2020',
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('vernietigen', $decision['nomination']);
		$this->assertSame('nog_te_archiveren', $decision['status']);
		$this->assertSame('4.1.2', $decision['classification']);
		$this->assertSame('P10Y', $decision['period']);
		$this->assertSame('2036-09-10', $decision['actionDate']);
		$this->assertSame('selectielijst', $decision['basis']);
		$this->assertSame('Selectielijst gemeenten 2020', $decision['source']);
	}

	/**
	 * With no stored decision, the schema annotation's evaluation supplies the
	 * period and the date, and says so.
	 */
	public function testFallsBackToTheSchemaAnnotation(): void {
		$entity = $this->entityWith(
			retention: [
				'annotation' => [
					'effectiveRetention' => 'P5Y',
					'matchedRule' => 2,
					'expiresAt' => '2031-01-01T00:00:00+00:00',
				],
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('P5Y', $decision['period']);
		$this->assertSame('2031-01-01T00:00:00+00:00', $decision['actionDate']);
		$this->assertSame('annotation', $decision['basis']);
		$this->assertSame(2, $decision['annotation']['matchedRule']);
	}

	/**
	 * A decision recorded against THIS object beats a rule evaluated from the
	 * schema — somebody decided the first one, the second is a default.
	 */
	public function testStoredDecisionWinsOverTheAnnotation(): void {
		$entity = $this->entityWith(
			retention: [
				'bewaartermijn' => 'P20Y',
				'archiefactiedatum' => '2046-09-10',
				'annotation' => [
					'effectiveRetention' => 'P5Y',
					'expiresAt' => '2031-01-01T00:00:00+00:00',
				],
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('P20Y', $decision['period']);
		$this->assertSame('2046-09-10', $decision['actionDate']);
		$this->assertSame('schema', $decision['basis']);
	}

	/**
	 * `bewaren` and `blijvend_bewaren` mean the same thing to an archivist, and
	 * must not reach a reader as two different nominations.
	 */
	public function testNormalisesTheShortKeepNomination(): void {
		$entity = $this->entityWith(retention: ['archiefnominatie' => 'bewaren']);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('blijvend_bewaren', $decision['nomination']);
	}

	/**
	 * A nomination this resolver has not been taught passes through. Dropping
	 * it would report "no nomination" for an object that carries one, which is
	 * the failure mode that hides a records obligation.
	 */
	public function testPassesAnUnknownNominationThrough(): void {
		$entity = $this->entityWith(retention: ['archiefnominatie' => 'overbrengen']);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('overbrengen', $decision['nomination']);
	}

	/**
	 * An active legal hold is reported with who placed it and why.
	 */
	public function testReportsAnActiveLegalHold(): void {
		$entity = $this->entityWith(
			retention: [
				'archiefnominatie' => 'vernietigen',
				'legalHold' => [
					'active' => true,
					'reason' => 'Pending appeal',
					'placedBy' => 'admin',
					'placedDate' => '2026-09-01T10:00:00+00:00',
				],
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertTrue($decision['legalHold']['active']);
		$this->assertSame('Pending appeal', $decision['legalHold']['reason']);
		$this->assertSame('admin', $decision['legalHold']['placedBy']);
	}

	/**
	 * A released hold still reports itself. "Held once and released" is a
	 * different fact from "never held", and an archivist reads the difference.
	 */
	public function testReportsAReleasedLegalHoldWithItsHistory(): void {
		$entity = $this->entityWith(
			retention: [
				'archiefnominatie' => 'vernietigen',
				'legalHold' => [
					'active' => false,
					'history' => [
						['reason' => 'Pending appeal', 'releasedBy' => 'admin'],
					],
				],
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertFalse($decision['legalHold']['active']);
		$this->assertSame(1, $decision['legalHold']['releasedCount']);
	}

	/**
	 * An object never placed on hold reports no hold at all, rather than an
	 * inactive one — otherwise every object in the register looks like it has
	 * a hold history.
	 */
	public function testReportsNoLegalHoldWhenNeverHeld(): void {
		$entity = $this->entityWith(retention: ['archiefnominatie' => 'vernietigen']);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertNull($decision['legalHold']);
	}

	/**
	 * The case this resolver exists for: an app that implements the ZGW zaak
	 * API declares the archival fields as ORDINARY SCHEMA PROPERTIES on its own
	 * record, and derives them on close. Reading only `@self.retention` would
	 * have left `_retention` empty on exactly those records.
	 */
	public function testReadsTheArchivalFieldsTheRecordItselfDeclares(): void {
		$entity = new ObjectEntity();
		$entity->setObject([
			'title' => 'Dakkapel Kerkstraat 12',
			'archiveNomination' => 'vernietigen',
			'archiveActionDate' => '2036-09-10',
			'archiveStatus' => 'nog_te_archiveren',
		]);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('vernietigen', $decision['nomination']);
		$this->assertSame('2036-09-10', $decision['actionDate']);
		$this->assertSame('nog_te_archiveren', $decision['status']);
		$this->assertSame('record', $decision['basis']);
	}

	/**
	 * The Dutch spelling too, for an app whose data model mirrors ZGW verbatim.
	 */
	public function testReadsTheDutchSpellingOfTheSameFields(): void {
		$entity = new ObjectEntity();
		$entity->setObject([
			'archiefnominatie' => 'blijvend_bewaren',
			'archiefactiedatum' => '2040-01-01',
		]);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('blijvend_bewaren', $decision['nomination']);
		$this->assertSame('2040-01-01', $decision['actionDate']);
	}

	/**
	 * A decision recorded through the retention service beats a field an app
	 * wrote for its own API consumers.
	 */
	public function testStoredRetentionWinsOverTheRecordsOwnFields(): void {
		$entity = new ObjectEntity();
		$entity->setRetention(['archiefnominatie' => 'blijvend_bewaren', 'archiefactiedatum' => '2099-01-01']);
		$entity->setObject(['archiveNomination' => 'vernietigen', 'archiveActionDate' => '2030-01-01']);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('blijvend_bewaren', $decision['nomination']);
		$this->assertSame('2099-01-01', $decision['actionDate']);
	}

	/**
	 * An ordinary record with no archival claim anywhere still resolves to
	 * nothing, so the key stays absent rather than becoming a row of dashes on
	 * every object in every register.
	 */
	public function testAnOrdinaryRecordStillHasNoDecision(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['title' => 'Just a record', 'status' => 'active']);

		$this->assertNull($this->resolver->resolve(entity: $entity));
	}

	/**
	 * The resolved decision reaches a client through `@self._retention`, which
	 * is the whole point of the merge.
	 */
	public function testTheDecisionSerializesIntoTheSelfEnvelope(): void {
		$entity = $this->entityWith(retention: ['archiefnominatie' => 'vernietigen']);

		$entity->setArchivalRetention($this->resolver->resolve(entity: $entity));
		$serialized = $entity->jsonSerialize();

		$this->assertSame('vernietigen', $serialized['@self']['_retention']['nomination']);
	}

}//end class

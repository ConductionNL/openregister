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

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver;
use OCA\OpenRegister\Service\Archival\RecordState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

		$this->assertSame('destroy', $decision['appraisal']);
		// RetentionService's own status vocabulary, mapped into the Archiefwet
		// lifecycle so the abstract layer answers in one set of terms (gap A4).
		$this->assertSame('active', $decision['recordState']);
		$this->assertSame('4.1.2', $decision['disposalCategory']);
		$this->assertSame('P10Y', $decision['retentionPeriod']);
		$this->assertSame('2036-09-10', $decision['disposalDate']);
		$this->assertSame('selection_list', $decision['basis']);
		$this->assertSame('Selectielijst gemeenten 2020', $decision['source']);
	}

	/**
	 * GAP B1: the abstract layer carries the list's version and the moment it
	 * was consulted, not only its name.
	 *
	 * A destruction date justified by a list name alone cannot be defended
	 * once that list revises, because the same category carries different
	 * retention periods across revisions.
	 */
	public function testCarriesTheSelectionListProvenance(): void {
		$entity = $this->entityWith(
			retention: [
				'archiefnominatie' => 'vernietigen',
				'bewaartermijn' => 'P10Y',
				'selectielijstBron' => 'Selectielijst gemeenten 2020',
				'selectionListVersion' => '2020.2',
				'selectionListConsultedAt' => '2026-09-10T12:00:00+00:00',
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('Selectielijst gemeenten 2020', $decision['source']);
		$this->assertSame('2020.2', $decision['sourceVersion']);
		$this->assertSame('2026-09-10T12:00:00+00:00', $decision['sourceConsultedAt']);
	}

	/**
	 * A decision recorded before the provenance keys existed reports no
	 * version rather than a null one.
	 *
	 * An absent key is silence, which is the truth about a decision taken when
	 * nothing recorded the version. A key holding null is a claim that the
	 * version was looked for and found to be nothing, which is false.
	 */
	public function testOmitsProvenanceItDoesNotHave(): void {
		$entity = $this->entityWith(
			retention: [
				'archiefnominatie' => 'vernietigen',
				'bewaartermijn' => 'P10Y',
				'selectielijstBron' => 'Selectielijst gemeenten 2020',
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertArrayNotHasKey('sourceVersion', $decision);
		$this->assertArrayNotHasKey('sourceConsultedAt', $decision);
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

		$this->assertSame('P5Y', $decision['retentionPeriod']);
		$this->assertSame('2031-01-01T00:00:00+00:00', $decision['disposalDate']);
		$this->assertSame('schema_annotation', $decision['basis']);
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

		$this->assertSame('P20Y', $decision['retentionPeriod']);
		$this->assertSame('2046-09-10', $decision['disposalDate']);
		$this->assertSame('schema', $decision['basis']);
	}

	/**
	 * `bewaren` and `blijvend_bewaren` mean the same thing to an archivist, and
	 * must not reach a reader as two different nominations.
	 */
	public function testNormalisesTheShortKeepNomination(): void {
		$entity = $this->entityWith(retention: ['archiefnominatie' => 'bewaren']);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('retain_permanently', $decision['appraisal']);
	}

	/**
	 * A nomination this resolver has not been taught passes through. Dropping
	 * it would report "no nomination" for an object that carries one, which is
	 * the failure mode that hides a records obligation.
	 */
	public function testPassesAnUnknownNominationThrough(): void {
		$entity = $this->entityWith(retention: ['archiefnominatie' => 'overbrengen']);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('overbrengen', $decision['appraisal']);
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

		// Omitted, not nulled: a null would not survive the read path, so the
		// create response and the GET response would disagree about it.
		$this->assertArrayNotHasKey('legalHold', $decision);
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

		$this->assertSame('destroy', $decision['appraisal']);
		$this->assertSame('2036-09-10', $decision['disposalDate']);
		// RetentionService's own status vocabulary, mapped into the Archiefwet
		// lifecycle so the abstract layer answers in one set of terms (gap A4).
		$this->assertSame('active', $decision['recordState']);
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

		$this->assertSame('retain_permanently', $decision['appraisal']);
		$this->assertSame('2040-01-01', $decision['disposalDate']);
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

		$this->assertSame('retain_permanently', $decision['appraisal']);
		$this->assertSame('2099-01-01', $decision['disposalDate']);
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

		$this->assertSame('destroy', $serialized['@self']['_retention']['appraisal']);
	}

	/**
	 * GAP A1, the defect this whole pass exists for: the TMLO block is a source.
	 *
	 * An object whose archival metadata lives only in `tmlo` used to resolve to
	 * NO decision at all — the exact silence `_retention` was built to end.
	 */
	public function testReadsTheTmloBlock(): void {
		$entity = new ObjectEntity();
		$entity->setTmlo(
			[
				'archiefnominatie' => 'blijvend_bewaren',
				'bewaarTermijn' => 'P20Y',
				'archiefactiedatum' => '2046-01-01',
				'archiefstatus' => 'semi_statisch',
				'vernietigingsCategorie' => '4.1.2',
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertNotNull($decision, 'a tmlo-only object must resolve to a decision');
		$this->assertSame('retain_permanently', $decision['appraisal']);
		$this->assertSame('P20Y', $decision['retentionPeriod']);
		$this->assertSame('2046-01-01', $decision['disposalDate']);
		$this->assertSame('semi_static', $decision['recordState']);
		$this->assertSame('4.1.2', $decision['disposalCategory']);
		$this->assertSame('tmlo', $decision['basis']);
	}

	/**
	 * GAP A2: the Archiefwet lifecycle, and whether the record is still ours.
	 *
	 * A transferred or destroyed record is no longer this system's to alter, and
	 * a consumer must be able to grey an edit from the boolean alone rather than
	 * having to know the lifecycle.
	 */
	public function testATransferredRecordIsReportedImmutable(): void {
		$entity = new ObjectEntity();
		$entity->setTmlo(['archiefstatus' => 'overgebracht']);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('transferred', $decision['recordState']);
		$this->assertTrue($decision['immutable']);
	}

	/**
	 * An active record is mutable, so the same boolean answers both ways.
	 */
	public function testAnActiveRecordIsNotImmutable(): void {
		$entity = new ObjectEntity();
		$entity->setTmlo(['archiefstatus' => 'actief']);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('active', $decision['recordState']);
		$this->assertFalse($decision['immutable']);
	}

	/**
	 * The stored retention block still wins over TMLO where both speak.
	 *
	 * `retention` is what the retention service decided against THIS object;
	 * `tmlo` may be older metadata carried in.
	 */
	public function testTheRetentionBlockWinsOverTmlo(): void {
		$entity = new ObjectEntity();
		$entity->setRetention(['bewaartermijn' => 'P5Y']);
		$entity->setTmlo(['bewaarTermijn' => 'P20Y']);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('P5Y', $decision['retentionPeriod']);
	}

	/**
	 * GAP B2: "the selectielijst was never consulted" is its own answer.
	 *
	 * A schema that declares a `classification` EXPECTS a list to be applied.
	 * Reporting `schema` when none came back is true but hides the fact that the
	 * lookup an operator believed was running never ran.
	 */
	public function testSaysWhenTheSelectionListWasExpectedAndNotConsulted(): void {
		$entity = new ObjectEntity();
		$entity->setRetention(
			[
				'classification' => '4.1.2',
				'archiefnominatie' => 'vernietigen',
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('selection_list_not_consulted', $decision['basis']);
	}

	/**
	 * And when the list DID answer, it is named as the authority.
	 */
	public function testNamesTheSelectionListWhenItAnswered(): void {
		$entity = new ObjectEntity();
		$entity->setRetention(
			[
				'classification' => '4.1.2',
				'selectielijstBron' => 'Selectielijst gemeenten 2020',
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame('selection_list', $decision['basis']);
		$this->assertSame('Selectielijst gemeenten 2020', $decision['source']);
	}

	/**
	 * EVERY SPELLING RecordState ACCEPTS RESOLVES TO ITS ENGLISH STATE.
	 *
	 * This resolver used to keep its own alias map, and the copy had drifted:
	 * `gearchiveerd` was listed as semi-static by RecordState and was missing
	 * here, so a record stored with it answered the raw Dutch word from the one
	 * layer whose job is to hand out a single vocabulary. The two lists are now
	 * one list, and this walks it so a future addition to RecordState that is
	 * not understood here fails instead of passing through.
	 *
	 * @dataProvider storedStateProvider
	 *
	 * @param string $stored The spelling as stored on the record.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function testEveryStoredSpellingResolvesToItsEnglishState(
		string $stored
	): void {
		$entity = $this->entityWith(
			retention: [
				'archiefnominatie' => 'vernietigen',
				'archiefstatus' => $stored,
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertNotNull($decision);
		$this->assertContains(
			$decision['recordState'],
			RecordState::ALL,
			sprintf(
				'the stored spelling "%s" is accepted by RecordState, so it must resolve to one '
				. 'of the English states and not to itself; it resolved to "%s"',
				$stored,
				$decision['recordState']
			)
		);
	}

	/**
	 * Every spelling RecordState says it accepts.
	 *
	 * 🔴 THIS READS `ALL_ALIASES`, NOT `CANONICAL`, AND THE DIFFERENCE IS THE
	 * WHOLE TEST. Deriving the cases from the same map the assertion checks
	 * would be a tautology: delete a spelling from `CANONICAL` and the test
	 * would simply run one case fewer and stay green, which is exactly how the
	 * `gearchiveerd` gap survived in the first place. `ALL_ALIASES` is the
	 * independent claim about what stored data may contain, so a spelling that
	 * is accepted there and unmapped here now fails.
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function storedStateProvider(): array {
		$cases = [];
		foreach (RecordState::ALL_ALIASES as $stored) {
			$cases[$stored] = [$stored];
		}

		return $cases;
	}

	/**
	 * A spelling nobody declared passes through rather than being forced into a
	 * state the record does not carry. Guessing here would be worse than not
	 * answering: it would report a lifecycle position nobody wrote.
	 */
	public function testAnUnknownSpellingIsNotForcedIntoAState(): void {
		$decision = $this->resolver->resolve(
			entity: $this->entityWith(
				retention: [
					'archiefnominatie' => 'vernietigen',
					'archiefstatus' => 'in_de_kast',
				]
			)
		);

		$this->assertNotNull($decision);
		$this->assertSame('in_de_kast', $decision['recordState']);
		$this->assertFalse($decision['immutable']);
	}

	/**
	 * "No rule matched" is an ANSWER, and it must reach the client as one.
	 *
	 * RetentionEvaluator reports the default applying as `matchedRule: null`.
	 * The read path strips nulls from every response, so on GET that key used
	 * to vanish while the create response still carried it: the same object
	 * answered in two shapes, and on read "no rule fired" could not be told
	 * from "this reader does not know". The resolver now says it with a
	 * boolean the strip keeps, and omits every key nothing established.
	 *
	 * This pins the WHOLE block, key order included, because the complaint was
	 * about the shape, not about one value in it.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#scenario-no-matching-rule-reads-the-same-on-create-and-on-read
	 */
	public function testNoMatchedRuleIsReportedAsDefaultedAndNothingIsNulled(): void {
		$entity = $this->entityWith(
			retention: [
				'annotation' => [
					'effectiveRetention' => 'P30D',
					'matchedRule' => null,
					'expiresAt' => '2026-01-31T00:00:00+00:00',
				],
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertSame(
			[
				'retentionPeriod' => 'P30D',
				'disposalDate' => '2026-01-31T00:00:00+00:00',
				'basis' => 'schema_annotation',
				'annotation' => [
					'effectiveRetention' => 'P30D',
					'defaulted' => true,
					'expiresAt' => '2026-01-31T00:00:00+00:00',
				],
			],
			$decision
		);
	}

	/**
	 * When a rule DID fire, its index is still the answer, and `defaulted`
	 * says the same thing from the other side.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#scenario-archival-row-read-shows-the-resolved-decision
	 */
	public function testAMatchedRuleKeepsItsIndexAndIsNotDefaulted(): void {
		$entity = $this->entityWith(
			retention: [
				'annotation' => [
					'effectiveRetention' => 'PT1H',
					'matchedRule' => 0,
					'expiresAt' => '2026-01-01T01:00:00+00:00',
				],
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		// Rule index 0 is falsy and must survive as a real answer.
		$this->assertSame(
			[
				'effectiveRetention' => 'PT1H',
				'matchedRule' => 0,
				'defaulted' => false,
				'expiresAt' => '2026-01-01T01:00:00+00:00',
			],
			$decision['annotation']
		);
	}

	/**
	 * An annotation block that records no evaluation is not given one.
	 *
	 * Inventing `defaulted` for a block with no `matchedRule` key would claim
	 * that somebody checked the rules, which the block does not say.
	 */
	public function testAnAnnotationWithoutAMatchedRuleIsNotGivenADefaultedFlag(): void {
		$entity = $this->entityWith(
			retention: [
				'annotation' => [
					'effectiveRetention' => 'P5Y',
					'expiresAt' => '2031-01-01T00:00:00+00:00',
				],
			]
		);

		$decision = $this->resolver->resolve(entity: $entity);

		$this->assertArrayNotHasKey('defaulted', $decision['annotation']);
		$this->assertArrayNotHasKey('matchedRule', $decision['annotation']);
	}

	/**
	 * THE PROPERTY THE FIX RESTS ON: the decision is a fixed point of the read
	 * path's strip.
	 *
	 * `ObjectsController::show()` runs its whole response through
	 * `stripEmptyValues()` unless `_empty=true`; create, update and patch do
	 * not. A block that the strip leaves unchanged therefore reads the same on
	 * every verb. This drives the real private method rather than restating its
	 * rule, over a decision that exercises every nullable key at once, so a
	 * null reintroduced anywhere in the resolver (or a strip that learns a new
	 * kind of empty) reddens here.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#scenario-no-matching-rule-reads-the-same-on-create-and-on-read
	 */
	public function testTheDecisionIsUnchangedByTheReadPathStrip(): void {
		$cases = [
			'annotation only, no rule matched' => $this->entityWith(
				retention: [
					'annotation' => [
						'effectiveRetention' => 'P30D',
						'matchedRule' => null,
						'expiresAt' => '2026-01-31T00:00:00+00:00',
					],
				]
			),
			'active hold with no reason, blank strings, and a list' => $this->entityWith(
				retention: [
					'archiefnominatie' => 'vernietigen',
					'classification' => '   ',
					'legalHold' => ['active' => true, 'reason' => null, 'placedBy' => ''],
					'annotation' => [
						'effectiveRetention' => 'P1Y',
						'matchedRule' => 1,
						'expiresAt' => '2027-01-01T00:00:00+00:00',
						'trace' => [['rule' => 0, 'note' => null], 'skipped'],
						'empty' => ['nested' => null],
					],
				]
			),
		];

		$controller = (new ReflectionClass(ObjectsController::class))->newInstanceWithoutConstructor();
		$strip = new \ReflectionMethod(ObjectsController::class, 'stripEmptyValues');

		foreach ($cases as $label => $entity) {
			$decision = $this->resolver->resolve(entity: $entity);
			$this->assertNotNull($decision, $label);

			$this->assertSame(
				$decision,
				$strip->invoke($controller, $decision),
				"{$label}: the read path's strip changed the decision, so create and GET would answer in different shapes"
			);
		}
	}

}//end class

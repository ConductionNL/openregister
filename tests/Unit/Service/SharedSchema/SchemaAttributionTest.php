<?php

/**
 * Unit tests for shared-schema detection and ownership attribution (#2689).
 *
 * The controls in this file are deliberately paired. A repair that mutates
 * schema linkage must be proven to fire on the broken instance AND to stay
 * silent on the healthy one — a check that cannot fail proves nothing, and a
 * check that always fires is worse than none for a command that rewrites
 * register configuration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SharedSchema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\SharedSchema;

use OCA\OpenRegister\Service\SharedSchema\SchemaAttribution;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Locks the decision layer of `occ openregister:registers:dedupe-shared-schemas`.
 */
class SchemaAttributionTest extends TestCase {

	/**
	 * The subject under test.
	 *
	 * @var SchemaAttribution
	 */
	private SchemaAttribution $attribution;

	/**
	 * Build the (dependency-free) subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->attribution = new SchemaAttribution();

	}//end setUp()

	/**
	 * The definition planix's `timeEntry` had, which overwrote pipelinq's.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private function planixTimeEntry(): array {
		return [
			'slug'       => 'timeEntry',
			'required'   => ['description'],
			'properties' => [
				'description' => ['type' => 'string'],
				'date'        => ['type' => 'string'],
				'duration'    => ['type' => 'number'],
				'employee'    => ['type' => 'string'],
				'task'        => ['type' => 'string'],
				'approved'    => ['type' => 'boolean'],
			],
		];

	}//end planixTimeEntry()

	/**
	 * The definition pipelinq's billing features depend on, which was wiped.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private function pipelinqTimeEntry(): array {
		return [
			'slug'       => 'timeEntry',
			'required'   => ['hours'],
			'properties' => [
				'hours'           => ['type' => 'number'],
				'billingCategory' => ['type' => 'string'],
				'client'          => ['type' => 'string'],
				'project'         => ['type' => 'string'],
				'billingSynced'   => ['type' => 'boolean'],
			],
		];

	}//end pipelinqTimeEntry()

	/**
	 * MUST-PASS CONTROL: the observed planix/pipelinq pair is detected and attributed.
	 *
	 * Registers 19 (planix) and 16 (pipelinq) both reference schema 161, whose
	 * stored content is planix's definition. Only planix's configuration matches,
	 * so planix keeps the entity and pipelinq is the one that must be split off.
	 *
	 * @return void
	 */
	public function testDetectsAndAttributesTheObservedSharedPair(): void {
		$shared = $this->attribution->indexShared(
			registerSchemas: [
				19 => [74, 159, 161],
				16 => [74, 159, 161],
				7  => [900],
			]
		);

		$this->assertSame([74, 159, 161], array_keys($shared), 'every co-referenced id must be reported');
		$this->assertSame([16, 19], $shared[161], 'both referencing registers must be named');
		$this->assertArrayNotHasKey(900, $shared, 'a singly-referenced schema is not shared');

		$verdict = $this->attribution->classify(
			candidates: [19 => $this->planixTimeEntry(), 16 => $this->pipelinqTimeEntry()],
			entity: $this->planixTimeEntry()
		);

		$this->assertSame(SchemaAttribution::STATUS_ONE_MATCH, $verdict['status']);
		$this->assertSame(19, $verdict['owner'], 'planix owns the definition the entity actually holds');
		$this->assertSame([19], $verdict['matches']);

		$owner = $this->attribution->resolveOwner(
			verdict: $verdict,
			schemaId: 161,
			registerIds: [16, 19],
			keep: ['perSchema' => [], 'global' => null]
		);

		$this->assertSame(19, $owner['owner']);
		$this->assertSame('configuration', $owner['source']);

	}//end testDetectsAndAttributesTheObservedSharedPair()

	/**
	 * MUST-FAIL CONTROL: a healthy instance yields nothing to repair.
	 *
	 * No register co-references a schema, so detection produces an empty plan and
	 * the command has nothing to write. Without this control the must-pass test
	 * above would still succeed for a detector that reported every schema.
	 *
	 * @return void
	 */
	public function testHealthyInstanceHasNothingToRepair(): void {
		$shared = $this->attribution->indexShared(
			registerSchemas: [
				19 => [74, 159, 161],
				16 => [9463, 9464, 9465],
				7  => [],
			]
		);

		$this->assertSame([], $shared, 'no schema is co-referenced, so nothing is shared');

	}//end testHealthyInstanceHasNothingToRepair()

	/**
	 * IDEMPOTENCE: feeding the post-split state back reports nothing.
	 *
	 * After the repair, register 16 points at its own 9463/9464/9465 (the ids the
	 * manual validation produced) and register 19 keeps 74/159/161. A second run
	 * must therefore be a no-op — a repair that re-fires on its own output would
	 * fork a new schema on every invocation.
	 *
	 * @return void
	 */
	public function testSecondRunAfterASplitIsANoOp(): void {
		$before = $this->attribution->indexShared(
			registerSchemas: [19 => [74, 159, 161], 16 => [74, 159, 161]]
		);
		$this->assertNotSame([], $before, 'guard: the pre-split state must be detectable');

		$after = [19 => [74, 159, 161], 16 => [74, 159, 161]];
		foreach ([74 => 9463, 159 => 9464, 161 => 9465] as $oldId => $newId) {
			$after[16] = $this->attribution->replaceSchemaId(schemas: $after[16], oldId: $oldId, newId: $newId);
		}

		$this->assertSame([9463, 9464, 9465], $after[16], 'the relink must preserve order');
		$this->assertSame([], $this->attribution->indexShared(registerSchemas: $after));

	}//end testSecondRunAfterASplitIsANoOp()

	/**
	 * Attribution matrix: no referencing register's configuration matches.
	 *
	 * Both apps have since moved on, so the entity matches neither. Guessing here
	 * is exactly what produced the damage, so the verdict carries no owner.
	 *
	 * @return void
	 */
	public function testNoMatchYieldsNoOwner(): void {
		$verdict = $this->attribution->classify(
			candidates: [19 => $this->planixTimeEntry(), 16 => $this->pipelinqTimeEntry()],
			entity: ['properties' => ['somethingElse' => ['type' => 'string']], 'required' => []]
		);

		$this->assertSame(SchemaAttribution::STATUS_NO_MATCH, $verdict['status']);
		$this->assertNull($verdict['owner']);
		$this->assertSame([], $verdict['matches']);

		$owner = $this->attribution->resolveOwner(
			verdict: $verdict,
			schemaId: 161,
			registerIds: [16, 19],
			keep: ['perSchema' => [], 'global' => null]
		);

		$this->assertNull($owner['owner'], 'an unattributed schema must not acquire an owner by accident');
		$this->assertSame('unattributed', $owner['source']);

	}//end testNoMatchYieldsNoOwner()

	/**
	 * Attribution matrix: several configurations match the entity.
	 *
	 * Two apps legitimately declare the same shape. That is ambiguous, not
	 * attributable, so both are listed and no owner is chosen.
	 *
	 * @return void
	 */
	public function testMultiMatchYieldsNoOwner(): void {
		$verdict = $this->attribution->classify(
			candidates: [19 => $this->planixTimeEntry(), 16 => $this->planixTimeEntry()],
			entity: $this->planixTimeEntry()
		);

		$this->assertSame(SchemaAttribution::STATUS_MULTI_MATCH, $verdict['status']);
		$this->assertNull($verdict['owner']);
		$this->assertSame([16, 19], $verdict['matches'], 'both matching registers must be reported');

	}//end testMultiMatchYieldsNoOwner()

	/**
	 * A register with no configuration on disk can never be a match.
	 *
	 * @return void
	 */
	public function testRegisterWithoutConfigurationIsNotACandidate(): void {
		$verdict = $this->attribution->classify(
			candidates: [19 => null, 16 => $this->pipelinqTimeEntry()],
			entity: $this->pipelinqTimeEntry()
		);

		$this->assertSame(SchemaAttribution::STATUS_ONE_MATCH, $verdict['status']);
		$this->assertSame(16, $verdict['owner']);

	}//end testRegisterWithoutConfigurationIsNotACandidate()

	/**
	 * The signature ignores property bodies but not the property NAME set.
	 *
	 * The import path stamps defaults and rewrites descriptions, so byte equality
	 * would put every schema in `no-match`. Losing a property, which is what the
	 * overwrite actually did, must still register as a difference.
	 *
	 * @return void
	 */
	public function testSignatureIgnoresBodiesButNotTheNameSet(): void {
		$restyled = $this->pipelinqTimeEntry();
		$restyled['properties']['hours'] = [
			'type'        => 'number',
			'description' => 'Hours worked',
			'default'     => 0,
		];

		$this->assertSame(
			$this->attribution->signature(definition: $this->pipelinqTimeEntry()),
			$this->attribution->signature(definition: $restyled),
			'a re-stamped property body must not change the signature'
		);

		$shortened = $this->pipelinqTimeEntry();
		unset($shortened['properties']['billingCategory']);

		$this->assertNotSame(
			$this->attribution->signature(definition: $this->pipelinqTimeEntry()),
			$this->attribution->signature(definition: $shortened),
			'a lost property MUST change the signature'
		);

	}//end testSignatureIgnoresBodiesButNotTheNameSet()

	/**
	 * A schema id stored as a string still counts as a reference.
	 *
	 * Different import eras wrote the list differently, so `"161"` and `161` must
	 * be recognised as the same pairing or the sharing goes undetected.
	 *
	 * @return void
	 */
	public function testMixedIdTypesAreStillDetectedAsShared(): void {
		$shared = $this->attribution->indexShared(
			registerSchemas: [19 => ['161'], 16 => [161]]
		);

		$this->assertSame([161 => [16, 19]], $shared);

	}//end testMixedIdTypesAreStillDetectedAsShared()

	/**
	 * `--keep <schemaId>:<registerId>` pins one schema; a bare id covers the rest.
	 *
	 * @return void
	 */
	public function testKeepOptionParsesBothForms(): void {
		$keep = $this->attribution->parseKeep(raw: ['161:16', '74:19', '19']);

		$this->assertSame([161 => 16, 74 => 19], $keep['perSchema']);
		$this->assertSame(19, $keep['global']);

	}//end testKeepOptionParsesBothForms()

	/**
	 * A per-schema `--keep` outranks a bare one.
	 *
	 * @return void
	 */
	public function testPerSchemaKeepOutranksTheGlobalOne(): void {
		$owner = $this->attribution->resolveOwner(
			verdict: ['status' => SchemaAttribution::STATUS_NO_MATCH, 'owner' => null, 'matches' => []],
			schemaId: 161,
			registerIds: [16, 19],
			keep: ['perSchema' => [161 => 16], 'global' => 19]
		);

		$this->assertSame(16, $owner['owner']);
		$this->assertSame('keep', $owner['source']);

	}//end testPerSchemaKeepOutranksTheGlobalOne()

	/**
	 * A `--keep` naming a register that does not reference the schema is ignored.
	 *
	 * Honouring it would relink every referencing register onto a fresh entity —
	 * a bigger change than the operator asked for — so the schema stays
	 * unattributed and the write is refused instead.
	 *
	 * @return void
	 */
	public function testKeepIsIgnoredWhenItNamesAnUnrelatedRegister(): void {
		$owner = $this->attribution->resolveOwner(
			verdict: ['status' => SchemaAttribution::STATUS_MULTI_MATCH, 'owner' => null, 'matches' => [16, 19]],
			schemaId: 161,
			registerIds: [16, 19],
			keep: ['perSchema' => [161 => 4242], 'global' => null]
		);

		$this->assertNull($owner['owner']);
		$this->assertSame('unattributed', $owner['source']);

	}//end testKeepIsIgnoredWhenItNamesAnUnrelatedRegister()

	/**
	 * A `--keep` cannot override an attribution the configuration already settled,
	 * unless it is the specific per-schema form.
	 *
	 * @return void
	 */
	public function testGlobalKeepDoesNotOverrideASettledAttribution(): void {
		$owner = $this->attribution->resolveOwner(
			verdict: ['status' => SchemaAttribution::STATUS_ONE_MATCH, 'owner' => 19, 'matches' => [19]],
			schemaId: 161,
			registerIds: [16, 19],
			keep: ['perSchema' => [], 'global' => 16]
		);

		$this->assertSame(19, $owner['owner']);
		$this->assertSame('configuration', $owner['source']);

	}//end testGlobalKeepDoesNotOverrideASettledAttribution()

	/**
	 * A malformed `--keep` is refused rather than silently ignored.
	 *
	 * @return void
	 */
	public function testMalformedKeepIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->attribution->parseKeep(raw: ['161:not-an-id']);

	}//end testMalformedKeepIsRefused()

	/**
	 * The relink preserves entries it does not understand.
	 *
	 * A normalising rewrite would drop a non-numeric legacy entry, which is data
	 * loss rather than cleanup.
	 *
	 * @return void
	 */
	public function testRelinkPreservesOrderAndUnknownEntries(): void {
		$this->assertSame(
			[74, 9465, 'legacy-slug', 159],
			$this->attribution->replaceSchemaId(
				schemas: [74, '161', 'legacy-slug', 159],
				oldId: 161,
				newId: 9465
			)
		);

	}//end testRelinkPreservesOrderAndUnknownEntries()
}//end class

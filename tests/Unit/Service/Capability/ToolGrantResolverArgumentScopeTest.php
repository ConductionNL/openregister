<?php

/**
 * Unit tests for ToolGrantResolver's ARGUMENT-SCOPED grant form
 * (hydra-console-agent-leaves).
 *
 * Two halves, and the second matters more than the first. The NEW half checks that
 * `{toolId}?arg=value&other=in:a,b,c` parses, resolves to the underlying catalog id
 * without inventing a second entry, carries its constraints, and refuses a
 * non-conforming argument set. The REGRESSION half checks that every pre-existing
 * grant form — exact id, `{app}.{schema}.*`, `{app}.{schema}.{verb}`, `*:write`, an
 * empty list, the no-tools sentinel — keeps its current meaning exactly, because
 * this is an additive change to a fleet-wide, load-bearing class and "additive" is
 * a claim that has to be tested, not asserted.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Capability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-4-argument-scoped-grants-in-the-resolver
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Capability;

use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for argument-scoped grant parsing, resolution and constraint checking.
 *
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-4-argument-scoped-grants-in-the-resolver
 */
class ToolGrantResolverArgumentScopeTest extends TestCase {

	/**
	 * The pinned flow id used across the command-grant cases.
	 *
	 * @var string
	 */
	private const FLOW_A = '00000000-0000-0000-0000-00000000000a';

	/**
	 * A second, NOT-granted flow id.
	 *
	 * @var string
	 */
	private const FLOW_B = '00000000-0000-0000-0000-00000000000b';

	/**
	 * A catalog holding a full derived schema plus the curated, hint-less
	 * flow-runner the argument-scoped form exists for.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function catalog(): array {
		return [
			['name' => 'hydra_finding_search', 'mcpId' => 'hydra.finding.search'],
			['name' => 'hydra_finding_get', 'mcpId' => 'hydra.finding.get'],
			['name' => 'hydra_finding_create', 'mcpId' => 'hydra.finding.create'],
			['name' => 'hydra_finding_update', 'mcpId' => 'hydra.finding.update'],
			['name' => 'hydra_finding_delete', 'mcpId' => 'hydra.finding.delete'],
			['name' => 'openregister_runFlow', 'mcpId' => 'openregister.runFlow'],
		];

	}//end catalog()

	/**
	 * The command grant the seeded triage agent carries: one pinned flow plus one
	 * closed label set, in ONE `Agent.tools` string.
	 *
	 * @return string
	 */
	private function commandGrant(): string {
		return 'openregister.runFlow?flowId=' . self::FLOW_A . '&label=in:needs-input,retry:queued,rebuild:queued';
	}//end commandGrant()

	/**
	 * An argument-scoped grant resolves to the underlying EXACT catalog id — no
	 * second catalog entry is invented for the narrowed form.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-an-argument-scoped-grant-resolves-to-the-underlying-tool
	 */
	public function testArgumentScopedGrantResolvesToTheUnderlyingToolId(): void {
		$resolver = new ToolGrantResolver();

		$resolved = $resolver->resolve(grants: [$this->commandGrant()], catalog: $this->catalog());

		$this->assertSame(['openregister.runFlow'], $resolved);

	}//end testArgumentScopedGrantResolvesToTheUnderlyingToolId()

	/**
	 * The base ids a whitelist names, with constraints stripped — the shape
	 * `ToolLoop` must hand the facade, which matches on catalog ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-an-argument-scoped-grant-resolves-to-the-underlying-tool
	 */
	public function testBaseToolIdsStripsConstraintsAndLeavesLegacyGrantsVerbatim(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(
			['hydra.finding.*', 'openregister.runFlow', 'hydra.finding.delete'],
			$resolver->baseToolIds(grants: ['hydra.finding.*', $this->commandGrant(), 'hydra.finding.delete'])
		);

	}//end testBaseToolIdsStripsConstraintsAndLeavesLegacyGrantsVerbatim()

	/**
	 * A pin and a closed set are both representable in ONE grant string, and both
	 * are carried through to invocation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
	 */
	public function testAPinAndAClosedSetRideInOneGrantString(): void {
		$resolver = new ToolGrantResolver();

		$constraints = $resolver->argumentConstraints(grants: [$this->commandGrant()]);

		$this->assertArrayHasKey('openregister.runFlow', $constraints);
		$this->assertCount(1, $constraints['openregister.runFlow']);

		$set = $constraints['openregister.runFlow'][0];
		$this->assertSame(ToolGrantResolver::CONSTRAINT_MODE_PIN, $set['flowId']['mode']);
		$this->assertSame([self::FLOW_A], $set['flowId']['values']);
		$this->assertSame(ToolGrantResolver::CONSTRAINT_MODE_SET, $set['label']['mode']);
		$this->assertSame(['needs-input', 'retry:queued', 'rebuild:queued'], $set['label']['values']);

	}//end testAPinAndAClosedSetRideInOneGrantString()

	/**
	 * A conforming invocation is not a violation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-a-pinned-argument-that-matches-is-dispatched
	 */
	public function testConformingArgumentsYieldNoViolation(): void {
		$resolver = new ToolGrantResolver();
		$sets = $resolver->argumentConstraints(grants: [$this->commandGrant()])['openregister.runFlow'];

		$this->assertNull(
			ToolGrantResolver::violationFor(
				constraintSets: $sets,
				arguments: ['flowId' => self::FLOW_A, 'label' => 'retry:queued', 'uuid' => 'anything']
			)
		);

	}//end testConformingArgumentsYieldNoViolation()

	/**
	 * A DIFFERENT pinned value is a violation naming the pinned argument — this is
	 * the property that makes granting a flow runner safe at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-the-agent-may-run-exactly-one-flow
	 */
	public function testADifferentPinnedValueViolates(): void {
		$resolver = new ToolGrantResolver();
		$sets = $resolver->argumentConstraints(grants: [$this->commandGrant()])['openregister.runFlow'];

		$violation = ToolGrantResolver::violationFor(
			constraintSets: $sets,
			arguments: ['flowId' => self::FLOW_B, 'label' => 'retry:queued']
		);

		$this->assertNotNull($violation);
		$this->assertSame('flowId', $violation['argument']);

	}//end testADifferentPinnedValueViolates()

	/**
	 * A value outside the closed set violates — including the injected,
	 * administrative label a finding's text might ask for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-an-injected-instruction-cannot-escape-the-vocabulary
	 */
	public function testAValueOutsideTheClosedSetViolates(): void {
		$resolver = new ToolGrantResolver();
		$sets = $resolver->argumentConstraints(grants: [$this->commandGrant()])['openregister.runFlow'];

		$violation = ToolGrantResolver::violationFor(
			constraintSets: $sets,
			arguments: ['flowId' => self::FLOW_A, 'label' => 'admin']
		);

		$this->assertNotNull($violation);
		$this->assertSame('label', $violation['argument']);
		$this->assertSame(ToolGrantResolver::CONSTRAINT_MODE_SET, $violation['mode']);

	}//end testAValueOutsideTheClosedSetViolates()

	/**
	 * Omitting a constrained argument entirely is a violation, not a bypass: a pin
	 * that can be skipped by leaving the argument out is not a pin.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-a-pinned-argument-that-differs-is-refused-before-dispatch
	 */
	public function testOmittingAConstrainedArgumentViolates(): void {
		$resolver = new ToolGrantResolver();
		$sets = $resolver->argumentConstraints(grants: [$this->commandGrant()])['openregister.runFlow'];

		$violation = ToolGrantResolver::violationFor(constraintSets: $sets, arguments: ['flowId' => self::FLOW_A]);

		$this->assertNotNull($violation);
		$this->assertSame('label', $violation['argument']);

	}//end testOmittingAConstrainedArgumentViolates()

	/**
	 * An argument the grant does NOT mention is left to the tool's own validation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
	 */
	public function testAnUnmentionedArgumentIsNotConstrained(): void {
		$resolver = new ToolGrantResolver();
		$sets = $resolver->argumentConstraints(grants: ['openregister.runFlow?flowId=' . self::FLOW_A]);

		$this->assertNull(
			ToolGrantResolver::violationFor(
				constraintSets: $sets['openregister.runFlow'],
				arguments: ['flowId' => self::FLOW_A, 'register' => 'hydra', 'schema' => 'finding']
			)
		);

	}//end testAnUnmentionedArgumentIsNotConstrained()

	/**
	 * Two argument-scoped grants over the same tool are ALTERNATIVES whose
	 * arguments stay PAIRED — (A,x) and (B,y) are permitted, (A,y) is not. Merging
	 * the constraints per argument instead would have silently widened the grant,
	 * which is the exact failure the form exists to prevent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
	 */
	public function testAlternativeGrantSetsKeepTheirArgumentsPaired(): void {
		$resolver = new ToolGrantResolver();
		$sets = $resolver->argumentConstraints(
			grants: [
				'openregister.runFlow?flowId=' . self::FLOW_A . '&label=x',
				'openregister.runFlow?flowId=' . self::FLOW_B . '&label=y',
			]
		)['openregister.runFlow'];

		$this->assertNull(
			ToolGrantResolver::violationFor(constraintSets: $sets, arguments: ['flowId' => self::FLOW_A, 'label' => 'x'])
		);
		$this->assertNull(
			ToolGrantResolver::violationFor(constraintSets: $sets, arguments: ['flowId' => self::FLOW_B, 'label' => 'y'])
		);
		$this->assertNotNull(
			ToolGrantResolver::violationFor(constraintSets: $sets, arguments: ['flowId' => self::FLOW_A, 'label' => 'y'])
		);

	}//end testAlternativeGrantSetsKeepTheirArgumentsPaired()

	/**
	 * An UNCONSTRAINED exact-id grant beside a constrained one stays legal and means
	 * every target — a sibling grant must not narrow it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
	 */
	public function testABareExactIdGrantIsNotNarrowedByASiblingConstrainedGrant(): void {
		$resolver = new ToolGrantResolver();
		$sets = $resolver->argumentConstraints(
			grants: ['openregister.runFlow', 'openregister.runFlow?flowId=' . self::FLOW_A]
		)['openregister.runFlow'];

		$this->assertNull(
			ToolGrantResolver::violationFor(constraintSets: $sets, arguments: ['flowId' => self::FLOW_B])
		);

	}//end testABareExactIdGrantIsNotNarrowedByASiblingConstrainedGrant()

	/**
	 * A tool no grant constrains is absent from the map, so nothing is imposed on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
	 */
	public function testUnconstrainedGrantsProduceAnEmptyConstraintMap(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(
			[],
			$resolver->argumentConstraints(grants: ['hydra.finding.*', 'hydra.finding.delete', '__none__'])
		);

	}//end testUnconstrainedGrantsProduceAnEmptyConstraintMap()

	/**
	 * Narrowing NEVER downgrades classification: the narrowed flow runner still
	 * classifies write/destructive for default-deny, dry-run and approval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-narrowing-does-not-downgrade-classification
	 */
	public function testNarrowingDoesNotDowngradeClassification(): void {
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive(id: 'openregister.runFlow'));

		$resolver = new ToolGrantResolver();

		// An empty grant list means "all discovered tools, default-denied" — the
		// curated, hint-less flow runner must NOT survive that.
		$this->assertNotContains(
			'openregister.runFlow',
			$resolver->resolve(grants: [], catalog: $this->catalog())
		);

	}//end testNarrowingDoesNotDowngradeClassification()

	/**
	 * A constrained WILDCARD resolves to NOTHING rather than silently granting the
	 * wildcard unconstrained — fail closed, and loudly, via `resolvesToNothing()`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
	 */
	public function testAConstrainedWildcardResolvesToNothing(): void {
		$resolver = new ToolGrantResolver();
		$grants = ['hydra.finding.*?id=7'];

		$resolved = $resolver->resolve(grants: $grants, catalog: $this->catalog());

		$this->assertSame([], $resolved);
		$this->assertTrue($resolver->resolvesToNothing(grants: $grants, resolvedTools: $resolved));

	}//end testAConstrainedWildcardResolvesToNothing()

	/**
	 * A constraint value containing the separator characters survives a
	 * percent-encoded round trip.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
	 */
	public function testPercentEncodedConstraintValuesRoundTrip(): void {
		$resolver = new ToolGrantResolver();
		$sets = $resolver->argumentConstraints(grants: ['app.tool?q=a%2Cb%26c'])['app.tool'];

		$this->assertSame(['a,b&c'], $sets[0]['q']['values']);
		$this->assertNull(ToolGrantResolver::violationFor(constraintSets: $sets, arguments: ['q' => 'a,b&c']));

	}//end testPercentEncodedConstraintValuesRoundTrip()

	/**
	 * A structured (non-scalar) argument cannot satisfy a scalar constraint — fail
	 * closed rather than stringify it into an accidental match.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
	 */
	public function testAStructuredArgumentCannotSatisfyAScalarConstraint(): void {
		$resolver = new ToolGrantResolver();
		$sets = $resolver->argumentConstraints(grants: ['app.tool?q=x'])['app.tool'];

		$this->assertNotNull(
			ToolGrantResolver::violationFor(constraintSets: $sets, arguments: ['q' => ['x']])
		);

	}//end testAStructuredArgumentCannotSatisfyAScalarConstraint()

	// -----------------------------------------------------------------------
	// REGRESSION: every pre-existing grant form keeps its current meaning.
	// -----------------------------------------------------------------------

	/**
	 * A schema wildcard still grants READ verbs only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-a-schema-wildcard-grants-read-verbs-only
	 */
	public function testRegressionSchemaWildcardGrantsReadVerbsOnly(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(
			['hydra.finding.search', 'hydra.finding.get'],
			$resolver->resolve(grants: ['hydra.finding.*'], catalog: $this->catalog())
		);

	}//end testRegressionSchemaWildcardGrantsReadVerbsOnly()

	/**
	 * The `:write` modifier still adds the write verbs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-a-write-tool-is-granted-only-when-named-explicitly
	 */
	public function testRegressionWriteModifierStillAddsWriteVerbs(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(
			[
				'hydra.finding.search',
				'hydra.finding.get',
				'hydra.finding.create',
				'hydra.finding.update',
				'hydra.finding.delete',
			],
			$resolver->resolve(grants: ['hydra.finding.*:write'], catalog: $this->catalog())
		);

	}//end testRegressionWriteModifierStillAddsWriteVerbs()

	/**
	 * An explicitly-named verb subset still passes through verbatim, beside a
	 * read-only wildcard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-a-write-tool-is-granted-only-when-named-explicitly
	 */
	public function testRegressionExplicitVerbSubsetPassesThroughVerbatim(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(
			['hydra.finding.search', 'hydra.finding.get', 'hydra.finding.delete'],
			$resolver->resolve(grants: ['hydra.finding.*', 'hydra.finding.delete'], catalog: $this->catalog())
		);

	}//end testRegressionExplicitVerbSubsetPassesThroughVerbatim()

	/**
	 * An empty grant list yields nothing, and argument scoping does not change that.
	 *
	 * Was `testRegressionEmptyGrantListStillMeansAllDefaultDenied`. Empty grants
	 * meant "all, default-denied" until 2026-08-16; they now mean NO TOOLS, and
	 * the regression worth guarding is that the argument-constraint parser does
	 * not reintroduce a grant where none was made.
	 *
	 * @return void
	 */
	public function testEmptyGrantListYieldsNothingEvenWithArgumentScoping(): void {
		$resolved = (new ToolGrantResolver())->resolve(grants: [], catalog: $this->catalog());

		$this->assertSame([], $resolved);

	}//end testEmptyGrantListYieldsNothingEvenWithArgumentScoping()

	/**
	 * The no-tools sentinel is still recognised, still resolves to nothing, and is
	 * still NOT reported as a misconfiguration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-a-deliberately-tool-less-agent-is-not-reported
	 */
	public function testRegressionNoToolsSentinelIsStillDeliberate(): void {
		$resolver = new ToolGrantResolver();
		$grants = [ToolGrantResolver::NO_TOOLS_SENTINEL];

		$this->assertTrue($resolver->isExplicitNoTools(grants: $grants));
		$this->assertFalse($resolver->resolvesToNothing(grants: $grants, resolvedTools: []));
		$this->assertFalse($resolver->hasWildcardGrant(grants: $grants));

	}//end testRegressionNoToolsSentinelIsStillDeliberate()

	/**
	 * Grants naming a schema the catalog does not expose still resolve to nothing
	 * and are still REPORTED as a misconfiguration rather than run as chat-only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-grants-that-resolve-to-nothing-are-reported
	 */
	public function testRegressionMisspelledSchemaGrantIsReported(): void {
		$resolver = new ToolGrantResolver();
		$grants = ['hydra.findng.*'];

		$resolved = $resolver->resolve(grants: $grants, catalog: $this->catalog());

		$this->assertSame([], $resolved);
		$this->assertTrue($resolver->resolvesToNothing(grants: $grants, resolvedTools: $resolved));

	}//end testRegressionMisspelledSchemaGrantIsReported()

	/**
	 * `hasWildcardGrant()` still answers the same question for every legacy form,
	 * and now answers it for the base of a constrained grant too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
	 */
	public function testRegressionWildcardDetectionUnchanged(): void {
		$resolver = new ToolGrantResolver();

		$this->assertTrue($resolver->hasWildcardGrant(grants: ['hydra.finding.*']));
		$this->assertTrue($resolver->hasWildcardGrant(grants: ['hydra.finding.*:write']));
		$this->assertFalse($resolver->hasWildcardGrant(grants: ['hydra.finding.get']));
		$this->assertFalse($resolver->hasWildcardGrant(grants: [$this->commandGrant()]));

	}//end testRegressionWildcardDetectionUnchanged()

	/**
	 * A trailing `?` with nothing after it declares no constraint — identical to
	 * the bare exact-id grant, never a grant nobody can satisfy.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
	 */
	public function testATrailingQuestionMarkDeclaresNoConstraint(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(['openregister.runFlow'], $resolver->baseToolIds(grants: ['openregister.runFlow?']));
		$this->assertSame([], $resolver->argumentConstraints(grants: ['openregister.runFlow?']));

	}//end testATrailingQuestionMarkDeclaresNoConstraint()
}//end class

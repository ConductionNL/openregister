<?php

/**
 * Characterisation tests for ToolGrantResolver's hint classification,
 * descriptor indexing and argument-constraint decisions.
 *
 * Pins allowed AND refused outcomes of every branch of the hint ladder
 * (scope, destructiveHint, readOnlyHint, fallback), of the descriptor index
 * (non-array entries, empty ids, both id forms, first occurrence wins), and of
 * the argument-constraint check (missing argument, structured value, value
 * outside the set, first alternative's violation reported).
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
 * @spec openspec/specs/agent-tool-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Capability;

use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the resolver's classification and constraint decisions.
 */
class ToolGrantResolverDecisionGoldenTest extends TestCase {

	/**
	 * Each rung of the hint ladder decides, in order, and the first key set wins.
	 *
	 * @return void
	 */
	public function testHintLadderDecidesInOrder(): void {
		$cases = [
			'scope write verb'                   => [['scope' => 'update', 'readOnlyHint' => true], true],
			'scope read verb'                    => [['scope' => 'get', 'destructiveHint' => true], false],
			'scope not a string falls through'   => [['scope' => 5, 'destructiveHint' => true], true],
			'scope null falls through'           => [['scope' => null, 'readOnlyHint' => true], false],
			'destructive true'                   => [['destructiveHint' => true, 'readOnlyHint' => true], true],
			'destructive false'                  => [['destructiveHint' => false, 'readOnlyHint' => false], false],
			'destructive not bool falls through' => [['destructiveHint' => 'yes', 'readOnlyHint' => true], false],
			'destructive null falls through'     => [['destructiveHint' => null, 'readOnlyHint' => false], true],
			'read only true'                     => [['readOnlyHint' => true], false],
			'read only false'                    => [['readOnlyHint' => false], true],
			'read only not bool falls through'   => [['readOnlyHint' => 1], false],
			'no hints falls through'             => [[], false],
		];

		foreach ($cases as $label => [$descriptor, $expected]) {
			$this->assertSame(
				$expected,
				ToolGrantResolver::isWriteOrDestructive('app.thing.get', $descriptor),
				$label
			);
		}

		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive('app.thing.delete', []), 'fallback on a write verb');
		$this->assertTrue(ToolGrantResolver::isWriteOrDestructive('app.thingTool', ['other' => 1]), 'hint-less two-segment id fails closed');
		$this->assertFalse(ToolGrantResolver::isWriteOrDestructive('app.thing.get'), 'no descriptor uses the verb');
	}//end testHintLadderDecidesInOrder()

	/**
	 * The catalog is indexed under both id forms, skipping unusable entries and ids.
	 *
	 * @return void
	 */
	public function testCatalogIsIndexedUnderBothIdForms(): void {
		$resolver = new ToolGrantResolver();
		$catalog  = [
			'not-a-descriptor',
			['mcpId' => '', 'name' => 'app.lead.get'],
			['mcpId' => 'app.lead.search', 'name' => 'app_lead_search'],
			['mcpId' => 7, 'name' => null],
			['name' => 'app.lead.list'],
		];

		$this->assertSame(
			['app.lead.search', ''],
			$resolver->resolve(grants: ['app.lead.*'], catalog: $catalog),
			'read verbs only, each reported under its descriptor\'s canonical mcpId (an empty one included)'
		);
		$this->assertSame(
			['app.lead.search'],
			$resolver->resolve(grants: ['app_lead_search'], catalog: $catalog),
			'the underscored name resolves to the dotted id'
		);
		$this->assertSame(
			[],
			$resolver->resolve(grants: ['other.lead.*'], catalog: $catalog),
			'a wildcard over ids the catalog lacks grants nothing'
		);
	}//end testCatalogIsIndexedUnderBothIdForms()

	/**
	 * The first descriptor carrying an id wins its classification.
	 *
	 * @return void
	 */
	public function testFirstDescriptorForAnIdWins(): void {
		$resolver = new ToolGrantResolver();

		$dottedFirst = $resolver->resolve(
			grants: ['app.lead.*'],
			catalog: [
				['mcpId' => 'app.lead.get', 'name' => 'alias'],
				['mcpId' => 'other', 'name' => 'app.lead.get'],
			]
		);
		$aliasFirst = $resolver->resolve(
			grants: ['app.lead.*'],
			catalog: [
				['mcpId' => 'other', 'name' => 'app.lead.get'],
				['mcpId' => 'app.lead.get', 'name' => 'alias'],
			]
		);

		$this->assertSame(['app.lead.get'], $dottedFirst);
		$this->assertSame(['other'], $aliasFirst);
	}//end testFirstDescriptorForAnIdWins()

	/**
	 * Every way an argument can fail its constraint is refused; conforming calls pass.
	 *
	 * @return void
	 */
	public function testArgumentConstraintOutcomes(): void {
		$set = [
			'mode' => ['mode' => 'in', 'values' => ['dry', 'live']],
			'flag' => ['mode' => 'eq', 'values' => ['true']],
		];

		$this->assertNull(ToolGrantResolver::violationFor([], ['mode' => 'x']), 'no sets imposes nothing');
		$this->assertNull(ToolGrantResolver::violationFor([$set], ['mode' => 'dry', 'flag' => true, 'other' => [1]]));
		$this->assertSame(
			['argument' => 'mode', 'mode' => 'in', 'values' => ['dry', 'live']],
			ToolGrantResolver::violationFor([$set], ['flag' => true])
		);
		$this->assertSame(
			['argument' => 'mode', 'mode' => 'in', 'values' => ['dry', 'live']],
			ToolGrantResolver::violationFor([$set], ['mode' => ['dry'], 'flag' => true])
		);
		$this->assertSame(
			['argument' => 'flag', 'mode' => 'eq', 'values' => ['true']],
			ToolGrantResolver::violationFor([$set], ['mode' => 'live', 'flag' => false])
		);
		$this->assertSame(
			['argument' => 'flag', 'mode' => 'eq', 'values' => ['true']],
			ToolGrantResolver::violationFor([$set], ['mode' => 'live', 'flag' => null])
		);
		$this->assertSame(
			['argument' => 'tag', 'mode' => 'pin', 'values' => ['Array']],
			ToolGrantResolver::violationFor([['tag' => ['mode' => 'pin', 'values' => ['Array']]]], ['tag' => ['x']]),
			'a structured value is refused before it could be stringified into a match'
		);
	}//end testArgumentConstraintOutcomes()

	/**
	 * Of several alternatives, any satisfied one permits; otherwise the first one's violation is reported.
	 *
	 * @return void
	 */
	public function testAlternativesReportTheFirstViolation(): void {
		$first  = ['a' => ['mode' => 'eq', 'values' => ['1']]];
		$second = ['b' => ['mode' => 'eq', 'values' => ['2']]];

		$this->assertNull(ToolGrantResolver::violationFor([$first, $second], ['b' => 2]));
		$this->assertNull(ToolGrantResolver::violationFor([$first, $second], ['a' => '1']));
		$this->assertSame(
			['argument' => 'a', 'mode' => 'eq', 'values' => ['1']],
			ToolGrantResolver::violationFor([$first, $second], ['a' => 3, 'b' => 3])
		);
	}//end testAlternativesReportTheFirstViolation()

	/**
	 * Only ids some grant constrains carry constraint sets, bare siblings included as empty sets.
	 *
	 * @return void
	 */
	public function testArgumentConstraintsKeepOnlyConstrainedIds(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(
			[
				'app.run' => [[], ['flowId' => ['mode' => 'pin', 'values' => ['A']]]],
			],
			$resolver->argumentConstraints(
				grants: ['app.run', 'app.run?flowId=A', 'app.other', 'app.lead.*?x=1', 'app.lead.*']
			)
		);
	}//end testArgumentConstraintsKeepOnlyConstrainedIds()
}//end class

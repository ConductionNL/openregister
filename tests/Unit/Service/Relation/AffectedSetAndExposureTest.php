<?php

/**
 * Walking the relations, pruning a branch out loud, and what a link hands over.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Relation\AffectedSet;
use OCA\OpenRegister\Service\Relation\AffectedSetQuery;
use OCA\OpenRegister\Service\Relation\LinkExposure;
use PHPUnit\Framework\TestCase;

/**
 * Verifies rows 2.48 and 13.36.
 */
class AffectedSetAndExposureTest extends TestCase {

	/**
	 * A graph: root -> a (betreft), a -> b (betreft), root -> p (partij),
	 * and root -> c through a type nobody asked about.
	 *
	 * @return array<string, mixed> The graph.
	 */
	private function graph(): array {
		return [
			'root' => 'root',
			'depth' => 2,
			'nodes' => [
				['uuid' => 'root', 'schema' => 'zaak', 'distance' => 0, 'resolved' => true],
				['uuid' => 'a', 'schema' => 'zaak', 'distance' => 1, 'resolved' => true],
				['uuid' => 'b', 'schema' => 'zaak', 'distance' => 2, 'resolved' => true],
				['uuid' => 'c', 'schema' => 'document', 'distance' => 1, 'resolved' => true],
				['uuid' => 'p', 'schema' => 'partij', 'distance' => 1, 'resolved' => true],
			],
			'edges' => [
				['from' => 'root', 'to' => 'a', 'type' => 'betreft'],
				['from' => 'a', 'to' => 'b', 'type' => 'betreft'],
				['from' => 'root', 'to' => 'c', 'type' => 'bijlage'],
				['from' => 'root', 'to' => 'p', 'type' => 'partij'],
			],
			'truncated' => false,
			'truncatedBy' => null,
		];
	}//end graph()

	/**
	 * The subject.
	 *
	 * @return AffectedSet The service.
	 */
	private function affected(): AffectedSet {
		return new AffectedSet();
	}//end affected()

	/**
	 * The walk answers who else is affected, with the path that reached them.
	 *
	 * @return void
	 */
	public function testTheWalkNamesWhoIsAffectedAndHowTheyWereReached(): void {
		$result = $this->affected()->derive(graph: $this->graph(), query: (new AffectedSetQuery())->withPartySchemas(schemas: ['partij']));

		$uuids = array_column($result['objects'], 'uuid');
		$this->assertSame(['a', 'b', 'c'], $uuids, 'the root itself is not in its own affected set');
		$this->assertSame(['p'], array_column($result['parties'], 'uuid'), 'a party is projected beside the objects');

		$b = $result['objects'][1];
		$this->assertSame(
			[
				['from' => 'root', 'to' => 'a', 'type' => 'betreft'],
				['from' => 'a', 'to' => 'b', 'type' => 'betreft'],
			],
			$b['path'],
			'each reached node carries the path that reached it'
		);
	}//end testTheWalkNamesWhoIsAffectedAndHowTheyWereReached()

	/**
	 * Filtering to named types drops the rest.
	 *
	 * @return void
	 */
	public function testFilteringToNamedTypesDropsTheRest(): void {
		$result = $this->affected()->derive(graph: $this->graph(), query: (new AffectedSetQuery(types: ['betreft']))->withPartySchemas(schemas: ['partij']));

		$this->assertSame(['a', 'b'], array_column($result['objects'], 'uuid'));
		$this->assertSame([], $result['parties'], 'the party edge was not one of the named types');
	}//end testFilteringToNamedTypesDropsTheRest()

	/**
	 * 🔴 An empty type list is refused: it reads as both "none" and "all".
	 *
	 * @return void
	 */
	public function testAnEmptyTypeListIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->affected()->derive(graph: $this->graph(), query: new AffectedSetQuery(types: []));
	}//end testAnEmptyTypeListIsRefused()

	/**
	 * The control: a null type list filters nothing.
	 *
	 * @return void
	 */
	public function testANullTypeListFiltersNothing(): void {
		$result = $this->affected()->derive(graph: $this->graph(), query: (new AffectedSetQuery(types: null))->withPartySchemas(schemas: ['partij']));

		$this->assertCount(3, $result['objects'], 'the control: absent means "not filtered by type"');
	}//end testANullTypeListFiltersNothing()

	/**
	 * 🔴 A prune removes what it cuts, AND says what it cut.
	 *
	 * @return void
	 */
	public function testAPruneIsReportedAsWellAsApplied(): void {
		$result = $this->affected()->derive(graph: $this->graph(), query: (new AffectedSetQuery(prune: ['bijlage']))->withPartySchemas(schemas: ['partij']));

		$this->assertSame(['a', 'b'], array_column($result['objects'], 'uuid'), 'the cut branch is gone');
		$this->assertSame(
			[['type' => 'bijlage', 'at' => 'root', 'to' => 'c']],
			$result['pruned'],
			'a notification list that silently omits a branch hides exactly who was not told'
		);
	}//end testAPruneIsReportedAsWellAsApplied()

	/**
	 * 🔴 Pruning recomputes reachability: a node reachable ONLY through the cut
	 * disappears with it.
	 *
	 * Dropping the edge and keeping the node would be a prune that reports a
	 * cut and changes no result.
	 *
	 * @return void
	 */
	public function testPruningRemovesWhatWasOnlyReachableThroughTheCut(): void {
		$result = $this->affected()->derive(graph: $this->graph(), query: (new AffectedSetQuery(prune: ['betreft']))->withPartySchemas(schemas: ['partij']));

		$uuids = array_column($result['objects'], 'uuid');
		$this->assertNotContains('a', $uuids, 'the node behind the cut edge is gone');
		$this->assertNotContains('b', $uuids, 'and so is the node that was only reachable through it');
		$this->assertContains('c', $uuids, 'while a node reached another way stays');
	}//end testPruningRemovesWhatWasOnlyReachableThroughTheCut()

	/**
	 * A node still reachable another way survives a prune of one route to it.
	 *
	 * @return void
	 */
	public function testANodeReachableAnotherWaySurvivesAPrune(): void {
		$graph = $this->graph();
		$graph['edges'][] = ['from' => 'c', 'to' => 'b', 'type' => 'bijlage'];

		$result = $this->affected()->derive(graph: $graph, query: new AffectedSetQuery(prune: ['betreft']));

		$this->assertContains('b', array_column($result['objects'], 'uuid'), 'one route cut is not every route cut');
	}//end testANodeReachableAnotherWaySurvivesAPrune()

	/**
	 * Truncation is passed through, never recomputed.
	 *
	 * @return void
	 */
	public function testTruncationIsPassedThrough(): void {
		$graph = $this->graph();
		$graph['truncated'] = true;
		$graph['truncatedBy'] = 'cap';

		$result = $this->affected()->derive(graph: $graph, query: new AffectedSetQuery());

		$this->assertTrue($result['truncated'], 'a complete-looking answer to an incomplete question is the failure here');
		$this->assertSame('cap', $result['truncatedBy']);
	}//end testTruncationIsPassedThrough()

	/**
	 * 🔴 A record the caller may not read is absent, and so is what lies only behind it.
	 *
	 * The walk loads nodes under the caller's RBAC, so an unreadable record
	 * arrives unresolved. Neither it nor its uuid in a path may reach the answer.
	 *
	 * @return void
	 */
	public function testARecordTheCallerMayNotReadIsAbsentAndNotWalkedThrough(): void {
		$graph = $this->graph();
		$graph['nodes'][1] = ['uuid' => 'a', 'title' => null, 'schema' => null, 'distance' => 1, 'resolved' => false, 'external' => false];

		$result = $this->affected()->derive(graph: $graph, query: (new AffectedSetQuery())->withPartySchemas(schemas: ['partij']));

		$uuids = array_column($result['objects'], 'uuid');
		$this->assertNotContains('a', $uuids, 'the unreadable record is not in the answer');
		$this->assertNotContains('b', $uuids, 'nor what was only reachable through it');
		$this->assertSame(['c'], $uuids, 'the control: a readable sibling stays');
		$this->assertSame(['p'], array_column($result['parties'], 'uuid'));
	}//end testARecordTheCallerMayNotReadIsAbsentAndNotWalkedThrough()

	/**
	 * A node with no readable flag fails closed, while an external address stays.
	 *
	 * @return void
	 */
	public function testOnlyResolvedAndExternalNodesAreWalked(): void {
		$graph = $this->graph();
		unset($graph['nodes'][3]['resolved']);
		$graph['nodes'][] = ['uuid' => 'https://example.org/x', 'distance' => 1, 'resolved' => false, 'external' => true];
		$graph['edges'][] = ['from' => 'root', 'to' => 'https://example.org/x', 'type' => 'verwijst'];
		$graph['edges'][] = ['from' => 'root', 'to' => 'nowhere', 'type' => 'verwijst'];

		$result = $this->affected()->derive(graph: $graph, query: new AffectedSetQuery());

		$this->assertSame(['a', 'b', 'p', 'https://example.org/x'], array_column($result['objects'], 'uuid'));
	}//end testOnlyResolvedAndExternalNodesAreWalked()

	/**
	 * A pruned edge into an unreadable record is not reported, so the report does not name it.
	 *
	 * @return void
	 */
	public function testAPruneReportNeverNamesAnUnreadableRecord(): void {
		$graph = $this->graph();
		$graph['nodes'][3]['resolved'] = false;

		$result = $this->affected()->derive(graph: $graph, query: new AffectedSetQuery(prune: ['bijlage']));

		$this->assertSame([], $result['pruned']);
	}//end testAPruneReportNeverNamesAnUnreadableRecord()

	/**
	 * A relation type with an `exposes` set.
	 *
	 * @return array<string, mixed> The type.
	 */
	private function exposingType(): array {
		return ['key' => 'gerelateerd', LinkExposure::KEY => ['zaaknummer', 'status']];
	}//end exposingType()

	/**
	 * 🔴 A link shows the two fields it declares, and withholds the rest.
	 *
	 * @return void
	 */
	public function testALinkShowsWhatItDeclaresAndWithholdsTheRest(): void {
		$far = ['zaaknummer' => 'Z-1', 'status' => 'open', 'toelichting' => 'gevoelig', 'bsn' => '123'];

		$exposure = new LinkExposure();
		$projection = $exposure->project(
			farObject: $far,
			visible: $exposure->visibleProperties(relationType: $this->exposingType(), readable: ['zaaknummer', 'status', 'toelichting', 'bsn'])
		);

		$this->assertSame('Z-1', $projection['zaaknummer']);
		$this->assertSame('open', $projection['status']);
		$this->assertSame(LinkExposure::WITHHELD, $projection['toelichting']);
		$this->assertArrayHasKey(
			'bsn',
			$projection,
			'withheld, not absent: empty reads as "there is none" and withheld reads as "you may not see it"'
		);
		$this->assertSame(LinkExposure::WITHHELD, $projection['bsn']);
	}//end testALinkShowsWhatItDeclaresAndWithholdsTheRest()

	/**
	 * 🔴 Exposure NARROWS and never widens: a field the reader's own rules
	 * withhold stays withheld, whatever the link declares.
	 *
	 * A link that handed over whatever its schema author listed would turn
	 * every relation into an access decision made by whoever wrote the schema.
	 *
	 * @return void
	 */
	public function testExposureNeverWidensBeyondTheReadersOwnRules(): void {
		$exposure = new LinkExposure();
		$type = ['key' => 'gerelateerd', LinkExposure::KEY => ['zaaknummer', 'bsn']];

		$visible = $exposure->visibleProperties(relationType: $type, readable: ['zaaknummer', 'status']);

		$this->assertSame(['zaaknummer'], $visible, 'the link may not hand over a field this reader may not see');

		$projection = $exposure->project(farObject: ['zaaknummer' => 'Z-1', 'bsn' => '123'], visible: $visible);
		$this->assertSame(LinkExposure::WITHHELD, $projection['bsn']);
	}//end testExposureNeverWidensBeyondTheReadersOwnRules()

	/**
	 * 🔴 A type declaring NO exposure narrows nothing — the behaviour every
	 * relation type has today.
	 *
	 * @return void
	 */
	public function testATypeDeclaringNoExposureNarrowsNothing(): void {
		$exposure = new LinkExposure();

		$this->assertFalse($exposure->declaresExposure(relationType: ['key' => 'gerelateerd']));
		$this->assertSame(
			['zaaknummer', 'status'],
			$exposure->visibleProperties(relationType: ['key' => 'gerelateerd'], readable: ['zaaknummer', 'status']),
			'every schema saved before this must keep behaving as it did'
		);
	}//end testATypeDeclaringNoExposureNarrowsNothing()

	/**
	 * 🔴 But a PRESENT-BUT-EMPTY exposure exposes nothing, which is a different
	 * statement and a legitimate one.
	 *
	 * @return void
	 */
	public function testAPresentButEmptyExposureExposesNothing(): void {
		$exposure = new LinkExposure();
		$type = ['key' => 'gerelateerd', LinkExposure::KEY => []];

		$this->assertTrue($exposure->declaresExposure(relationType: $type));
		$this->assertSame(
			[],
			$exposure->visibleProperties(relationType: $type, readable: ['zaaknummer', 'status']),
			'"related, and you may see none of it" is a thing a link is allowed to say'
		);
	}//end testAPresentButEmptyExposureExposesNothing()

	/**
	 * The exposed fields come back in the order the schema author listed them.
	 *
	 * @return void
	 */
	public function testTheExposedFieldsKeepTheDeclaredOrder(): void {
		$visible = (new LinkExposure())->visibleProperties(
			relationType: ['key' => 'g', LinkExposure::KEY => ['status', 'zaaknummer']],
			readable: ['zaaknummer', 'status']
		);

		$this->assertSame(['status', 'zaaknummer'], $visible, 'not the order the permission layer happened to answer in');
	}//end testTheExposedFieldsKeepTheDeclaredOrder()

	/**
	 * An exposed property the far schema does not declare is refused at save.
	 *
	 * @return void
	 */
	public function testAnExposedPropertyTheFarSchemaLacksIsRefusedAtSave(): void {
		$refusal = (new LinkExposure())->refusalFor(
			relationType: ['key' => 'g', LinkExposure::KEY => ['zaaknummer', 'zaknummer']],
			farProperties: ['zaaknummer', 'status'],
			typeName: 'gerelateerd'
		);

		$this->assertNotNull($refusal, 'a typo would be silently absent from every projection afterwards');
		$this->assertStringContainsString('zaknummer', (string)$refusal);
	}//end testAnExposedPropertyTheFarSchemaLacksIsRefusedAtSave()

	/**
	 * The control: a well-formed exposure saves.
	 *
	 * @return void
	 */
	public function testAWellFormedExposureIsAccepted(): void {
		$this->assertNull(
			(new LinkExposure())->refusalFor(
				relationType: $this->exposingType(),
				farProperties: ['zaaknummer', 'status', 'toelichting'],
				typeName: 'gerelateerd'
			),
			'the control: an exposure naming real properties is accepted'
		);
	}//end testAWellFormedExposureIsAccepted()
}//end class

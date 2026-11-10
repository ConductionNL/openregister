<?php

/**
 * Unit tests for ToolGrantSet — grants as a structure, not a string.
 *
 * 🔴 The property these tests exist for: a tool id is CARRIED, never rebuilt
 * from its coordinates. `hermiq.listFiles` sits at (hermiq, file, list), and
 * rebuilding an id from those gives `hermiq.file.list`, which is not a tool.
 * Every other guarantee here is downstream of that one.
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
 * @spec openspec/specs/structured-tool-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Capability;

use OCA\OpenRegister\Service\Capability\ToolGrantSet;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the structured grant set.
 */
class ToolGrantSetTest extends TestCase {

	/**
	 * A legacy `string[]` still loads and still means the same thing.
	 *
	 * @return void
	 */
	public function testLegacyStringListLoadsUnchanged(): void {
		$set = ToolGrantSet::fromStored(['pipelinq.lead.search', 'pipelinq.lead.get']);

		$this->assertFalse($set->isEmpty());
		$this->assertSame(
			['pipelinq.lead.search', 'pipelinq.lead.get'],
			$set->toGrantStrings(),
			'a stored legacy list must resolve to exactly the grants it always did'
		);
	}//end testLegacyStringListLoadsUnchanged()

	/**
	 * 🔴 THE ONE THAT MATTERS. A hand-written id survives the round trip.
	 *
	 * `hermiq.listFiles` is a real tool; `hermiq.file.list` is not. Storing only
	 * the coordinates and rebuilding the id would dispatch to nothing.
	 *
	 * @return void
	 */
	public function testAHandWrittenIdIsCarriedNotRebuilt(): void {
		$set = ToolGrantSet::fromStored(['hermiq.listFiles']);

		$this->assertSame(
			['hermiq.listFiles'],
			$set->toGrantStrings(),
			'the id must be carried verbatim — rebuilding it from coordinates yields hermiq.file.list, which does not exist'
		);

		$stored = $set->toStored();
		$this->assertSame(
			['hermiq.listFiles'],
			$stored['hermiq']['listFiles']['listFiles'] ?? null,
			'a two-part id is its own subject and action: it names a capability, not a verb applied to a noun'
		);
	}//end testAHandWrittenIdIsCarriedNotRebuilt()

	/**
	 * The structured form loads and needs no parsing.
	 *
	 * @return void
	 */
	public function testStructuredFormLoadsDirectly(): void {
		$set = ToolGrantSet::fromStored([
			'pipelinq' => ['lead' => ['search' => 'pipelinq.lead.search']],
			'hermiq' => ['file' => ['list' => 'hermiq.listFiles']],
		]);

		$this->assertTrue($set->has('pipelinq', 'lead', 'search'));
		$this->assertTrue($set->has('hermiq', 'file', 'list'));
		$this->assertFalse($set->has('pipelinq', 'lead', 'delete'));

		$resolved = $set->toGrantStrings();
		sort($resolved);
		$this->assertSame(['hermiq.listFiles', 'pipelinq.lead.search'], $resolved);
	}//end testStructuredFormLoadsDirectly()

	/**
	 * The two shapes are told apart by their keys, not a version field.
	 *
	 * @return void
	 */
	public function testTheTwoShapesAreDistinguishedByTheirKeys(): void {
		$legacy = ToolGrantSet::fromStored(['pipelinq.lead.search']);
		$structured = ToolGrantSet::fromStored(['pipelinq' => ['lead' => ['search' => 'pipelinq.lead.search']]]);

		$this->assertSame(
			$legacy->toStored(),
			$structured->toStored(),
			'the same grant written either way must load to the same structure'
		);
	}//end testTheTwoShapesAreDistinguishedByTheirKeys()

	/**
	 * A wildcard grant keeps its meaning through the round trip.
	 *
	 * @return void
	 */
	public function testWildcardsSurviveTheRoundTrip(): void {
		foreach (['pipelinq.lead.*', 'pipelinq.lead.*:write'] as $grant) {
			$set = ToolGrantSet::fromStored([$grant]);
			$this->assertSame([$grant], $set->toGrantStrings(), "$grant must resolve unchanged");
		}
	}//end testWildcardsSurviveTheRoundTrip()

	/**
	 * An argument-scoped grant becomes DATA, not a second grammar in a string.
	 *
	 * @return void
	 */
	public function testArgumentConstraintsBecomeStructure(): void {
		$set = ToolGrantSet::fromStored(['openregister.runFlow?flowId=abc&mode=in:dry,live']);

		$stored = $set->toStored();
		$entries = $stored['openregister']['runFlow']['runFlow'] ?? null;

		$this->assertIsArray($entries, 'an action holds a list of entries');
		$this->assertCount(1, $entries);

		$entry = $entries[0];
		$this->assertIsArray($entry, 'a constrained grant is stored as an object, not a string with a query on it');
		$this->assertSame('openregister.runFlow', $entry['id']);
		$this->assertSame('abc', $entry['args']['flowId']);
		$this->assertSame(['dry', 'live'], $entry['args']['mode'], 'a closed value set stays a list');

		// And converts back to exactly the grammar the resolver enforces.
		$this->assertSame(
			['openregister.runFlow?flowId=abc&mode=in:dry,live'],
			$set->toGrantStrings()
		);
	}//end testArgumentConstraintsBecomeStructure()

	/**
	 * 🔴 Two grants for ONE tool with different constraints both survive.
	 *
	 * `runFlow?flowId=A` and `runFlow?flowId=B` share an id and are two distinct
	 * capabilities. The first cut keyed an action to a single entry, so the
	 * second assignment silently revoked the first — a narrowing that removes a
	 * grant nobody asked to remove. A bare grant alongside a constrained one is
	 * also legal (it means "every target") and must not displace it either.
	 *
	 * @return void
	 */
	public function testTwoConstrainedGrantsForOneToolBothSurvive(): void {
		$set = ToolGrantSet::fromStored([
			'openregister.runFlow?flowId=A',
			'openregister.runFlow?flowId=B',
			'openregister.runFlow',
		]);

		$this->assertSame(
			[
				'openregister.runFlow?flowId=A',
				'openregister.runFlow?flowId=B',
				'openregister.runFlow',
			],
			$set->toGrantStrings(),
			'one tool granted three ways is three grants, not one'
		);

		$this->assertCount(3, $set->toStored()['openregister']['runFlow']['runFlow']);
	}//end testTwoConstrainedGrantsForOneToolBothSurvive()

	/**
	 * Adding and removing grants works at coordinates.
	 *
	 * @return void
	 */
	public function testGrantsCanBeAddedAndRemovedByCoordinate(): void {
		$set = ToolGrantSet::fromStored([])
			->with('hermiq', 'file', 'list', 'hermiq.listFiles')
			->with('hermiq', 'file', 'read', 'hermiq.readFile');

		$this->assertTrue($set->has('hermiq', 'file', 'list'));
		$this->assertCount(2, $set->toolIds());

		$set = $set->without('hermiq', 'file', 'list');
		$this->assertFalse($set->has('hermiq', 'file', 'list'));
		$this->assertTrue($set->has('hermiq', 'file', 'read'));

		// ⚠️ Removing the LAST grant must leave the set empty, not a map of empty
		// maps — `isEmpty()` drives default-deny, and "configured with nothing" and
		// "unconfigured" must not be told apart by leftover scaffolding.
		$set = $set->without('hermiq', 'file', 'read');
		$this->assertTrue($set->isEmpty(), 'removing the last grant must leave no empty app or subject behind');
		$this->assertSame([], $set->toStored());
	}//end testGrantsCanBeAddedAndRemovedByCoordinate()

	/**
	 * Malformed stored data is dropped, never guessed at.
	 *
	 * @return void
	 */
	public function testMalformedEntriesAreDropped(): void {
		$set = ToolGrantSet::fromStored([
			'pipelinq' => [
				'lead' => [
					'search' => 'pipelinq.lead.search',
					'get' => ['args' => ['x' => 1]],
					'bad' => 42,
					'' => 'pipelinq.lead.empty',
				],
				'' => ['search' => 'nope'],
				'broken' => 'not-an-array',
			],
			'' => ['lead' => ['search' => 'nope']],
			7 => ['lead' => ['search' => 'nope']],
		]);

		$this->assertSame(
			['pipelinq.lead.search'],
			$set->toGrantStrings(),
			'an entry with no id is unusable — inventing one from its coordinates could resolve to a tool nobody granted'
		);
	}//end testMalformedEntriesAreDropped()

	/**
	 * Nothing stored means nothing granted, in every empty spelling.
	 *
	 * @return void
	 */
	public function testEmptyIsEmpty(): void {
		foreach ([[], null, '', 0, false] as $stored) {
			$this->assertTrue(
				ToolGrantSet::fromStored($stored)->isEmpty(),
				'an unconfigured agent must read as unconfigured, whatever the empty value looks like'
			);
		}
	}//end testEmptyIsEmpty()

	/**
	 * A duplicate grant resolves once.
	 *
	 * @return void
	 */
	public function testDuplicateGrantsCollapse(): void {
		$set = ToolGrantSet::fromStored(['hermiq.listFiles', 'hermiq.listFiles']);

		$this->assertSame(['hermiq.listFiles'], $set->toGrantStrings());
	}//end testDuplicateGrantsCollapse()

	/**
	 * Two constrained grants for ONE tool are both kept.
	 *
	 * 🔴 The entry is APPENDED, not assigned. `runFlow?flowId=A` and
	 * `runFlow?flowId=B` are two distinct capabilities sharing an id, and
	 * assigning at those coordinates keeps only the last — silently revoking the
	 * other. Nothing errors; the agent simply stops being able to run flow A.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#scenario-two-constrained-grants-for-one-tool-both-survive
	 */
	public function testTwoConstraintsOnOneToolBothSurvive(): void {
		$set = ToolGrantSet::fromGrantStrings(
			ids: ['hermiq.runFlow?flowId=A', 'hermiq.runFlow?flowId=B']
		);

		$grants = $set->toGrantStrings();

		$this->assertContains('hermiq.runFlow?flowId=A', $grants);
		$this->assertContains('hermiq.runFlow?flowId=B', $grants);
		$this->assertCount(2, $grants, 'neither constrained grant may displace the other');
	}//end testTwoConstraintsOnOneToolBothSurvive()

	/**
	 * A bare grant and a constrained one for the same tool coexist.
	 *
	 * A bare grant means "every target", so it is WIDER than its constrained
	 * sibling — but it still may not displace it, because the stored list is
	 * what the next save writes back and dropping either changes what the agent
	 * holds.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#scenario-two-constrained-grants-for-one-tool-both-survive
	 */
	public function testABareGrantDoesNotDisplaceItsConstrainedSibling(): void {
		$grants = ToolGrantSet::fromGrantStrings(
			ids: ['hermiq.runFlow', 'hermiq.runFlow?flowId=A']
		)->toGrantStrings();

		$this->assertContains('hermiq.runFlow', $grants);
		$this->assertContains('hermiq.runFlow?flowId=A', $grants);
	}//end testABareGrantDoesNotDisplaceItsConstrainedSibling()

	/**
	 * Entries that are not usable grant strings are skipped, not stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
	 */
	public function testUnusableEntriesAreSkipped(): void {
		$set = ToolGrantSet::fromGrantStrings(
			ids: ['hermiq.listFiles', '', 42, null, ['nested'], 'pipelinq.lead.search']
		);

		$this->assertSame(
			['hermiq.listFiles', 'pipelinq.lead.search'],
			$set->toGrantStrings()
		);
	}//end testUnusableEntriesAreSkipped()

	/**
	 * An empty grant list is empty, and grants nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
	 */
	public function testAnEmptyListGrantsNothing(): void {
		$set = ToolGrantSet::fromGrantStrings(ids: []);

		$this->assertTrue($set->isEmpty());
		$this->assertSame([], $set->toGrantStrings());
		$this->assertSame([], $set->toolIds());
		$this->assertFalse($set->has(app: 'hermiq', subject: 'listFiles', action: 'listFiles'));
	}//end testAnEmptyListGrantsNothing()
}//end class

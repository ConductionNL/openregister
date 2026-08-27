<?php

/**
 * Unit tests for ToolGrantCodec — the legacy grant grammar, in one place.
 *
 * 🔴 The property these tests exist for: a grant string round-trips through the
 * codec with its MEANING intact. The codec is the only thing that knows the
 * grammar — `{app}.{subject}.{action}`, `{app}.{name}`, and the
 * `?key=value&other=in:a,b` constraints — and every consumer reads coordinates
 * from it instead of splitting an id itself. A constraint lost in that trip does
 * not error; it silently widens a grant from one flow to every flow.
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
 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Capability;

use OCA\OpenRegister\Service\Capability\ToolGrantCodec;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the grant grammar codec.
 *
 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
 */
class ToolGrantCodecTest extends TestCase {
	/**
	 * A three-segment id is already structured: app, subject, action.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public function testDottedIdYieldsItsOwnCoordinates(): void {
		[$app, $subject, $action, $entry] = ToolGrantCodec::coordinatesFor(
			grant: 'pipelinq.lead.search'
		);

		$this->assertSame('pipelinq', $app);
		$this->assertSame('lead', $subject);
		$this->assertSame('search', $action);
		$this->assertSame('pipelinq.lead.search', $entry);
	}//end testDottedIdYieldsItsOwnCoordinates()

	/**
	 * A two-segment id is a CAPABILITY: the name is both subject and action.
	 *
	 * Splitting it into a verb and a noun is what produced subjects like "fetch"
	 * out of `webFetch` — a row named after half a word.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public function testHandWrittenIdIsBothSubjectAndAction(): void {
		[$app, $subject, $action, $entry] = ToolGrantCodec::coordinatesFor(
			grant: 'hermiq.listFiles'
		);

		$this->assertSame('hermiq', $app);
		$this->assertSame('listFiles', $subject);
		$this->assertSame('listFiles', $action);

		// 🔴 The id is CARRIED. Rebuilding it from the coordinates above would
		// give `hermiq.listFiles.listFiles`, which is not a tool.
		$this->assertSame('hermiq.listFiles', $entry);
	}//end testHandWrittenIdIsBothSubjectAndAction()

	/**
	 * A deeper id keeps its middle segments in the subject.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public function testDeepIdKeepsItsMiddleSegments(): void {
		[$app, $subject, $action] = ToolGrantCodec::coordinatesFor(
			grant: 'openregister.contact.address.update'
		);

		$this->assertSame('openregister', $app);
		$this->assertSame('contact.address', $subject);
		$this->assertSame('update', $action);
	}//end testDeepIdKeepsItsMiddleSegments()

	/**
	 * Constraints survive the split, and the id no longer carries them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#scenario-two-constrained-grants-for-one-tool-both-survive
	 */
	public function testConstraintsAreParsedOffTheId(): void {
		[$app, $subject, $action, $entry] = ToolGrantCodec::coordinatesFor(
			grant: 'hermiq.runFlow?flowId=A'
		);

		$this->assertSame('hermiq', $app);
		$this->assertSame('runFlow', $subject);
		$this->assertSame('runFlow', $action);
		$this->assertSame(['id' => 'hermiq.runFlow', 'args' => ['flowId' => 'A']], $entry);
	}//end testConstraintsAreParsedOffTheId()

	/**
	 * `in:` names a closed value set and reads back as a list.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public function testInListConstraintBecomesAnArray(): void {
		$args = ToolGrantCodec::parseConstraints(query: 'status=in:open,won&owner=me');

		$this->assertSame(['status' => ['open', 'won'], 'owner' => 'me'], $args);
	}//end testInListConstraintBecomesAnArray()

	/**
	 * A malformed constraint pair is dropped rather than stored as a key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public function testConstraintWithoutAValueIsDropped(): void {
		$this->assertSame([], ToolGrantCodec::parseConstraints(query: 'flowId'));
		$this->assertSame([], ToolGrantCodec::parseConstraints(query: ''));
		$this->assertSame(
			['flowId' => 'A'],
			ToolGrantCodec::parseConstraints(query: '&flowId=A&')
		);
	}//end testConstraintWithoutAValueIsDropped()

	/**
	 * A value containing `=` keeps it — only the FIRST `=` separates.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public function testOnlyTheFirstEqualsSeparates(): void {
		$this->assertSame(
			['filter' => 'a=b'],
			ToolGrantCodec::parseConstraints(query: 'filter=a=b')
		);
	}//end testOnlyTheFirstEqualsSeparates()

	/**
	 * An unconstrained grant stays a plain string, not a one-key map.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
	 */
	public function testEntryForKeepsAnUnconstrainedGrantAsAString(): void {
		$this->assertSame('hermiq.listFiles', ToolGrantCodec::entryFor(id: 'hermiq.listFiles', args: []));
		$this->assertSame(
			['id' => 'hermiq.runFlow', 'args' => ['flowId' => 'A']],
			ToolGrantCodec::entryFor(id: 'hermiq.runFlow', args: ['flowId' => 'A'])
		);
	}//end testEntryForKeepsAnUnconstrainedGrantAsAString()

	/**
	 * A constrained entry renders back into the grammar it was read from.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
	 */
	public function testGrantStringRendersConstraintsBack(): void {
		$this->assertSame(
			'hermiq.runFlow?flowId=A',
			ToolGrantCodec::grantStringFor(entry: ['id' => 'hermiq.runFlow', 'args' => ['flowId' => 'A']])
		);

		$this->assertSame(
			'pipelinq.lead.search?status=in:open,won',
			ToolGrantCodec::grantStringFor(
				entry: ['id' => 'pipelinq.lead.search', 'args' => ['status' => ['open', 'won']]]
			)
		);
	}//end testGrantStringRendersConstraintsBack()

	/**
	 * Parse then render returns the original grant, constraints included.
	 *
	 * The round trip is the whole contract: the read path and the write path are
	 * different methods, and a constraint that survives one but not the other
	 * widens the grant on the next save.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#scenario-a-grant-round-trips-without-losing-its-identity
	 */
	public function testEveryShapeRoundTrips(): void {
		$grants = [
			'hermiq.listFiles',
			'pipelinq.lead.search',
			'openregister.contact.address.update',
			'hermiq.runFlow?flowId=A',
			'pipelinq.lead.search?status=in:open,won',
		];

		foreach ($grants as $grant) {
			[, , , $entry] = ToolGrantCodec::coordinatesFor(grant: $grant);

			$this->assertSame(
				$grant,
				ToolGrantCodec::grantStringFor(entry: $entry),
				"$grant must survive the round trip unchanged"
			);
		}
	}//end testEveryShapeRoundTrips()

	/**
	 * An entry that cannot name its tool is DROPPED, never defaulted.
	 *
	 * Inventing `{app}.{subject}.{action}` for it would resolve to a tool id that
	 * may not exist — or worse, to one that does and was never granted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
	 */
	public function testAnEntryWithoutAnIdIsDropped(): void {
		$this->assertNull(ToolGrantCodec::sanitiseEntry(entry: ['args' => ['flowId' => 'A']]));
		$this->assertNull(ToolGrantCodec::sanitiseEntry(entry: ['id' => '']));
		$this->assertNull(ToolGrantCodec::sanitiseEntry(entry: ['id' => 123]));
		$this->assertNull(ToolGrantCodec::sanitiseEntry(entry: ''));
		$this->assertNull(ToolGrantCodec::sanitiseEntry(entry: null));
		$this->assertNull(ToolGrantCodec::sanitiseEntry(entry: 42));
	}//end testAnEntryWithoutAnIdIsDropped()

	/**
	 * A usable entry is normalised: no args means the bare id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
	 */
	public function testSanitiseNormalisesAnArgumentlessEntry(): void {
		$this->assertSame('hermiq.listFiles', ToolGrantCodec::sanitiseEntry(entry: 'hermiq.listFiles'));
		$this->assertSame('hermiq.listFiles', ToolGrantCodec::sanitiseEntry(entry: ['id' => 'hermiq.listFiles']));
		$this->assertSame(
			'hermiq.listFiles',
			ToolGrantCodec::sanitiseEntry(entry: ['id' => 'hermiq.listFiles', 'args' => 'not-an-array'])
		);
		$this->assertSame(
			['id' => 'hermiq.runFlow', 'args' => ['flowId' => 'A']],
			ToolGrantCodec::sanitiseEntry(entry: ['id' => 'hermiq.runFlow', 'args' => ['flowId' => 'A']])
		);
	}//end testSanitiseNormalisesAnArgumentlessEntry()
}//end class

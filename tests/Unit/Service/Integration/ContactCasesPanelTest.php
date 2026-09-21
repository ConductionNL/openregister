<?php

/**
 * What a contact is involved in, grouped the way the panel asks.
 *
 * 🔴 AN OBJECT THE READER MAY NOT SEE MUST NOT VANISH FROM THE COUNT. Dropping
 * it makes the panel say a contact is involved in two cases when they are
 * involved in five, and nothing on screen says so: the reader is quietly told
 * something false rather than told less. It is counted apart instead.
 *
 * 🔴 AND THE COUNT MUST NOT BE BROKEN DOWN BY SCHEMA. "Two objects you may not
 * read, one of them a bezwaar" is most of what the reader was not allowed to
 * know, so the unreadable tally is deliberately flat.
 *
 * 🔴 A GROUP IS A SUMMARY, NOT A LIST. A contact linked to four hundred
 * objects must not render four hundred rows into a sidebar; the count above
 * the group is what says there are more.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\ContactCasesPanel;
use PHPUnit\Framework\TestCase;

/**
 * The grouping behind the cases panel.
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */
class ContactCasesPanelTest extends TestCase {

	private ContactCasesPanel $panel;

	protected function setUp(): void {
		parent::setUp();
		$this->panel = new ContactCasesPanel();
	}//end setUp()

	/**
	 * One resolved row.
	 *
	 * @param string $schema   Its schema slug.
	 * @param string $title    Its title.
	 * @param bool   $readable Whether this reader may see it.
	 *
	 * @return array<string,mixed> The row.
	 */
	private function row(string $schema, string $title, bool $readable = true): array {
		return [
			'schema' => $schema,
			'schemaLabel' => ucfirst($schema),
			'objectUuid' => strtolower($title),
			'title' => $title,
			'status' => 'in behandeling',
			'url' => '/apps/dossiq/cases/' . strtolower($title),
			'readable' => $readable,
		];
	}//end row()

	public function testTheLinksAreGroupedBySchema(): void {
		$result = $this->panel->group([
			$this->row('case', 'Zaak A'),
			$this->row('case', 'Zaak B'),
			$this->row('bezwaar', 'Bezwaar C'),
		]);

		$this->assertSame(['case', 'bezwaar'], array_column($result['groups'], 'schema'));
		$this->assertSame([2, 1], array_column($result['groups'], 'count'));
		$this->assertSame(3, $result['total']);
	}//end testTheLinksAreGroupedBySchema()

	public function testTheRowsCarryWhatAReaderNeedsToRecogniseThem(): void {
		$result = $this->panel->group([$this->row('case', 'Zaak A')]);
		$row = $result['groups'][0]['rows'][0];

		$this->assertSame('Zaak A', $row['title']);
		$this->assertSame('in behandeling', $row['status']);
		$this->assertStringContainsString('/cases/', $row['url']);
	}//end testTheRowsCarryWhatAReaderNeedsToRecogniseThem()

	public function testAnObjectTheReaderMayNotSeeIsCountedAndNeverNamed(): void {
		$result = $this->panel->group([
			$this->row('case', 'Zaak A'),
			$this->row('bezwaar', 'Geheim bezwaar', false),
		]);

		$this->assertSame(1, $result['unreadable']);
		$this->assertSame(2, $result['total'], 'the reader is told there are two, not one');

		$rendered = json_encode($result['groups']);
		$this->assertStringNotContainsString('Geheim bezwaar', (string)$rendered);
		// And not broken down by schema either: which register somebody
		// appears in is most of what the reader was not allowed to know.
		$this->assertSame(['case'], array_column($result['groups'], 'schema'));
	}//end testAnObjectTheReaderMayNotSeeIsCountedAndNeverNamed()

	public function testALinkWithNoSchemaIsCountedRatherThanInventedIntoAGroup(): void {
		$result = $this->panel->group([['objectUuid' => 'x', 'title' => 'Ergens', 'readable' => true]]);

		$this->assertSame([], $result['groups']);
		$this->assertSame(1, $result['unreadable']);
	}//end testALinkWithNoSchemaIsCountedRatherThanInventedIntoAGroup()

	public function testTheBiggestGroupComesFirstAndTiesAreStable(): void {
		$rows = array_merge(
			[$this->row('bezwaar', 'B1')],
			[$this->row('case', 'C1'), $this->row('case', 'C2')],
			[$this->row('advies', 'A1')],
		);

		$first = array_column($this->panel->group($rows)['groups'], 'schema');
		$again = array_column($this->panel->group($rows)['groups'], 'schema');

		$this->assertSame($first, $again);
		// Biggest first, then by label, so the order is a fact somebody chose.
		$this->assertSame('case', $first[0]);
		$this->assertSame(['advies', 'bezwaar'], array_slice($first, 1));
	}//end testTheBiggestGroupComesFirstAndTiesAreStable()

	public function testAGroupIsASummaryAndSaysWhenItHeldRowsBack(): void {
		$rows = [];
		for ($i = 0; $i < 25; $i++) {
			$rows[] = $this->row('case', 'Zaak ' . $i);
		}

		$group = $this->panel->group($rows)['groups'][0];

		$this->assertSame(25, $group['count']);
		$this->assertCount(ContactCasesPanel::ROWS_PER_GROUP, $group['rows']);
		$this->assertTrue($this->panel->hasMore($group));
	}//end testAGroupIsASummaryAndSaysWhenItHeldRowsBack()

	public function testAGroupShowingEverythingSaysThereIsNoMore(): void {
		$group = $this->panel->group([$this->row('case', 'Zaak A')])['groups'][0];

		$this->assertFalse($this->panel->hasMore($group));
	}//end testAGroupShowingEverythingSaysThereIsNoMore()

	public function testAContactWithNoLinksAnswersEmptyRatherThanNothing(): void {
		$result = $this->panel->group([]);

		$this->assertSame(['groups' => [], 'unreadable' => 0, 'total' => 0], $result);
	}//end testAContactWithNoLinksAnswersEmptyRatherThanNothing()
}//end class

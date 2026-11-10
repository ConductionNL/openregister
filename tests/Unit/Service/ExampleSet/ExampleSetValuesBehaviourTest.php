<?php

/**
 * Unit test for ExampleSetValues: the placeholder kinds a fill resolves by
 * name, file ids, booleans in text, gaps left in text, and owners.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentRows;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetFillRun
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentRows
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetMoment
 */
class ExampleSetValuesBehaviourTest extends TestCase {

	/**
	 * A context with every kind filled.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		return [
			'cast'          => ['sanne' => ['uid' => 'sanne', 'flag' => true, 'off' => false, 'address' => ['street' => 'Lindelaan']], 'ghost' => 'not-an-entry'],
			'objects'       => ['case-1' => ['id' => 'abc'], 'case-2' => [ExampleSetValues::PLANNED => true]],
			'files'         => ['dossier' => [20525, 0, 'x']],
			'flows'         => ['self' => ['uuid' => 'flow-uuid']],
			'lookups'       => ['caseType' => 'type-uuid', 'empty' => ''],
			'organisations' => ['acme' => 'org-uuid'],
			'instance'      => ['baseUrl' => 'https://cloud.example.test'],
			'registers'     => ['cases' => 7],
			'schemas'       => ['case' => 12],
			'today'         => new DateTimeImmutable('2026-10-13 12:00', new DateTimeZone('UTC')),
		];
	}//end context()

	/**
	 * Fill one value and return it with its gaps.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array{0: mixed, 1: array<int, string>}
	 */
	private function fillOne(mixed $value): array {
		$gaps   = [];
		$filled = (new ExampleSetValues())->fill(value: $value, context: $this->context(), gaps: $gaps);
		return [$filled, $gaps];
	}//end fillOne()

	/**
	 * Placeholders resolved by name, by file index and by planned object.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function resolved(): array {
		return [
			'lookup'                   => ['{{lookup:caseType}}', 'type-uuid'],
			'organisation'             => ['{{organisation:acme}}', 'org-uuid'],
			'instance'                 => ['{{instance:baseUrl}}', 'https://cloud.example.test'],
			'register keeps its type'  => ['{{register:cases}}', 7],
			'schema'                   => ['{{schema:case}}', 12],
			'flow self is its uuid'    => ['{{flow:self}}', 'flow-uuid'],
			'file id by index'         => ['{{file:dossier.0}}', 20525],
			'planned object stand-in'  => ['{{object:case-2.id}}', 'example-set-stand-in:case-2.id'],
			'true in text'             => ['on: {{cast:sanne.flag}}', 'on: true'],
			'false in text'            => ['off: {{cast:sanne.off}}', 'off: false'],
			'a number in text'         => ['zaak {{register:cases}}', 'zaak 7'],
			'an object field'          => ['{{object:case-1.id}}', 'abc'],
			'a day from today'         => ['{{date:-3}}', '2026-10-10'],
		];
	}//end resolved()

	/**
	 * Each placeholder resolves to its value and leaves no gap.
	 *
	 * @param string $placeholder The written value.
	 * @param mixed  $expected    The filled value.
	 *
	 * @return void
	 *
	 * @dataProvider resolved
	 */
	public function testAPlaceholderResolves(string $placeholder, mixed $expected): void {
		[$filled, $gaps] = $this->fillOne(value: $placeholder);
		$this->assertSame($expected, $filled);
		$this->assertSame([], $gaps);
	}//end testAPlaceholderResolves()

	/**
	 * Placeholders that resolve to nothing.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unresolved(): array {
		return [
			'an empty lookup'             => ['{{lookup:empty}}'],
			'an unknown name'             => ['{{organisation:nope}}'],
			'a file index that is no int' => ['{{file:dossier.first}}'],
			'a zero file id'              => ['{{file:dossier.1}}'],
			'a file id that is text'      => ['{{file:dossier.2}}'],
			'an unplanned object'         => ['{{object:case-9.id}}'],
			'a planned object, no field'  => ['{{object:case-2}}'],
			'a path without a field'      => ['{{cast:sanne}}'],
			'an unknown kind'             => ['{{weather:today}}'],
		];
	}//end unresolved()

	/**
	 * A whole value that resolves to nothing stays as written and is a gap.
	 *
	 * @param string $placeholder The written value.
	 *
	 * @return void
	 *
	 * @dataProvider unresolved
	 */
	public function testAnUnresolvedWholeValueIsAGap(string $placeholder): void {
		[$filled, $gaps] = $this->fillOne(value: $placeholder);
		$this->assertSame($placeholder, $filled);
		$this->assertSame([$placeholder], $gaps);
	}//end testAnUnresolvedWholeValueIsAGap()

	/**
	 * A placeholder inside text that resolves to nothing, or to an array, stays in the text and is a gap.
	 *
	 * @return void
	 */
	public function testAnUnresolvedPlaceholderInTextIsAGap(): void {
		[$filled, $gaps] = $this->fillOne(value: 'zaak {{object:case-9.id}} van {{cast:sanne.uid}} aan {{cast:sanne.address}}');
		$this->assertSame('zaak {{object:case-9.id}} van sanne aan {{cast:sanne.address}}', $filled);
		$this->assertSame(['{{object:case-9.id}}', '{{cast:sanne.address}}'], $gaps);
	}//end testAnUnresolvedPlaceholderInTextIsAGap()

	/**
	 * A fill walks arrays and keeps what holds no placeholder as it is.
	 *
	 * @return void
	 */
	public function testAFillWalksArrays(): void {
		[$filled, $gaps] = $this->fillOne(value: ['a' => ['{{lookup:caseType}}', 5, null], 'b' => 'plain {{ text']);
		$this->assertSame(['a' => ['type-uuid', 5, null], 'b' => 'plain {{ text'], $filled);
		$this->assertSame([], $gaps);
	}//end testAFillWalksArrays()

	/**
	 * An object's verbatim fields are kept as written and the rest is filled, in the data's own order.
	 *
	 * @return void
	 */
	public function testFillObjectKeepsVerbatimFields(): void {
		$gaps   = [];
		$object = ['data' => ['raw' => '{{lookup:caseType}}', 'type' => '{{lookup:caseType}}', 'gone' => '{{lookup:nope}}'], 'verbatimFields' => ['raw', 7]];

		$filled = (new ExampleSetValues())->fillObject(object: $object, context: $this->context(), gaps: $gaps);

		$this->assertSame(['raw' => '{{lookup:caseType}}', 'type' => 'type-uuid', 'gone' => '{{lookup:nope}}'], $filled);
		$this->assertSame(['{{lookup:nope}}'], $gaps);
	}//end testFillObjectKeepsVerbatimFields()

	/**
	 * Views, tasks and flows of a fragment are read through the fragment rows.
	 *
	 * @return void
	 */
	public function testRowsOfAFragmentComeFromTheFragmentRows(): void {
		$rows = $this->createMock(ExampleSetFragmentRows::class);
		$rows->expects($this->once())->method('viewsOf')->with(['k' => 1], null)->willReturn([['view']]);
		$rows->expects($this->once())->method('tasksOf')->with(['k' => 1], ['r' => 1])->willReturn([['task']]);
		$rows->expects($this->once())->method('flowsOf')->with(['k' => 2], null)->willReturn([['flow']]);
		$values = new ExampleSetValues(rows: $rows);

		$this->assertSame([['view']], $values->viewsOf(fragment: ['k' => 1], record: null));
		$this->assertSame([['task']], $values->tasksOf(fragment: ['k' => 1], record: ['r' => 1]));
		$this->assertSame([['flow']], $values->flowsOf(fragment: ['k' => 2], record: null));
	}//end testRowsOfAFragmentComeFromTheFragmentRows()

	/**
	 * An object without an owner has none, and a cast owner is accepted.
	 *
	 * @return void
	 */
	public function testOwnerOfAcceptsACastAccount(): void {
		$values = new ExampleSetValues();
		$this->assertSame(['owner' => null, 'refusal' => ''], $values->ownerOf(object: [], context: $this->context()));
		$this->assertSame(
			['owner' => 'sanne', 'refusal' => ''],
			$values->ownerOf(object: ['owner' => '{{cast:sanne.uid}}'], context: $this->context())
		);
	}//end testOwnerOfAcceptsACastAccount()

	/**
	 * An owner that is no cast account, is not text, or leaves a gap is refused.
	 *
	 * @return void
	 */
	public function testOwnerOfRefusesWhatIsNoCastAccount(): void {
		$values = new ExampleSetValues();
		$this->assertSame(
			['owner' => null, 'refusal' => 'owner ""bob"" is no account the cast declares'],
			$values->ownerOf(object: ['owner' => 'bob'], context: $this->context())
		);
		$this->assertSame(
			['owner' => null, 'refusal' => 'owner "["sanne"]" is no account the cast declares'],
			$values->ownerOf(object: ['owner' => ['sanne']], context: $this->context())
		);
		$this->assertSame(
			['owner' => null, 'refusal' => 'owner ""{{cast:nobody.uid}}"" is no account the cast declares'],
			$values->ownerOf(object: ['owner' => '{{cast:nobody.uid}}'], context: $this->context())
		);
	}//end testOwnerOfRefusesWhatIsNoCastAccount()
}//end class

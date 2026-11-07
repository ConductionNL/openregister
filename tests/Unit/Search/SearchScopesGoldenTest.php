<?php

/**
 * Golden pin for SearchScopes.
 *
 * Records what parse(), narrows(), jsonSerialize() and narrowSchemas() gave
 * for a broad table of inputs before the class was split into smaller
 * methods, so the refactor can be shown to change no result.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Search\SearchScopes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every SearchScopes result recorded on the pre-refactor code.
 */
class SearchScopesGoldenTest extends TestCase {
	/**
	 * The schema rows every case narrows.
	 *
	 * @var array<int, array{id: int|string, slug: string, register: string}>
	 */
	private const SCHEMAS = [
		['id' => 1, 'slug' => 'person', 'register' => 'crm'],
		['id' => '2', 'slug' => 'case', 'register' => 'zaken'],
		['id' => 3, 'slug' => 'note', 'register' => 'crm'],
		['id' => 3, 'slug' => 'note', 'register' => 'crm'],
		['id' => 4, 'slug' => 'Person', 'register' => 'other'],
	];

	/**
	 * The recorded results: input, serialised scopes, narrows(), narrowed ids.
	 *
	 * @return array<string, array{mixed, string, bool, string}> The cases.
	 */
	public static function recordedResults(): array {
		return [
			'input 01' => [null, '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', false, '[1,2,3,3,4]'],
			'input 02' => ['', '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', false, '[1,2,3,3,4]'],
			'input 03' => ['files', '{"apps":[],"registers":[],"schemas":[],"filesOnly":true,"unparsed":[]}', true, '[1,2,3,3,4]'],
			'input 04' => ['FILES', '{"apps":[],"registers":[],"schemas":[],"filesOnly":true,"unparsed":[]}', true, '[1,2,3,3,4]'],
			'input 05' => [' files , files ', '{"apps":[],"registers":[],"schemas":[],"filesOnly":true,"unparsed":[]}', true, '[1,2,3,3,4]'],
			'input 06' => ['app:procest', '{"apps":["procest"],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', true, '[1,2,3,3,4]'],
			'input 07' => ['APP: Procest ', '{"apps":["procest"],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', true, '[1,2,3,3,4]'],
			'input 08' => ['register:crm', '{"apps":[],"registers":["crm"],"schemas":[],"filesOnly":false,"unparsed":[]}', true, '[1,3]'],
			'input 09' => ['schema:case', '{"apps":[],"registers":[],"schemas":["case"],"filesOnly":false,"unparsed":[]}', true, '[2]'],
			'input 10' => ['schema:case,register:crm', '{"apps":[],"registers":["crm"],"schemas":["case"],"filesOnly":false,"unparsed":[]}', true, '[1,2,3]'],
			'input 11' => ['register:crm,register:crm', '{"apps":[],"registers":["crm"],"schemas":[],"filesOnly":false,"unparsed":[]}', true, '[1,3]'],
			'input 12' => ['colour:blue', '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":["colour:blue"]}', false, '[1,2,3,3,4]'],
			'input 13' => ['nocolon', '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":["nocolon"]}', false, '[1,2,3,3,4]'],
			'input 14' => ['schema:', '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":["schema:"]}', false, '[1,2,3,3,4]'],
			'input 15' => ['schema: ', '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":["schema:"]}', false, '[1,2,3,3,4]'],
			'input 16' => [':x', '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[":x"]}', false, '[1,2,3,3,4]'],
			'input 17' => ['app:a:b', '{"apps":["a:b"],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', true, '[1,2,3,3,4]'],
			'input 18' => ['files,schema:person,colour:blue,app:x', '{"apps":["x"],"registers":[],"schemas":["person"],"filesOnly":true,"unparsed":["colour:blue"]}', true, '[1]'],
			'input 19' => [',,,', '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', false, '[1,2,3,3,4]'],
			'input 20' => [' , ', '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', false, '[1,2,3,3,4]'],
			'input 21' => ['register:zaken,schema:note', '{"apps":[],"registers":["zaken"],"schemas":["note"],"filesOnly":false,"unparsed":[]}', true, '[2,3]'],
			'input 22' => ['schema:person,schema:PERSON', '{"apps":[],"registers":[],"schemas":["person"],"filesOnly":false,"unparsed":[]}', true, '[1]'],
			'input 23' => [['schema:case', 'files', '  app:x  ', 5, ''], '{"apps":["x"],"registers":[],"schemas":["case"],"filesOnly":true,"unparsed":["5"]}', true, '[2]'],
			'input 24' => [[], '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', false, '[1,2,3,3,4]'],
			'input 25' => [42, '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', false, '[1,2,3,3,4]'],
			'input 26' => [true, '{"apps":[],"registers":[],"schemas":[],"filesOnly":false,"unparsed":[]}', false, '[1,2,3,3,4]'],
			'input 27' => [['register:other', 'register:crm'], '{"apps":[],"registers":["other","crm"],"schemas":[],"filesOnly":false,"unparsed":[]}', true, '[1,3,4]'],
			'input 28' => ['unknown:a,unknown:a,files', '{"apps":[],"registers":[],"schemas":[],"filesOnly":true,"unparsed":["unknown:a"]}', true, '[1,2,3,3,4]'],
		];
	}//end recordedResults()

	/**
	 * Each input still parses, serialises and narrows as recorded.
	 *
	 * @param mixed  $raw      The scopes as a caller sends them.
	 * @param string $json     The recorded jsonSerialize() output, as JSON.
	 * @param bool   $narrows  The recorded narrows() result.
	 * @param string $narrowed The recorded narrowSchemas() result, as JSON.
	 *
	 * @return void
	 */
	#[DataProvider('recordedResults')]
	public function testResultMatchesRecording(mixed $raw, string $json, bool $narrows, string $narrowed): void {
		$scopes = SearchScopes::parse($raw);

		$this->assertSame($json, json_encode($scopes->jsonSerialize()));
		$this->assertSame($narrows, $scopes->narrows());
		$this->assertSame($narrowed, json_encode($scopes->narrowSchemas(self::SCHEMAS)));
	}//end testResultMatchesRecording()

	/**
	 * A register-only scope never reads a row's slug, so rows without one narrow cleanly.
	 *
	 * @return void
	 */
	public function testRegisterOnlyScopeIgnoresMissingSlug(): void {
		$rows = [
			['id' => 7, 'register' => 'crm'],
			['id' => 8, 'register' => 'zaken'],
		];

		$this->assertSame([7], SearchScopes::parse('register:crm')->narrowSchemas($rows));
	}//end testRegisterOnlyScopeIgnoresMissingSlug()

	/**
	 * A schema-only scope never reads a row's register.
	 *
	 * @return void
	 */
	public function testSchemaOnlyScopeIgnoresMissingRegister(): void {
		$rows = [
			['id' => 7, 'slug' => 'person'],
			['id' => 8, 'slug' => 'case'],
		];

		$this->assertSame([8], SearchScopes::parse('schema:case')->narrowSchemas($rows));
	}//end testSchemaOnlyScopeIgnoresMissingRegister()
}//end class

<?php

/**
 * ReferenceFilterParser: `_ref[<property>][<field>]` blocks become forward related-row filters on the schema the property references, and everything unusable is refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Query\ReferenceFilterParser;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Query\ReferenceFilterParser
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilterParser
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowBlockField
 * @uses \OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ReferenceFilterParserTest extends TestCase {

	private Schema $request;

	private Schema $person;

	/**
	 * Schemas resolved by reference, as the parser asks for them.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	protected function setUp(): void {
		$this->person = new Schema();
		$this->person->setId(12);
		$this->person->setProperties(
			[
				'woonplaats' => ['type' => 'string'],
				'geboortedatum' => ['type' => 'string', 'format' => 'date'],
				'adres' => ['type' => 'string', 'format' => 'uuid', '$ref' => '#/components/schemas/address'],
			]
		);

		$this->request = new Schema();
		$this->request->setId(7);
		$this->request->setProperties(
			[
				'aanvrager' => ['type' => 'string', 'format' => 'uuid', '$ref' => '#/components/schemas/person'],
				'betrokkenen' => ['type' => 'array', 'items' => ['type' => 'string', '$ref' => 'person']],
				'titel' => ['type' => 'string'],
				'zaaktype' => ['type' => 'string', '$ref' => 'missing-schema'],
			]
		);
	}

	private function parser(): ReferenceFilterParser {
		return new ReferenceFilterParser(
			function (string $reference): ?Schema {
				$this->asked[] = $reference;
				return ($reference === 'person') ? $this->person : null;
			}
		);
	}

	/**
	 * @return list<RelatedRowFilter>
	 */
	private function parse(array $ref): array {
		return $this->parser()->parse(['_ref' => $ref, 'other' => 'x'], $this->request);
	}

	public function testAQueryWithoutRefHasNoFilters(): void {
		$this->assertSame([], $this->parser()->parse(['titel' => 'x'], $this->request));
		$this->assertSame([], $this->asked);
	}

	public function testABlockBecomesAForwardFilterOnTheReferencedSchema(): void {
		$filters = $this->parse(['aanvrager' => ['woonplaats' => 'Zuiddrecht']]);

		$this->assertCount(1, $filters);
		$this->assertSame('12', $filters[0]->schema);
		$this->assertSame('aanvrager', $filters[0]->foreignKey);
		$this->assertSame(RelatedRowFilter::DIRECTION_FORWARD, $filters[0]->direction);
		$this->assertSame([['field' => 'woonplaats', 'operator' => 'eq', 'value' => 'Zuiddrecht']], $filters[0]->conditions);
		// The `$ref` path is reduced to the schema it names.
		$this->assertSame(['person'], $this->asked);
	}

	public function testAnOperatorAndSeveralFieldsHoldOnOneReferencedObject(): void {
		$filters = $this->parse(['aanvrager' => ['woonplaats' => 'Zuiddrecht', 'geboortedatum' => ['gte' => '1990-01-01', 'lt' => '2000-01-01']]]);

		$this->assertCount(1, $filters);
		$this->assertSame(
			[
				['field' => 'woonplaats', 'operator' => 'eq', 'value' => 'Zuiddrecht'],
				['field' => 'geboortedatum', 'operator' => 'gte', 'value' => '1990-01-01'],
				['field' => 'geboortedatum', 'operator' => 'lt', 'value' => '2000-01-01'],
			],
			$filters[0]->conditions
		);
	}

	public function testAListReferenceAndABareListAreAccepted(): void {
		$filters = $this->parse(['betrokkenen' => ['woonplaats' => ['Zuiddrecht', 'Oosterhout']]]);

		$this->assertSame('betrokkenen', $filters[0]->foreignKey);
		$this->assertSame([['field' => 'woonplaats', 'operator' => 'in', 'value' => ['Zuiddrecht', 'Oosterhout']]], $filters[0]->conditions);
	}

	public function testANumberedSuffixAsksForASecondIndependentObject(): void {
		$filters = $this->parse(
			[
				'betrokkenen' => ['woonplaats' => 'Zuiddrecht'],
				'betrokkenen:2' => ['woonplaats' => 'Oosterhout'],
			]
		);

		$this->assertCount(2, $filters);
		$this->assertSame('betrokkenen', $filters[1]->foreignKey);
		$this->assertSame('Oosterhout', $filters[1]->conditions[0]['value']);
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'not an object' => ['Zuiddrecht', '_ref must be an object of reference-property blocks'],
			'empty' => [[], '_ref must be an object of reference-property blocks'],
			'unknown property' => [['aanvraagster' => ['woonplaats' => 'x']], "the schema has no property 'aanvraagster'"],
			'not a reference' => [['titel' => ['woonplaats' => 'x']], "the property 'titel' is not a reference"],
			'referenced schema gone' => [['zaaktype' => ['naam' => 'x']], "the schema 'missing-schema' that property 'zaaktype' references cannot be found"],
			'misspelt field' => [['aanvrager' => ['woonplaatz' => 'Zuiddrecht']], "_ref[aanvrager]: the referenced schema has no field 'woonplaatz'"],
			'no condition' => [['aanvrager' => []], '_ref[aanvrager] must carry at least one condition'],
			'unknown operator' => [['aanvrager' => ['woonplaats' => ['like' => 'Zuid']]], "The condition _ref[aanvrager][woonplaats] uses operator 'like'"],
			'dotted chain' => [['aanvrager.adres' => ['stad' => 'x']], 'reaches through more than one reference'],
			'dotted field' => [['aanvrager' => ['adres.stad' => 'x']], 'reaches through more than one reference'],
			'nested chain' => [['aanvrager' => ['adres' => ['stad' => 'Zuiddrecht']]], '_ref[aanvrager][adres] reaches through more than one reference'],
			'bad suffix' => [['aanvrager:x' => ['woonplaats' => 'x']], "the suffix after ':' must be a number"],
		];
	}

	/**
	 * @dataProvider refusals
	 */
	public function testAnUnusableBlockIsRefusedNamingThePart(mixed $ref, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$this->parser()->parse(['_ref' => $ref], $this->request);
	}

	public function testAnOperatorOnAReferenceFieldItselfIsNotAChain(): void {
		$filters = $this->parse(['aanvrager' => ['adres' => ['eq' => 'addr-uuid']]]);

		$this->assertSame([['field' => 'adres', 'operator' => 'eq', 'value' => 'addr-uuid']], $filters[0]->conditions);
	}
}

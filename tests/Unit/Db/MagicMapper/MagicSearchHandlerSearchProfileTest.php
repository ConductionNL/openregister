<?php

/**
 * MagicSearchHandler reads a property's match type and free-text participation
 * from the injected Kernel search-profile contract.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use LogicException;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\SearchLeafScope;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\PropertySearchProfileInterface;
use OCA\OpenRegister\Search\Service\Search\PropertySearchProfile;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchLeafScope
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchLeaf
 * @uses   \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile
 */
final class MagicSearchHandlerSearchProfileTest extends TestCase {

	private const PROPERTIES = [
		'title' => ['type' => 'string'],
		'code' => ['type' => 'string'],
	];

	public function testTheSearchSqlFollowsTheSearchComponentsProfile(): void {
		$conditions = $this->conditions(profile: new PropertySearchProfile());

		$this->assertCount(2, $conditions);
		$this->assertStringContainsString('"title"', $conditions[0]);
		$this->assertStringContainsString("'%x%'", $conditions[0]);
		$this->assertStringContainsString('"code"', $conditions[1]);
	}//end testTheSearchSqlFollowsTheSearchComponentsProfile()

	public function testTheInjectedProfileDecidesTheColumnsAndTheirMatchType(): void {
		$conditions = $this->conditions(profile: new class implements PropertySearchProfileInterface {
			public function declaredBy(array $property): array {
				return ['matchType' => self::MATCH_EXACT, 'inputControl' => 'text', 'declared' => true];
			}//end declaredBy()

			public static function matchTypeFor(array $property): string {
				return self::MATCH_EXACT;
			}//end matchTypeFor()

			public static function participatesInFreeText(array $property): bool {
				return ($property['type'] ?? null) === 'string' && isset($property['format']) === false && count($property) === 1;
			}//end participatesInFreeText()
		});

		$this->assertCount(2, $conditions);
		$this->assertStringContainsString("'x'", $conditions[0]);
		$this->assertStringNotContainsString("'%x%'", implode(' ', $conditions));
	}//end testTheInjectedProfileDecidesTheColumnsAndTheirMatchType()

	public function testAHandlerWithoutAProfileRefusesToSearchProperties(): void {
		$this->expectException(LogicException::class);

		$this->conditions(profile: null);
	}//end testAHandlerWithoutAProfileRefusesToSearchProperties()

	/**
	 * The free-text conditions of the term "x" over the two properties.
	 *
	 * @param PropertySearchProfileInterface|null $profile The profile the handler reads.
	 *
	 * @return array<int, string>
	 */
	private function conditions(?PropertySearchProfileInterface $profile): array {
		$handler = (new ReflectionClass(MagicSearchHandler::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(MagicSearchHandler::class, 'searchProfile'))->setValue($handler, $profile);

		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);
		$scope = new SearchLeafScope(schema: $schema, isPostgres: true);
		$scope->connection = new class {
			public function quote(string $value): string {
				return "'" . $value . "'";
			}//end quote()
		};

		return (new ReflectionMethod(MagicSearchHandler::class, 'propertyLeafConditions'))->invoke($handler, $scope->term(pattern: '%x%', literal: 'x'));
	}//end conditions()
}//end class

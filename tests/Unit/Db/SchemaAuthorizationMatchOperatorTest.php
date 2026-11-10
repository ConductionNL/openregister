<?php

/**
 * An authorization `match` is refused at save when it names an operator nobody evaluates (openregister#4089).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Operator names and operand shapes in a `match` are checked when the schema is saved.
 *
 * Before openregister#4089 `validateAuthorizationRule()` only checked that
 * `match` was an array. planninq shipped `{"project": {"$in": {"$lookup": ...}}}`;
 * Open Register has no `$lookup`, the import accepted it, and every project
 * member saw no tasks with nobody told why.
 */
class SchemaAuthorizationMatchOperatorTest extends TestCase {

	/**
	 * A schema whose read rule carries the given match.
	 *
	 * @param array<string, mixed> $match The match clause.
	 *
	 * @return Schema
	 */
	private function schemaWithMatch(array $match): Schema {
		$schema = new Schema();
		$schema->setAuthorization(
			[
				'read' => [
					['group' => 'members', 'match' => $match],
				],
			]
		);

		return $schema;
	}//end schemaWithMatch()

	/**
	 * The planninq rule: `$in` over a `$lookup` map.
	 *
	 * @return void
	 */
	public function testAnInOperandThatIsNotAListIsRefused(): void {
		$schema = $this->schemaWithMatch(['project' => ['$in' => ['$lookup' => ['from' => 'project', 'field' => 'members']]]]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('$in');
		$schema->validateAuthorization();
	}//end testAnInOperandThatIsNotAListIsRefused()

	/**
	 * An operator Open Register does not evaluate is refused, not stored.
	 *
	 * @return void
	 */
	public function testAnUnknownOperatorIsRefused(): void {
		$schema = $this->schemaWithMatch(['status' => 'open', 'project' => ['$lookup' => ['from' => 'project']]]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('$lookup');
		$schema->validateAuthorization();
	}//end testAnUnknownOperatorIsRefused()

	/**
	 * A `$nin` operand that is a single value rather than a list is refused.
	 *
	 * @return void
	 */
	public function testANinOperandThatIsAPlainValueIsRefused(): void {
		$schema = $this->schemaWithMatch(['status' => ['$nin' => 'closed']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('$nin');
		$schema->validateAuthorization();
	}//end testANinOperandThatIsAPlainValueIsRefused()

	/**
	 * Every operator the evaluators handle, in the shapes they accept, still saves.
	 *
	 * @return void
	 */
	public function testEveryHandledOperatorStillSaves(): void {
		$schema = $this->schemaWithMatch(
			[
				'owner' => '$userId',
				'_organisation' => '$organisation',
				'status' => ['$in' => ['open', 'review']],
				'group' => ['$in' => '$user.groups'],
				'phase' => ['$nin' => ['closed']],
				'sharedWith' => ['$contains' => '$userId'],
				'deletedAt' => ['$exists' => false],
				'publishDate' => ['$lte' => '$now'],
				'score' => ['$gte' => 1, '$lt' => 10],
				'kind' => ['$eq' => 'a'],
				'label' => ['$ne' => 'b'],
				'rank' => ['$gt' => 0],
				'archived' => null,
				'active' => true,
			]
		);

		$this->assertTrue($schema->validateAuthorization());
	}//end testEveryHandledOperatorStillSaves()
}//end class

<?php

/**
 * The relation statements carry the organisation filter they are given.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRelationSql;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRelationSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 */
class MagicRelationSqlTest extends TestCase {
	/**
	 * The filter's parameters follow those of the conditions, as its fragment does.
	 *
	 * @return void
	 */
	public function testTheOrganisationParametersFollowTheConditions(): void {
		$sql = new MagicRelationSql(isPostgres: false, organisationFilter: [' AND _organisation = ?', ['org']]);

		[$where, $params] = $sql->batchWhere(uuids: ['a'], extraFields: ['x']);

		$this->assertStringEndsWith(' AND _organisation = ?', $where);
		$this->assertSame(['a', 'a', '%a%', 'org'], $params);
	}//end testTheOrganisationParametersFollowTheConditions()

	/**
	 * A lookup of one uuid keeps to the filter too.
	 *
	 * @return void
	 */
	public function testALookupOfOneUuidCarriesTheOrganisationFilter(): void {
		$sql = new MagicRelationSql(isPostgres: true, organisationFilter: [' AND _organisation = ?', ['org']]);

		[$statement, $params] = $sql->referencing(fullTableName: 't', uuid: 'a');

		$this->assertStringEndsWith(' AND _organisation = ? LIMIT 100', $statement);
		$this->assertSame(['a', 'a', 'org'], $params);
	}//end testALookupOfOneUuidCarriesTheOrganisationFilter()
}//end class

<?php

/**
 * Behaviour tests for SearchIndexRowCounts: summing per slug and withholding the total when a table is uncounted.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Search;

use OCA\OpenRegister\Search\Service\Search\SearchIndexRowCounts;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Service\Search\SearchIndexRowCounts
 */
class SearchIndexRowCountsBehaviourTest extends TestCase
{
    public function testNothingAddedReportsAZeroTotal(): void
    {
        $this->assertSame(['rowsInTables' => 0, 'indexed' => []], (new SearchIndexRowCounts())->toArray());
    }//end testNothingAddedReportsAZeroTotal()

    public function testTablesSharingASlugAreSummedAndCountInTheTotal(): void
    {
        $counts = new SearchIndexRowCounts();
        $counts->add(slug: 'people', rows: 4);
        $counts->add(slug: 'cases', rows: 0);
        $counts->add(slug: 'people', rows: 6);

        $this->assertSame(['rowsInTables' => 10, 'indexed' => ['people' => 10, 'cases' => 0]], $counts->toArray());
    }//end testTablesSharingASlugAreSummedAndCountInTheTotal()

    public function testAnUncountedTableDropsTheTotalAndItsWholeSlug(): void
    {
        $counts = new SearchIndexRowCounts();
        $counts->add(slug: 'people', rows: 4);
        $counts->add(slug: 'people', rows: null);
        $counts->add(slug: 'cases', rows: 2);

        $this->assertSame(['indexed' => ['cases' => 2]], $counts->toArray());
    }//end testAnUncountedTableDropsTheTotalAndItsWholeSlug()

    public function testAnUncountedTableBeforeItsCountedTwinStillDropsTheSlug(): void
    {
        $counts = new SearchIndexRowCounts();
        $counts->add(slug: 'people', rows: null);
        $counts->add(slug: 'people', rows: 9);

        $this->assertSame(['indexed' => []], $counts->toArray());
    }//end testAnUncountedTableBeforeItsCountedTwinStillDropsTheSlug()
}//end class

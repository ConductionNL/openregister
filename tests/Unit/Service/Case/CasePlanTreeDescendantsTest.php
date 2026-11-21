<?php

/**
 * Behaviour tests for CasePlanTree: depth-first descendants and the plan-level settings.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
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

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Case\CasePlanTree
 * @uses   \OCA\OpenRegister\Db\CaseItem
 */
class CasePlanTreeDescendantsTest extends TestCase
{
    private static function item(int $id, ?int $parent, string $key, ?array $settings = null): CaseItem
    {
        $item = new CaseItem();
        $item->setId($id);
        $item->setParentItemId($parent);
        $item->setItemKey($key);
        $item->setPlanSettings($settings);
        return $item;
    }//end item()

    public function testDescendantsAreListedDepthFirstAndALeafHasNone(): void
    {
        $tree = new CasePlanTree(
            [
                self::item(1, null, 'intake'),
                self::item(2, 1, 'check'),
                self::item(3, 2, 'check-docs'),
                self::item(4, 1, 'decide'),
                self::item(5, 2, 'check-id'),
                self::item(6, null, 'close'),
            ]
        );

        $this->assertSame(
            ['check', 'check-docs', 'check-id', 'decide'],
            array_map(static fn(CaseItem $i): string => (string) $i->getItemKey(), $tree->descendants(1))
        );
        $this->assertSame([], $tree->descendants(4));
        $this->assertSame([], $tree->descendants(99));
    }//end testDescendantsAreListedDepthFirstAndALeafHasNone()

    public function testSettingsComeFromTheFirstRowThatCarriesThem(): void
    {
        $tree = new CasePlanTree(
            [
                self::item(1, null, 'a', []),
                self::item(2, null, 'b', ['allowAdHoc' => true]),
                self::item(3, null, 'c', ['allowAdHoc' => false]),
            ]
        );

        $this->assertSame(['allowAdHoc' => true], $tree->settings());
        $this->assertSame([], (new CasePlanTree([self::item(1, null, 'a')]))->settings());
    }//end testSettingsComeFromTheFirstRowThatCarriesThem()
}//end class

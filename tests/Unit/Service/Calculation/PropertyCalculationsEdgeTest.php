<?php

/**
 * Behaviour tests for PropertyCalculations edges: unusable properties and which errors block.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
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

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Calculation\PropertyCalculations
 */
class PropertyCalculationsEdgeTest extends TestCase
{
    public function testOnlyNamedPropertiesWithAnObjectDefinitionForwardACalculation(): void
    {
        $collected = (new PropertyCalculations())->fromProperties(
            [
                0       => ['calculation' => ['expression' => '1']],
                ''      => ['calculation' => ['expression' => '2']],
                'flag'  => 'not an object',
                'plain' => ['type' => 'string'],
                'total' => ['calculation' => ['expression' => 'a + b', 'materialise' => false]],
                'sum'   => ['calculation' => ['expression' => 'a']],
            ]
        );

        $this->assertSame(
            ['total' => ['expression' => 'a + b', 'materialise' => false], 'sum' => ['expression' => 'a', 'materialise' => true]],
            $collected
        );
    }//end testOnlyNamedPropertiesWithAnObjectDefinitionForwardACalculation()

    public function testAnIgnoredDependsOnNeverBlocksEvenWhenItNamesAForwardedCalculation(): void
    {
        $ignored = ['code' => 'calculation-dependson-ignored', 'message' => 'Calculation "total": dependsOn is ignored'];
        $other   = ['code' => 'calculation-invalid-expression', 'message' => 'Calculation "total": bad expression'];

        $this->assertSame([$other], (new PropertyCalculations())->blockingErrors([$ignored, $other], ['total']));
    }//end testAnIgnoredDependsOnNeverBlocksEvenWhenItNamesAForwardedCalculation()

    public function testADuplicateDeclarationAlwaysBlocksWhileAnUnrelatedErrorDoesNot(): void
    {
        $duplicate = ['code' => 'calculation-duplicate-declaration', 'message' => 'Calculation "elsewhere" is declared twice'];
        $unrelated = ['code' => 'calculation-invalid-expression', 'message' => 'Calculation "elsewhere": bad expression'];

        $this->assertSame([$duplicate], (new PropertyCalculations())->blockingErrors([$duplicate, $unrelated], ['total']));
    }//end testADuplicateDeclarationAlwaysBlocksWhileAnUnrelatedErrorDoesNot()
}//end class

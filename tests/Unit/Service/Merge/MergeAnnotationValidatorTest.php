<?php

/**
 * MergeAnnotationValidator unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#6.1
 */

declare(strict_types=1);

namespace Unit\Service\Merge;

use OCA\OpenRegister\Service\Merge\MergeAnnotationValidator;
use PHPUnit\Framework\TestCase;

class MergeAnnotationValidatorTest extends TestCase
{

    private MergeAnnotationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MergeAnnotationValidator();
    }//end setUp()

    public function testAbsentAnnotationIsValid(): void
    {
        $this->assertSame([], $this->validator->validate(['properties' => []]));
    }//end testAbsentAnnotationIsValid()

    public function testValidAnnotationWithDefaults(): void
    {
        $shape = ['x-openregister-merge' => []];
        $this->assertSame([], $this->validator->validate($shape));
    }//end testValidAnnotationWithDefaults()

    public function testValidFullAnnotation(): void
    {
        $shape = [
            'x-openregister-merge' => [
                'sourceLinkField'     => 'sources',
                'entityType'          => 'organisation',
                'reversalWindowDays'  => 30,
                'statusField'         => 'status',
                'survivorStatus'      => 'active',
                'mergedStatus'        => 'merged-into-other',
            ],
        ];
        $this->assertSame([], $this->validator->validate($shape));
    }//end testValidFullAnnotation()

    public function testNonObjectAnnotationIsInvalid(): void
    {
        $errors = $this->validator->validate(['x-openregister-merge' => 'not-an-object']);
        $this->assertNotEmpty($errors);
        $this->assertSame('merge.not-object', $errors[0]['code']);
    }//end testNonObjectAnnotationIsInvalid()

    public function testNonIntegerReversalWindowIsInvalid(): void
    {
        $errors = $this->validator->validate(['x-openregister-merge' => ['reversalWindowDays' => 'thirty']]);
        $this->assertNotEmpty($errors);
        $this->assertSame('merge.invalid-reversal-window', $errors[0]['code']);
    }//end testNonIntegerReversalWindowIsInvalid()

    public function testNegativeReversalWindowIsInvalid(): void
    {
        $errors = $this->validator->validate(['x-openregister-merge' => ['reversalWindowDays' => -5]]);
        $this->assertNotEmpty($errors);
        $this->assertSame('merge.invalid-reversal-window', $errors[0]['code']);
    }//end testNegativeReversalWindowIsInvalid()

    public function testZeroReversalWindowIsInvalid(): void
    {
        $errors = $this->validator->validate(['x-openregister-merge' => ['reversalWindowDays' => 0]]);
        $this->assertNotEmpty($errors);
        $this->assertSame('merge.invalid-reversal-window', $errors[0]['code']);
    }//end testZeroReversalWindowIsInvalid()

    public function testNonStringFieldIsInvalid(): void
    {
        $errors = $this->validator->validate(['x-openregister-merge' => ['sourceLinkField' => 123]]);
        $this->assertNotEmpty($errors);
        $this->assertSame('merge.field-not-string', $errors[0]['code']);
    }//end testNonStringFieldIsInvalid()

    public function testMultipleErrorsAreAllReported(): void
    {
        $errors = $this->validator->validate(
            [
                'x-openregister-merge' => [
                    'reversalWindowDays' => -1,
                    'statusField'        => 123,
                    'survivorStatus'     => true,
                ],
            ]
        );
        $this->assertGreaterThanOrEqual(3, count($errors));
    }//end testMultipleErrorsAreAllReported()

    public function testWellFormedReverseFkSourceLinkIsAccepted(): void
    {
        $errors = $this->validator->validate(
            [
                'x-openregister-merge' => [
                    'sourceLink' => [
                        'mode'           => 'reverseFk',
                        'sourceSchema'   => 'sourceRecord',
                        'referenceField' => 'currentMasterEntity',
                    ],
                ],
            ]
        );
        $this->assertSame([], $errors);
    }//end testWellFormedReverseFkSourceLinkIsAccepted()

    public function testIncompleteReverseFkSourceLinkIsFlagged(): void
    {
        $errors = $this->validator->validate(
            [
                'x-openregister-merge' => [
                    'sourceLink' => ['mode' => 'reverseFk', 'referenceField' => 'currentMasterEntity'],
                ],
            ]
        );
        $codes = array_column($errors, 'code');
        $this->assertContains('merge.source-link-reverse-fk-incomplete', $codes);
    }//end testIncompleteReverseFkSourceLinkIsFlagged()
}//end class

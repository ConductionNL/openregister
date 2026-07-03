<?php

/**
 * SurvivorshipAnnotationValidator unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survivorship
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#7.2
 */

declare(strict_types=1);

namespace Unit\Service\Survivorship;

use OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator;
use PHPUnit\Framework\TestCase;

class SurvivorshipAnnotationValidatorTest extends TestCase
{

    private SurvivorshipAnnotationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SurvivorshipAnnotationValidator();
    }//end setUp()

    public function testAbsentAnnotationIsValid(): void
    {
        $this->assertSame([], $this->validator->validate(['properties' => []]));
    }//end testAbsentAnnotationIsValid()

    public function testValidAnnotationWithDefaults(): void
    {
        $shape = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
            ],
        ];
        $this->assertSame([], $this->validator->validate($shape));
    }//end testValidAnnotationWithDefaults()

    public function testValidFullAnnotation(): void
    {
        $shape = [
            'x-openregister-survivorship' => [
                'sourceLinkField'      => 'sources',
                'goldenRecordField'    => 'goldenRecord',
                'provenanceField'      => 'attributeProvenance',
                'tierOrder'            => ['discard', 'bronze', 'silver', 'gold'],
                'defaultTier'          => 'bronze',
                'discardTier'          => 'discard',
                'freshnessAnchorField' => 'lastUpdated',
                'tieBreak'             => 'mostRecentUpdate',
                'trustLookup'          => ['keys' => ['entityType', 'attribute', 'sourceSystem']],
            ],
        ];
        $this->assertSame([], $this->validator->validate($shape));
    }//end testValidFullAnnotation()

    public function testNotAnObjectIsInvalid(): void
    {
        $errors = $this->validator->validate(['x-openregister-survivorship' => 'nope']);
        $this->assertSame('survivorship.not-object', $errors[0]['code']);
    }//end testNotAnObjectIsInvalid()

    public function testMissingSourceLinkFieldIsInvalid(): void
    {
        $errors = $this->validator->validate(['x-openregister-survivorship' => []]);
        $codes  = array_column($errors, 'code');
        $this->assertContains('survivorship.missing-source-link-field', $codes);
    }//end testMissingSourceLinkFieldIsInvalid()

    public function testNonArrayTierOrderIsInvalid(): void
    {
        $shape  = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
                'tierOrder'       => 'not-an-array',
            ],
        ];
        $errors = $this->validator->validate($shape);
        $this->assertSame('survivorship.tier-order-not-array', $errors[array_key_last($errors)]['code']);
    }//end testNonArrayTierOrderIsInvalid()

    public function testDefaultTierNotInTierOrderIsInvalid(): void
    {
        $shape  = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
                'tierOrder'       => ['bronze', 'silver', 'gold'],
                'defaultTier'     => 'platinum',
            ],
        ];
        $errors = $this->validator->validate($shape);
        $codes  = array_column($errors, 'code');
        $this->assertContains('survivorship.default-tier-not-in-order', $codes);
    }//end testDefaultTierNotInTierOrderIsInvalid()

    public function testDiscardTierNotInTierOrderIsInvalid(): void
    {
        $shape  = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
                'tierOrder'       => ['bronze', 'silver', 'gold'],
                'discardTier'     => 'trash',
            ],
        ];
        $errors = $this->validator->validate($shape);
        $codes  = array_column($errors, 'code');
        $this->assertContains('survivorship.discard-tier-not-in-order', $codes);
    }//end testDiscardTierNotInTierOrderIsInvalid()

    public function testTrustLookupNotObjectIsInvalid(): void
    {
        $shape  = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
                'trustLookup'     => 'nope',
            ],
        ];
        $errors = $this->validator->validate($shape);
        $codes  = array_column($errors, 'code');
        $this->assertContains('survivorship.trust-lookup-not-object', $codes);
    }//end testTrustLookupNotObjectIsInvalid()

    public function testTrustLookupEmptyKeysIsInvalid(): void
    {
        $shape  = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
                'trustLookup'     => ['keys' => []],
            ],
        ];
        $errors = $this->validator->validate($shape);
        $codes  = array_column($errors, 'code');
        $this->assertContains('survivorship.trust-lookup-keys-invalid', $codes);
    }//end testTrustLookupEmptyKeysIsInvalid()

    public function testOverridesFieldAbsentIsValid(): void
    {
        $shape = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
            ],
        ];
        $this->assertSame([], $this->validator->validate($shape));
    }//end testOverridesFieldAbsentIsValid()

    public function testOverridesFieldValidStringIsValid(): void
    {
        $shape = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
                'overridesField'  => 'attributeOverrides',
            ],
        ];
        $this->assertSame([], $this->validator->validate($shape));
    }//end testOverridesFieldValidStringIsValid()

    public function testOverridesFieldNonStringIsInvalid(): void
    {
        $shape  = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
                'overridesField'  => ['nope'],
            ],
        ];
        $errors = $this->validator->validate($shape);
        $codes  = array_column($errors, 'code');
        $this->assertContains('survivorship.overrides-field-invalid', $codes);
    }//end testOverridesFieldNonStringIsInvalid()

    public function testOverridesFieldEmptyStringIsInvalid(): void
    {
        $shape  = [
            'x-openregister-survivorship' => [
                'sourceLinkField' => 'sources',
                'overridesField'  => '',
            ],
        ];
        $errors = $this->validator->validate($shape);
        $codes  = array_column($errors, 'code');
        $this->assertContains('survivorship.overrides-field-invalid', $codes);
    }//end testOverridesFieldEmptyStringIsInvalid()
}//end class

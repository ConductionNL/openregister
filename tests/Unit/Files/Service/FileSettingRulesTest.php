<?php

/**
 * The file setting rules port answers exactly what the static rules answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service;

use OCA\OpenRegister\Exception\UnknownMaskFormException;
use OCA\OpenRegister\Files\Service\File\MaskForm;
use OCA\OpenRegister\Files\Service\FileSettingRules;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\JurisdictionPatternSets;
use OCA\OpenRegister\Kernel\Contracts\Files\FileSettingRulesInterface;
use OCA\OpenRegister\Service\File\ArchiveBounds;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Service\FileSettingRules
 * @uses   \OCA\OpenRegister\Files\Service\File\MaskForm
 * @uses   \OCA\OpenRegister\Service\File\ArchiveBounds
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\JurisdictionPatternSets
 */
class FileSettingRulesTest extends TestCase
{

    private FileSettingRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new FileSettingRules();
    }//end setUp()

    public function testItImplementsThePort(): void
    {
        $this->assertInstanceOf(FileSettingRulesInterface::class, $this->rules);
    }//end testItImplementsThePort()

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function maskForms(): array
    {
        return [
            'empty'       => [[]],
            'placeholder' => [['PERSON' => ['form' => MaskForm::PLACEHOLDER]]],
            'first two'   => [['EMAIL' => ['form' => MaskForm::FIRST, 'n' => 2], 'PHONE' => ['form' => MaskForm::LAST, 'n' => 3]]],
            'shorthand'   => [['IBAN' => MaskForm::GENERALISE]],
        ];
    }//end maskForms()

    /**
     * @dataProvider maskForms
     *
     * @param array<mixed> $maskForms The submitted value.
     */
    public function testMaskFormsAreNormalisedAsMaskFormDoes(array $maskForms): void
    {
        try {
            $expected = MaskForm::normalise(maskForms: $maskForms);
        } catch (UnknownMaskFormException $e) {
            $this->expectException(UnknownMaskFormException::class);
            $this->expectExceptionMessage($e->getMessage());
            $this->rules->normaliseMaskForms(maskForms: $maskForms);
            return;
        }

        $this->assertSame($expected, $this->rules->normaliseMaskForms(maskForms: $maskForms));
    }//end testMaskFormsAreNormalisedAsMaskFormDoes()

    public function testAnUnknownMaskFormIsRefusedAsMaskFormRefusesIt(): void
    {
        $this->expectException(UnknownMaskFormException::class);
        $this->rules->normaliseMaskForms(maskForms: ['PERSON' => ['form' => 'scramble']]);
    }//end testAnUnknownMaskFormIsRefusedAsMaskFormRefusesIt()

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function fileSettings(): array
    {
        return [
            'none'        => [[]],
            'not a block' => [['archive' => 'x']],
            'partial'     => [['archive' => ['maxMembers' => '50']]],
            'deep'        => [['archive' => ['maxDepth' => 9, 'maxRatio' => 0, 'maxTotalBytes' => 10]]],
        ];
    }//end fileSettings()

    /**
     * @dataProvider fileSettings
     *
     * @param array<string, mixed> $fileSettings The file settings.
     */
    public function testArchiveBoundsAreReadAsArchiveBoundsReadsThem(array $fileSettings): void
    {
        $this->assertSame(
            ArchiveBounds::fromSettings(fileSettings: $fileSettings)->toArray(),
            $this->rules->archiveBounds(fileSettings: $fileSettings)
        );
    }//end testArchiveBoundsAreReadAsArchiveBoundsReadsThem()

    public function testTheArchiveDefaultsAreTheArchiveBoundsDefaults(): void
    {
        $this->assertSame(ArchiveBounds::DEFAULTS, $this->rules->archiveDefaults());
    }//end testTheArchiveDefaultsAreTheArchiveBoundsDefaults()

    public function testThePatternSetCodesAreTheRegistryCodes(): void
    {
        $this->assertSame(JurisdictionPatternSets::codes(), $this->rules->patternSetCodes());
    }//end testThePatternSetCodesAreTheRegistryCodes()

    public function testUnknownPatternSetsAreFoundAsTheRegistryFindsThem(): void
    {
        $codes = array_merge(JurisdictionPatternSets::codes(), ['xx', 3, '']);

        $this->assertSame(JurisdictionPatternSets::unknown(codes: $codes), $this->rules->unknownPatternSets(codes: $codes));
        $this->assertSame([], $this->rules->unknownPatternSets(codes: JurisdictionPatternSets::codes()));
    }//end testUnknownPatternSetsAreFoundAsTheRegistryFindsThem()
}//end class

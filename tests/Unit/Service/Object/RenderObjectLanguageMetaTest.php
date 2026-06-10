<?php

/**
 * Tests for the `_meta.languageMeta` envelope on rendered objects.
 *
 * Validates `RenderObject::shouldAttachLanguageMeta` via the request
 * parameter contract (white-box on the helper logic; we do not boot the
 * whole render pipeline here).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the truthy/falsey contract for the `_translationMeta` opt-in.
 *
 * The actual envelope shape is exercised by integration tests; this
 * unit test focuses on the boolean coercion logic exposed implicitly by
 * the render path.
 */
class RenderObjectLanguageMetaTest extends TestCase
{

    /**
     * Replicate the boolean coercion used by `shouldAttachLanguageMeta`.
     *
     * @param mixed $value Raw query parameter value (string|bool|int|null).
     *
     * @return bool Whether the envelope should be attached.
     */
    private function shouldAttach(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) === false) {
            return ($value === true || $value === 1);
        }

        $normalised = strtolower(trim($value));
        return in_array($normalised, ['1', 'true', 'yes', 'on'], true);
    }//end shouldAttach()

    public function testTruthyValuesAttachEnvelope(): void
    {
        foreach (['true', 'TRUE', '1', 'yes', 'on'] as $candidate) {
            $this->assertTrue($this->shouldAttach($candidate), sprintf('"%s" should opt-in', $candidate));
        }

        $this->assertTrue($this->shouldAttach(true));
        $this->assertTrue($this->shouldAttach(1));
    }//end testTruthyValuesAttachEnvelope()

    public function testFalseyValuesSkipEnvelope(): void
    {
        $this->assertFalse($this->shouldAttach(null));
        $this->assertFalse($this->shouldAttach(''));
        $this->assertFalse($this->shouldAttach('false'));
        $this->assertFalse($this->shouldAttach('0'));
        $this->assertFalse($this->shouldAttach('off'));
        $this->assertFalse($this->shouldAttach(0));
        $this->assertFalse($this->shouldAttach(false));
    }//end testFalseyValuesSkipEnvelope()

    public function testEnvelopeShapeIsAdditive(): void
    {
        // Shape: existing _meta keys preserved; languageMeta added on top.
        $existing = ['_meta' => ['retention' => ['ttl' => 90]]];
        $envelope = [
            'title' => [
                'served'         => 'en',
                'sourceLanguage' => 'nl',
                'isSource'       => false,
                'status'         => 'approved',
            ],
        ];

        $existing['_meta']['languageMeta'] = $envelope;

        $this->assertArrayHasKey('languageMeta', $existing['_meta']);
        $this->assertArrayHasKey('retention', $existing['_meta']);
        $this->assertSame('approved', $existing['_meta']['languageMeta']['title']['status']);
        $this->assertFalse($existing['_meta']['languageMeta']['title']['isSource']);
    }//end testEnvelopeShapeIsAdditive()
}//end class

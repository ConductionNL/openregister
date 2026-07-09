<?php

/**
 * Unit tests for the MDI icon renderer used to surface a schema's icon in
 * Nextcloud unified search.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\MdiIconRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Targets MdiIconRenderer::has(), svg() and dataUri().
 */
class MdiIconRendererTest extends TestCase
{


    /**
     * A curated icon name resolves regardless of case, prefix or separators.
     *
     * @return void
     */
    public function testNormalisesNameVariants(): void
    {
        foreach (['Dog', 'dog', 'mdi-dog', 'mdiDog', 'MDI_DOG'] as $variant) {
            $this->assertTrue(MdiIconRenderer::has(icon: $variant), $variant.' should resolve');
            $this->assertNotNull(MdiIconRenderer::svg(icon: $variant), $variant.' should render');
        }

    }//end testNormalisesNameVariants()


    /**
     * svg() returns a 24×24 SVG document carrying the glyph path.
     *
     * @return void
     */
    public function testSvgIsWellFormed(): void
    {
        $svg = MdiIconRenderer::svg(icon: 'Account');

        $this->assertIsString($svg);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
        $this->assertStringContainsString('<path', $svg);
        $this->assertStringEndsWith('</svg>', $svg);

    }//end testSvgIsWellFormed()


    /**
     * dataUri() wraps the SVG as a base64 data URI whose payload is the SVG.
     *
     * @return void
     */
    public function testDataUriEncodesTheSvg(): void
    {
        $uri = MdiIconRenderer::dataUri(icon: 'Stethoscope');

        $this->assertIsString($uri);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);

        $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($decoded);
        $this->assertSame(MdiIconRenderer::svg(icon: 'Stethoscope'), $decoded);

    }//end testDataUriEncodesTheSvg()


    /**
     * Unknown, empty and null names resolve to nothing so the caller can fall
     * back to its own icon, and never throw.
     *
     * @return void
     */
    public function testUnknownAndEmptyReturnNothing(): void
    {
        foreach (['Nonexistent', '', null, '   ', '!!!'] as $bad) {
            $this->assertFalse(MdiIconRenderer::has(icon: $bad));
            $this->assertNull(MdiIconRenderer::svg(icon: $bad));
            $this->assertNull(MdiIconRenderer::dataUri(icon: $bad));
        }

    }//end testUnknownAndEmptyReturnNothing()


}//end class

<?php

/**
 * Golden test pinning how MdiIconRenderer resolves icon references.
 *
 * Recorded on the renderer before resolvePath() was split: which reference
 * resolves to which glyph, which resolve to nothing, and the exact rendered
 * markup of the whole curated set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\MdiIconRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Golden outcomes of MdiIconRenderer.
 */
class MdiIconRendererGoldenTest extends TestCase {

	/**
	 * References and the curated key they resolve to (null: none).
	 *
	 * @return array<string, array{0: string|null, 1: string|null}>
	 */
	public static function references(): array {
		return [
			'null'             => [null, null],
			'empty'            => ['', null],
			'bare prefix'      => ['mdi', null],
			'upper prefix'     => ['MDI', null],
			'prefix dash'      => ['mdi-', null],
			'title case'       => ['Dog', 'dog'],
			'kebab prefix'     => ['mdi-dog', 'dog'],
			'camel prefix'     => ['mdiDog', 'dog'],
			'upper snake'      => ['MDI_DOG', 'dog'],
			'punctuation'      => ['d-o-g!', 'dog'],
			'spaces'           => [' dog ', 'dog'],
			'prefix once only' => ['mdimdidog', null],
			'spaced prefix'    => ['mdi dog', 'dog'],
			'plural unknown'   => ['dogs', null],
			'digit'            => ['0', null],
		];
	}//end references()

	/**
	 * Each reference renders exactly the glyph of its key, or nothing.
	 *
	 * @param string|null $icon The reference.
	 * @param string|null $key  The curated key, or null.
	 *
	 * @dataProvider references
	 *
	 * @return void
	 */
	public function testReferenceResolution(?string $icon, ?string $key): void {
		$this->assertSame($key !== null, MdiIconRenderer::has(icon: $icon));
		if ($key === null) {
			$this->assertNull(MdiIconRenderer::svg(icon: $icon));
			$this->assertNull(MdiIconRenderer::dataUri(icon: $icon));
			return;
		}

		$this->assertSame(MdiIconRenderer::svg(icon: $key), MdiIconRenderer::svg(icon: $icon));
	}//end testReferenceResolution()

	/**
	 * The markup of every curated glyph, reached through its prefixed name, is unchanged.
	 *
	 * @return void
	 */
	public function testWholeCuratedSetRendersIdentically(): void {
		$keys = array_keys((new ReflectionClassConstant(MdiIconRenderer::class, 'PATHS'))->getValue());
		$uris = array_map(static fn (string $key): ?string => MdiIconRenderer::dataUri(icon: 'mdi-' . $key), $keys);

		$this->assertCount(17, $keys);
		$this->assertNotContains(null, $uris);
		$this->assertSame('dd9acc0c27247c38c096f2f23f9b3fb49bbce8e5', sha1(implode('|', $uris)));
	}//end testWholeCuratedSetRendersIdentically()
}//end class

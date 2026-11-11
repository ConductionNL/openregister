<?php

/**
 * FlowValueRenderer: a list or object placed inside a longer string renders as its JSON text, a scalar as its string.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowValueRenderer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowValueRenderer
 */
class FlowValueRendererInlineArrayBehaviourTest extends TestCase {

	public function testAnArrayInsideAStringRendersAsJson(): void {
		$json = ['tags' => ['a', 'b'], 'owner' => ['uid' => 'pieter'], 'n' => 3];

		self::assertSame('Tags: ["a","b"] by {"uid":"pieter"} (3)', FlowValueRenderer::render('Tags: {{ tags }} by {{ owner }} ({{ n }})', $json));
		self::assertSame(['a', 'b'], FlowValueRenderer::render('{{ tags }}', $json), 'a whole-value template keeps the array');
	}//end testAnArrayInsideAStringRendersAsJson()
}//end class

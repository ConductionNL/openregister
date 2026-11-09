<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\Bindings\RootBindings;
use OCA\OpenRegister\Service\MdiIconSvg;
use OCA\OpenRegister\Service\MdiIconSvgInterface;
use PHPUnit\Framework\TestCase;

/**
 * Lane K27: every interface it introduced is bound in its component's
 * registrar map, to a class that implements it, and the outside caller
 * names only the interface.
 */
final class K27InterfaceBindingsTest extends TestCase {

	private const LIB = __DIR__ . '/../../../lib';

	public function testMdiIconSvgInterfaceIsBoundInRootBindings(): void {
		$this->assertArrayHasKey(MdiIconSvgInterface::class, RootBindings::BINDINGS);
		$this->assertSame(MdiIconSvg::class, RootBindings::BINDINGS[MdiIconSvgInterface::class]);
	}

	public function testMdiIconSvgImplementsItsInterface(): void {
		$this->assertTrue(is_subclass_of(MdiIconSvg::class, MdiIconSvgInterface::class));
	}

	public function testIconControllerNamesOnlyTheInterface(): void {
		$source = (string) file_get_contents(self::LIB . '/Controller/IconController.php');
		$this->assertDoesNotMatchRegularExpression('/\bMdiIconSvg\b/', $source);
		$this->assertStringContainsString('MdiIconSvgInterface', $source);
	}
}

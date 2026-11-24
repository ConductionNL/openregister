<?php

/**
 * ObjectPreviewInterface is answered by the object preview formatter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\AppHost\Reference\AbstractSchemaReferenceProvider;
use OCA\OpenRegister\Kernel\Contracts\Integrations\ObjectPreviewInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatterInterface;
use PHPUnit\Framework\TestCase;

/**
 * The schema-scoped reference providers ask the Integrations port; the formatter answers both names.
 */
final class ObjectPreviewBindingTest extends TestCase {

	public function testThePortAndTheFormatterInterfaceResolveToTheSameFormatter(): void {
		$this->assertSame(ObjectPreviewFormatter::class, ContractBindings::BINDINGS[ObjectPreviewInterface::class] ?? null);
		$this->assertSame(ObjectPreviewFormatter::class, ContractBindings::BINDINGS[ObjectPreviewFormatterInterface::class] ?? null);
	}

	public function testTheFormatterInterfaceIsThePort(): void {
		$this->assertTrue(is_subclass_of(ObjectPreviewFormatterInterface::class, ObjectPreviewInterface::class));
		$this->assertTrue(is_subclass_of(ObjectPreviewFormatter::class, ObjectPreviewInterface::class));
	}

	public function testTheFormatterInterfaceKeepsEveryMethodItHad(): void {
		$methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(ObjectPreviewFormatterInterface::class))->getMethods());
		sort($methods);

		$this->assertSame(['buildReference', 'getAppIconUrl', 'parseReference', 'resolveCachePrefix', 'resolveMdiIconUrl'], $methods);
	}

	public function testALeafProviderStillAcceptsTheFormatterInterface(): void {
		$parameter = (new \ReflectionMethod(AbstractSchemaReferenceProvider::class, '__construct'))->getParameters()[0];

		$this->assertSame('formatter', $parameter->getName());
		$this->assertSame(ObjectPreviewInterface::class, (string) $parameter->getType());
		$this->assertTrue(is_subclass_of(ObjectPreviewFormatterInterface::class, (string) $parameter->getType()));
	}
}//end class

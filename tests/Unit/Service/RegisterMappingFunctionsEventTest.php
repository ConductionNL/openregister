<?php

/**
 * RegisterMappingFunctionsEvent: functions apps register for mapping templates, and the allowlist of names it yields.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\RegisterMappingFunctionsEvent;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

/**
 * @covers \OCA\OpenRegister\Service\RegisterMappingFunctionsEvent
 */
class RegisterMappingFunctionsEventTest extends TestCase {

	public function testANewEventOffersNoFunctions(): void {
		$event = new RegisterMappingFunctionsEvent();

		$this->assertSame([], $event->getFunctions());
		$this->assertSame([], $event->getAllowedNames());
	}

	public function testRegisteredFunctionsAreKeptInOrderAndTheirNamesAllowed(): void {
		$event = new RegisterMappingFunctionsEvent();
		$upper = new TwigFunction('or_upper', 'strtoupper');
		$lower = new TwigFunction('or_lower', 'strtolower');
		$event->registerFunction($upper);
		$event->registerFunction($lower);

		$this->assertSame([$upper, $lower], $event->getFunctions());
		$this->assertSame(['or_upper', 'or_lower'], $event->getAllowedNames());
	}
}

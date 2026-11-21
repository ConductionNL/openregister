<?php

/**
 * The aggregation runner reads the request's translation mode through the Core port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
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

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Kernel\Contracts\Core\RequestLanguagesInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\PlatformServiceBindings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The runner types the port; the shared language service answers it.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationRunner
 * @covers \OCA\OpenRegister\Service\LanguageService
 */
final class AggregationRunnerLanguagePortTest extends TestCase {

	public function testTheRunnerTakesThePort(): void {
		$parameter = null;
		foreach ((new ReflectionMethod(AggregationRunner::class, '__construct'))->getParameters() as $candidate) {
			if ($candidate->getName() === 'languageService') {
				$parameter = $candidate;
			}
		}

		$this->assertNotNull($parameter);
		$type = $parameter->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(RequestLanguagesInterface::class, $type->getName());
		$this->assertFalse($type->allowsNull());
	}

	public function testThePortResolvesToTheSharedLanguageService(): void {
		$this->assertSame(LanguageService::class, CoreServiceBindings::BINDINGS[RequestLanguagesInterface::class]);
		$this->assertArrayNotHasKey(RequestLanguagesInterface::class, PlatformServiceBindings::BINDINGS);
	}

	public function testThePortCarriesTheTranslationMode(): void {
		$languages = new LanguageService();
		$port      = $languages;
		$this->assertInstanceOf(RequestLanguagesInterface::class, $port);
		$this->assertFalse($port->shouldReturnAllTranslations());

		$languages->setReturnAllTranslations(true);
		$this->assertTrue($port->shouldReturnAllTranslations());
	}
}//end class

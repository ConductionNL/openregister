<?php

/**
 * The save path's two translation ports are answered by the translation
 * projection and status services, bound in Configuration's own bindings.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Kernel\Contracts\Core\DerivedTranslationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SourceLanguagesInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\Object\SaveObject\OutdatedTranslations;
use OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SaveTranslationPortsBindingTest extends TestCase {

	public function testTheProjectionAnswersTheSourceLanguagePort(): void {
		self::assertTrue(is_subclass_of(TranslationProjectionService::class, SourceLanguagesInterface::class));
		self::assertSame(TranslationProjectionService::class, ContractBindings::BINDINGS[SourceLanguagesInterface::class] ?? null);
	}

	public function testTheStatusServiceAnswersTheDerivedTranslationsPort(): void {
		self::assertTrue(is_subclass_of(TranslationStatusService::class, DerivedTranslationsInterface::class));
		self::assertSame(TranslationStatusService::class, CoreServiceBindings::BINDINGS[DerivedTranslationsInterface::class] ?? null);
	}

	public function testTheSavePathTypesAgainstThePorts(): void {
		foreach ([SaveObjectValueHandlers::class, OutdatedTranslations::class] as $class) {
			$types = [];
			foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
				$types[] = (string) $parameter->getType();
			}

			self::assertContains(SourceLanguagesInterface::class, $types, $class);
			self::assertContains(DerivedTranslationsInterface::class, $types, $class);
		}
	}
}

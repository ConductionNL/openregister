<?php

/**
 * File redaction asks its own port for the detection and masking settings; Configuration answers it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
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

use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Kernel\Contracts\Files\RedactionSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\CapacityPolicy;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The port is bound by Configuration to the handler that already answered, and the Files classes type against it.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentRedactionServices
 */
final class RedactionSettingsPortTest extends TestCase {

	public function testConfigurationBindsThePortToTheFileSettingsHandler(): void {
		$this->assertSame(FileSettingsHandler::class, ContractBindings::BINDINGS[RedactionSettingsInterface::class]);
		$this->assertTrue(is_subclass_of(FileSettingsHandler::class, RedactionSettingsInterface::class));
	}

	public function testTheFilesClassesTypeAgainstThePort(): void {
		$this->assertSame(RedactionSettingsInterface::class, $this->parameterType(class: DocumentRedactionServices::class, name: 'fileSettings'));
		$this->assertSame(RedactionSettingsInterface::class, $this->parameterType(class: EntityRecognitionHandler::class, name: 'fileSettings'));
	}

	public function testThePortsAreOptionalAsBefore(): void {
		foreach ([DocumentRedactionServices::class, EntityRecognitionHandler::class] as $class) {
			foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
				if ($parameter->getName() === 'fileSettings') {
					$this->assertTrue($parameter->allowsNull());
					$this->assertNull($parameter->getDefaultValue());
				}
			}
		}
	}

	public function testTheDefaultThresholdKeepsItsValueAndItsHandlerName(): void {
		$this->assertSame(0.5, RedactionSettingsInterface::DEFAULT_CONFIDENCE_THRESHOLD);
		$this->assertSame(0.5, FileSettingsHandler::DEFAULT_CONFIDENCE_THRESHOLD);
	}

	public function testTheRedactionBagCarriesWhateverAnswersThePort(): void {
		$settings = new class implements RedactionSettingsInterface {
			public function confidenceThreshold(): float {
				return 0.8;
			}

			public function maskForms(): array {
				return ['PERSON' => ['form' => 'initials']];
			}

			public function capacityPolicy(): CapacityPolicy {
				return CapacityPolicy::fromStored(stored: null);
			}

			public function officialsSource(): ?array {
				return null;
			}

			public function personRegister(): ?array {
				return null;
			}

			public function personRegisterBoost(): float {
				return 0.0;
			}

			public function placeRegister(): ?array {
				return null;
			}
		};

		$bag = new DocumentRedactionServices(
			entityRelationMapper: $this->createMock(EntityRelationMapperInterface::class),
			sanitizer: $this->createMock(OfficeDocumentSanitizer::class),
			fileSettings: $settings
		);

		$this->assertSame($settings, $bag->fileSettings);
		$this->assertSame(['PERSON' => ['form' => 'initials']], $bag->fileSettings?->maskForms());
	}

	/**
	 * The declared type of one constructor parameter.
	 *
	 * @param class-string $class The class.
	 * @param string       $name  The parameter name.
	 *
	 * @return string The type name.
	 */
	private function parameterType(string $class, string $name): string {
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if ($parameter->getName() === $name && $type instanceof ReflectionNamedType) {
				return $type->getName();
			}
		}

		return '';
	}
}//end class

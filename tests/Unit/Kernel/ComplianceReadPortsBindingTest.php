<?php

/**
 * The archival services and the anonymisation backend ask ports; Configuration and Integrations answer them.
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

use OCA\OpenRegister\Kernel\Contracts\Compliance\ArchivalSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\ConnectionStatusReporterInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileSettingsReaderInterface;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendCollaborators;
use OCA\OpenRegister\Service\Archival\DestructionListCreator;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Connection\ConnectionReporterInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Each port is bound by the area of the class that answers it, and that class still answers the old names.
 */
final class ComplianceReadPortsBindingTest extends TestCase {

	public function testConfigurationBindsTheSettingsPortsToTheHandlersThatAlreadyAnswerThem(): void {
		$this->assertSame(ObjectRetentionHandler::class, ContractBindings::BINDINGS[ArchivalSettingsInterface::class]);
		$this->assertSame(ObjectRetentionHandler::class, ContractBindings::BINDINGS[ArchivalSettingsReaderInterface::class]);
		$this->assertSame(FileSettingsHandler::class, ContractBindings::BINDINGS[FileSettingsReaderInterface::class]);
	}

	public function testIntegrationsBindsTheConnectionReportPortToTheReporter(): void {
		$this->assertSame(ConnectionReporter::class, IntegrationWiring::BINDINGS[ConnectionStatusReporterInterface::class]);
	}

	public function testTheExistingContractsAreThePorts(): void {
		$this->assertTrue(is_subclass_of(ArchivalSettingsReaderInterface::class, ArchivalSettingsInterface::class));
		$this->assertTrue(is_subclass_of(ObjectRetentionHandler::class, ArchivalSettingsInterface::class));
		$this->assertTrue(is_subclass_of(ConnectionReporterInterface::class, ConnectionStatusReporterInterface::class));
		$this->assertTrue(is_subclass_of(ConnectionReporter::class, ConnectionStatusReporterInterface::class));
		$this->assertTrue(is_subclass_of(FileSettingsHandler::class, FileSettingsReaderInterface::class));
	}

	public function testTheComplianceClassesTypeAgainstThePorts(): void {
		$this->assertSame(ArchivalSettingsInterface::class, $this->parameterType(class: DestructionListCreator::class, name: 'settingsHandler'));
		$this->assertSame(FileSettingsReaderInterface::class, $this->parameterType(class: AnonymisationBackendCollaborators::class, name: 'fileSettingsHandler'));
		$this->assertSame(ConnectionStatusReporterInterface::class, $this->parameterType(class: AnonymisationBackendCollaborators::class, name: 'connectionReporter'));
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

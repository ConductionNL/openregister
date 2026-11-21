<?php

/**
 * The register-folder and file-size ports are bound to the services behind their Files interfaces.
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

use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Kernel\Contracts\Core\EntityFolderInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileSizeTotalsInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationUsageService;
use OCA\OpenRegister\Service\RegisterServiceHelpers;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Core asks its own ports; the service behind the Files interface answers both names.
 */
final class FileStoragePortsBindingTest extends TestCase {

	public function testTheEntityFolderPortResolvesToTheFolderService(): void {
		$this->assertSame(FileService::class, ContractBindings::BINDINGS[EntityFolderInterface::class]);
		$this->assertSame(ContractBindings::BINDINGS[FileFolderServiceInterface::class], ContractBindings::BINDINGS[EntityFolderInterface::class]);
		$this->assertTrue(is_subclass_of(FileFolderServiceInterface::class, EntityFolderInterface::class));
	}

	public function testTheFileSizePortResolvesToTheFileMapper(): void {
		$this->assertSame(FileMapper::class, FilesRegistrar::BINDINGS[FileSizeTotalsInterface::class]);
		$this->assertSame(FilesRegistrar::BINDINGS[FileMapperInterface::class], FilesRegistrar::BINDINGS[FileSizeTotalsInterface::class]);
		$this->assertTrue(is_subclass_of(FileMapperInterface::class, FileSizeTotalsInterface::class));
	}

	public function testThePortsHoldOnlyWhatCoreAsks(): void {
		$this->assertSame(['createEntityFolder', 'deleteRegisterFolder'], $this->methodNames(EntityFolderInterface::class));
		$this->assertSame(['sumSizesByFileIds'], $this->methodNames(FileSizeTotalsInterface::class));
	}

	public function testTheCoreServicesAskThePorts(): void {
		$this->assertSame(EntityFolderInterface::class, $this->parameterType(RegisterServiceHelpers::class, 'fileService'));
		$this->assertSame(FileSizeTotalsInterface::class, $this->parameterType(OrganisationUsageService::class, 'fileMapper'));
	}

	/**
	 * The method names of an interface, in declaration order.
	 *
	 * @param class-string $interface The interface.
	 *
	 * @return list<string>
	 */
	private function methodNames(string $interface): array {
		return array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($interface))->getMethods());
	}

	/**
	 * The declared type of one constructor parameter.
	 *
	 * @param class-string $class The class.
	 * @param string       $name  The parameter name.
	 *
	 * @return string
	 */
	private function parameterType(string $class, string $name): string {
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			if ($parameter->getName() === $name) {
				return (string) $parameter->getType();
			}
		}

		return '';
	}
}//end class

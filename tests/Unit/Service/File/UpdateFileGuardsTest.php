<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/*
 * Unit test: UpdateFileGuards reaches openregister's file account through the Core port.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileAccountInterface;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\UpdateFileGuards;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @covers \OCA\OpenRegister\Service\File\UpdateFileGuards
 */
final class UpdateFileGuardsTest extends TestCase {

	public function testTheGuardsTypeTheOwnershipTransferAgainstTheCorePort(): void {
		$parameters = (new ReflectionClass(UpdateFileGuards::class))->getConstructor()->getParameters();
		$type = $parameters[1]->getType();

		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(FileAccountInterface::class, $type->getName());
	}//end testTheGuardsTypeTheOwnershipTransferAgainstTheCorePort()

	public function testTheGuardsHoldTheCollaboratorsTheyAreGiven(): void {
		$validation = $this->createMock(FileValidationHandler::class);
		$account = $this->createMock(FileAccountInterface::class);

		$guards = new UpdateFileGuards($validation, $account);

		$this->assertSame($validation, $guards->fileValidHandler);
		$this->assertSame($account, $guards->fileOwnershipHandler);
	}//end testTheGuardsHoldTheCollaboratorsTheyAreGiven()

	public function testThePortResolvesToTheSameHandlerAsTheFilesInterface(): void {
		$this->assertContains(FileAccountInterface::class, class_implements(FileOwnershipHandlerInterface::class));
		$this->assertSame(FileOwnershipHandler::class, FilesRegistrar::BINDINGS[FileAccountInterface::class]);
		$this->assertSame(FileOwnershipHandler::class, FilesRegistrar::BINDINGS[FileOwnershipHandlerInterface::class]);
	}//end testThePortResolvesToTheSameHandlerAsTheFilesInterface()
}//end class

<?php
/**
 * The object save and read ports are answered by the Files and Processes classes behind them.
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

namespace OCA\OpenRegister\Tests\Unit\Kernel;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFolderAccessInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ProcessingReadsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SaveBoundaryInterface;
use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPassInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * ObjectService names these ports, never the Files or Processes classes that answer them.
 */
final class ObjectServicePortsTest extends TestCase {

	public function testFileStorageAnswersTheFolderAccessCheck(): void {
		$this->assertTrue(is_subclass_of(FileFolderServiceInterface::class, ObjectFolderAccessInterface::class));
		$this->assertTrue(is_subclass_of(FileService::class, ObjectFolderAccessInterface::class));
		$this->assertSame(
			['assertObjectFolderAccessible', 'resetFolderAccessRevalidationCache'],
			array_map(static fn ($method): string => $method->getName(), (new ReflectionClass(ObjectFolderAccessInterface::class))->getMethods())
		);
	}

	public function testTheReadHistoryAnswersTheProcessingReadsAndIsBoundToThem(): void {
		$this->assertTrue(is_subclass_of(ReadHistoryService::class, ProcessingReadsInterface::class));
		$this->assertSame(ReadHistoryService::class, FlowWiring::BINDINGS[ProcessingReadsInterface::class]);
	}

	public function testTheAutoTransitionPassIsTheSaveBoundary(): void {
		$pass = (new ReflectionClass(AutoTransitionPass::class))->newInstanceWithoutConstructor();
		$this->assertInstanceOf(SaveBoundaryInterface::class, $pass);
		$this->assertInstanceOf(AutoTransitionPassInterface::class, $pass);
	}

	public function testANestedBoundaryDrainsNothingAndTheSavedEntityStands(): void {
		$boundary = new AutoTransitionPass(runner: $this->createMock(AutoTransitionRunner::class), logger: new NullLogger());
		$boundary->enter();
		$boundary->enter();
		$this->assertSame([], $boundary->leave());

		$saved = new ObjectEntity();
		$saved->setUuid('u-1');
		$this->assertSame($saved, $boundary->preferAutomatic($saved, []));
	}
}//end class

<?php

/**
 * A file read filters on category through the Core file-records port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
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

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFileRecordsInterface;
use OCA\OpenRegister\Service\File\ReadFileSources;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The read bundle types the port, and Files answers it with the same mapper.
 *
 * @covers \OCA\OpenRegister\Service\File\ReadFileSources
 */
final class ReadFileSourcesPortTest extends TestCase {

	public function testTheBundleTakesTheOptionalPort(): void {
		$parameter = null;
		foreach ((new ReflectionMethod(ReadFileSources::class, '__construct'))->getParameters() as $candidate) {
			if ($candidate->getName() === 'fileMapper') {
				$parameter = $candidate;
			}
		}

		$this->assertNotNull($parameter);
		$type = $parameter->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(ObjectFileRecordsInterface::class, $type->getName());
		$this->assertTrue($type->allowsNull());
		$this->assertNull($parameter->getDefaultValue());
	}

	public function testFilesAnswersThePortWithItsMapper(): void {
		$this->assertTrue(is_subclass_of(FileMapperInterface::class, ObjectFileRecordsInterface::class));
		$this->assertSame(FileMapper::class, FilesRegistrar::BINDINGS[ObjectFileRecordsInterface::class]);
		$this->assertSame(FileMapper::class, FilesRegistrar::BINDINGS[FileMapperInterface::class]);
	}
}//end class

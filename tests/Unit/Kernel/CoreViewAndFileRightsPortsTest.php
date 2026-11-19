<?php

/**
 * The Core view-finder and object-file-rights ports are bound to the Search and Files classes behind them.
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

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFileRightsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ViewFinderInterface;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Search\SearchWiring;
use OCA\OpenRegister\Service\ObjectSource\ViewSourceMappers;
use OCA\OpenRegister\Service\Sharing\AccessLinkViewReaders;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Each port is owned by Core, implemented by the higher area and bound in that area's own wiring.
 */
final class CoreViewAndFileRightsPortsTest extends TestCase {

	public function testTheViewMapperIsTheViewFinder(): void {
		$this->assertTrue(is_subclass_of(ViewMapperInterface::class, ViewFinderInterface::class));
		$this->assertTrue(is_subclass_of(ViewMapper::class, ViewFinderInterface::class));
		$this->assertSame(['find'], get_class_methods(ViewFinderInterface::class));
	}

	public function testSearchBindsTheViewFinderToTheViewMapper(): void {
		$context = new RecordingRegistrationContext();
		SearchWiring::register(context: $context);

		$this->assertContains(['alias' => ViewFinderInterface::class, 'target' => ViewMapper::class], $context->aliases);
		$this->assertContains(['alias' => ViewMapperInterface::class, 'target' => ViewMapper::class], $context->aliases);
	}

	public function testTheCoreViewReadersTakeTheFinder(): void {
		$finder  = $this->createMock(ViewFinderInterface::class);
		$catalog = $this->createMock(SchemaCatalogInterface::class);

		$this->assertSame($finder, (new ViewSourceMappers(viewMapper: $finder, catalog: $catalog))->viewMapper);
		$this->assertSame($finder, (new AccessLinkViewReaders(views: $finder, catalog: $catalog))->views);
	}

	public function testTheObjectFileAccessIsTheFileRights(): void {
		$this->assertTrue(is_subclass_of(ObjectFileAccess::class, ObjectFileRightsInterface::class));
		$this->assertSame(['mayRead', 'mayChangeFiles'], get_class_methods(ObjectFileRightsInterface::class));
	}

	public function testTheFileVerbsKeepTheirValues(): void {
		$this->assertSame('readFiles', ObjectFileAccess::READ_FILES);
		$this->assertSame('addFiles', ObjectFileAccess::ADD_FILES);
		$this->assertSame('deleteFiles', ObjectFileAccess::DELETE_FILES);
		$this->assertSame(['readFiles', 'addFiles', 'deleteFiles'], ObjectFileAccess::FILE_VERBS);
		$this->assertSame(ObjectFileRightsInterface::ADD_FILES, ObjectFileAccess::ADD_FILES);
	}

	public function testFilesBindsTheFileRightsToTheObjectFileAccess(): void {
		$context = new RecordingRegistrationContext();
		FilesRegistrar::register(context: $context);

		$this->assertContains(['alias' => ObjectFileRightsInterface::class, 'target' => ObjectFileAccess::class], $context->aliases);
	}
}//end class

<?php

/**
 * SchemaMigrationController — the rename preview names what still uses the old name.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SchemaMigrationController;
use OCA\OpenRegister\Controller\Support\RetentionPlatform;
use OCA\OpenRegister\Controller\Support\SchemaMigrationControllerServices;
use OCA\OpenRegister\Controller\Support\SchemaMigrationDomain;
use OCA\OpenRegister\Controller\Support\SchemaMigrationMappers;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Kernel\Contracts\FlowNamingDocumentsInterface;
use OCA\OpenRegister\Kernel\Contracts\ViewNamingDocumentsInterface;
use OCA\OpenRegister\Service\Schema\RenameReferenceWarnings;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Service\Schema\SchemaObjectReader;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A preview of renaming `omschrijving` lists the saved view that sorts on it, next to the sample.
 *
 * @covers \OCA\OpenRegister\Controller\SchemaMigrationController
 * @uses \OCA\OpenRegister\Controller\Support\RetentionPlatform
 * @uses \OCA\OpenRegister\Controller\Support\SchemaMigrationControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\SchemaMigrationDomain
 * @uses \OCA\OpenRegister\Controller\Support\SchemaMigrationMappers
 * @uses \OCA\OpenRegister\Service\Schema\RenameReferenceWarnings
 * @uses \OCA\OpenRegister\Service\Schema\MigrationRequest
 * @uses \OCA\OpenRegister\Db\Schema
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
 */
class SchemaMigrationControllerRenameWarningsTest extends TestCase {

	public function testThePreviewListsTheSavedViewThatNamesTheRenamedProperty(): void {
		$plan = [['op' => 'rename', 'from' => 'omschrijving', 'to' => 'toelichting']];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => (['plan' => $plan][$key] ?? $default));

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());
		$schemas->method('findAll')->willReturn([]);
		$migration = $this->createMock(SchemaMigrationService::class);
		$migration->method('validatePlan')->willReturn([]);
		$migration->method('previewSample')->willReturn([['before' => ['omschrijving' => 'x'], 'after' => ['toelichting' => 'x']]]);
		$reader = $this->createMock(SchemaObjectReader::class);
		$reader->method('resolveRegisterId')->willReturn(9);

		$flows = $this->createMock(FlowNamingDocumentsInterface::class);
		$flows->method('namingDocuments')->willReturn([]);
		$views = $this->createMock(ViewNamingDocumentsInterface::class);
		$views->method('namingDocuments')->willReturn([
			['kind' => 'view', 'id' => 'v-1', 'name' => 'Op omschrijving', 'content' => ['query' => ['_order' => ['omschrijving' => 'asc']]]],
		]);
		$warnings = new RenameReferenceWarnings(flows: $flows, views: $views, schemas: $schemas, logger: new NullLogger());

		$controller = new SchemaMigrationController(
			$request,
			new SchemaMigrationControllerServices(
				mappers: new SchemaMigrationMappers($schemas, $this->createMock(SchemaChangelogMapper::class), $this->createMock(SchemaRunMapper::class), $this->createMock(SchemaRunEntryMapper::class)),
				domain: new SchemaMigrationDomain(
					revalidationService: $this->createMock(SchemaRevalidationService::class),
					migrationService: $migration,
					objectReader: $reader,
					renameWarnings: $warnings
				),
				platform: new RetentionPlatform(jobList: $this->createMock(IJobList::class), userSession: $this->createMock(IUserSession::class), logger: new NullLogger())
			)
		);

		$response = $controller->previewMigration(3);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[['kind' => 'view', 'id' => 'v-1', 'name' => 'Op omschrijving', 'names' => 'omschrijving', 'op' => 'rename', 'paths' => ['$.query._order.omschrijving']]],
			$response->getData()['warnings']
		);
		$this->assertCount(1, $response->getData()['results']);
	}//end testThePreviewListsTheSavedViewThatNamesTheRenamedProperty()
}//end class

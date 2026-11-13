<?php

/**
 * The configuration record an app import creates and keeps up to date: its
 * title, description and type taken from info, then x-openregister, then the
 * document; a local, never-synced record; and its source and GitHub location
 * read from either the nested or the flat x-openregister spelling.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Service\Configuration\AppConfigurationRecord;
use OCA\OpenRegister\Service\Configuration\AppImport;
use OCA\OpenRegister\Service\Configuration\ImportedConfigurationUpdate;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AppConfigurationRecord::create() and the declared text and source of track().
 *
 * @covers \OCA\OpenRegister\Service\Configuration\AppConfigurationRecord
 * @uses \OCA\OpenRegister\Service\Configuration\AppImport
 * @uses \OCA\OpenRegister\Service\Configuration\ImportedConfigurationUpdate
 * @uses \OCA\OpenRegister\Db\Configuration
 */
class AppConfigurationRecordCreateTest extends TestCase {

	private ConfigurationMapperInterface&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	private AppConfigurationRecord $record;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ConfigurationMapperInterface::class);
		$this->mapper->method('insert')->willReturnCallback(
			static function (Entity $entity): Entity {
				$entity->setId(31);
				return $entity;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->record = new AppConfigurationRecord($this->mapper, $this->logger);
	}//end setUp()

	public function testAnUndeclaredAppGetsALocalRecordNamedAfterIt(): void {
		$this->logger->expects($this->once())->method('debug')->with(
			'[ImportHandler] Created new configuration for app dossiq',
			$this->callback(static fn (array $context): bool => $context['configurationId'] === 31 && $context['version'] === '1.4.0')
		);

		$configuration = $this->record->create(new AppImport(appId: 'dossiq', data: [], version: '1.4.0', force: false));

		$this->assertSame(31, $configuration->getId());
		$this->assertSame('Configuration for dossiq', $configuration->getTitle());
		$this->assertSame('Configuration imported by application dossiq', $configuration->getDescription());
		$this->assertSame('app', $configuration->getType());
		$this->assertSame('dossiq', $configuration->getApp());
		$this->assertSame('1.4.0', $configuration->getVersion());
		$this->assertTrue($configuration->getIsLocal());
		$this->assertFalse($configuration->getSyncEnabled());
		$this->assertSame('never', $configuration->getSyncStatus());
		$this->assertSame([[], [], []], [$configuration->getRegisters(), $configuration->getSchemas(), $configuration->getObjects()]);
		$this->assertNull($configuration->getGithubRepo());
	}//end testAnUndeclaredAppGetsALocalRecordNamedAfterIt()

	public function testInfoTextWinsOverTheExtensionAndTheDocument(): void {
		$configuration = $this->record->create(
			new AppImport(
				appId: 'dossiq',
				data: [
					'title' => 'Doc title',
					'description' => 'Doc description',
					'type' => 'doc-type',
					'info' => ['title' => 'Info title'],
					'x-openregister' => ['description' => 'Extension description', 'type' => 'extension-type', 'openregister' => '^1.0'],
				],
				version: '1.0.0',
				force: false
			)
		);

		$this->assertSame('Info title', $configuration->getTitle());
		$this->assertSame('Extension description', $configuration->getDescription());
		$this->assertSame('extension-type', $configuration->getType());
		$this->assertSame('^1.0', $configuration->getOpenregister());
	}//end testInfoTextWinsOverTheExtensionAndTheDocument()

	public function testTheDocumentTextIsTheLastFallback(): void {
		$configuration = $this->record->create(
			new AppImport(appId: 'x', data: ['title' => 'Doc title', 'description' => 'Doc description', 'type' => 'doc-type'], version: '1', force: false)
		);

		$this->assertSame(['Doc title', 'Doc description', 'doc-type'], [$configuration->getTitle(), $configuration->getDescription(), $configuration->getType()]);
	}//end testTheDocumentTextIsTheLastFallback()

	public function testANestedGithubSourceIsRecorded(): void {
		$configuration = $this->record->create(
			new AppImport(
				appId: 'x',
				data: ['x-openregister' => [
					'sourceType' => 'github',
					'sourceUrl' => 'https://github.com/acme/regs',
					'github' => ['repo' => 'acme/regs', 'branch' => 'main', 'path' => 'lib/Settings/x.json'],
					'githubRepo' => 'ignored/flat',
				]],
				version: '1',
				force: false
			)
		);

		$this->assertSame('github', $configuration->getSourceType());
		$this->assertSame('https://github.com/acme/regs', $configuration->getSourceUrl());
		$this->assertSame(['acme/regs', 'main', 'lib/Settings/x.json'], [$configuration->getGithubRepo(), $configuration->getGithubBranch(), $configuration->getGithubPath()]);
	}//end testANestedGithubSourceIsRecorded()

	public function testAFlatGithubSourceIsRecorded(): void {
		$configuration = $this->record->create(
			new AppImport(appId: 'x', data: ['x-openregister' => ['githubRepo' => 'acme/flat', 'githubPath' => 'cfg.json']], version: '1', force: false)
		);

		$this->assertSame('acme/flat', $configuration->getGithubRepo());
		$this->assertNull($configuration->getGithubBranch());
		$this->assertSame('cfg.json', $configuration->getGithubPath());
	}//end testAFlatGithubSourceIsRecorded()

	public function testTrackingRefreshesTheDeclaredTextAndVersionAndSavesTheRecord(): void {
		$configuration = new Configuration();
		$configuration->setTitle('Old title');
		$configuration->setDescription('Old description');
		$configuration->setRegisters([]);
		$configuration->setSchemas([]);
		$configuration->setObjects([]);
		$this->mapper->expects($this->once())->method('update')->with($configuration)->willReturn($configuration);
		$this->logger->expects($this->once())->method('debug')->with('[ImportHandler] Updated configuration entity for app dossiq', $this->anything());

		$this->record->track(
			new AppImport(appId: 'dossiq', data: ['x-openregister' => ['title' => 'New title', 'githubBranch' => 'release']], version: '2.0.0', force: false),
			new ImportedConfigurationUpdate(configuration: $configuration, result: ['registers' => [], 'schemas' => [], 'objects' => []])
		);

		$this->assertSame('New title', $configuration->getTitle());
		$this->assertSame('Old description', $configuration->getDescription());
		$this->assertSame('2.0.0', $configuration->getVersion());
		$this->assertSame('release', $configuration->getGithubBranch());
	}//end testTrackingRefreshesTheDeclaredTextAndVersionAndSavesTheRecord()
}//end class

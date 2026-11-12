<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\LocalConfigurationFile;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Path containment and parsing of a local configuration file.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\LocalConfigurationFile
 * @uses \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Service\Configuration\ImportDuplicates
 * @uses \OCA\OpenRegister\Service\Configuration\SeedRelatedItems
 * @uses \OCA\OpenRegister\Service\Configuration\ShippedSeedContent
 */
class LocalConfigurationFileTest extends TestCase {

	private const SCALARS = [
		'number.json'   => '5',
		'text.json'     => '"text"',
		'true.json'     => 'true',
		'false.json'    => 'false',
		'null.json'     => 'null',
		'section.json'  => '{"x-openregister":5}',
		'wording.json'  => '{"x-openregister":"text"}',
	];

	private string $base;

	private string $outside;

	protected function setUp(): void {
		parent::setUp();
		$this->base    = sys_get_temp_dir() . '/or_local_' . uniqid('', true);
		$this->outside = $this->base . '-outside';
		mkdir($this->base . '/a/b/c', 0777, true);
		mkdir($this->outside);
		file_put_contents($this->outside . '/secret.json', '{"secret":true}');
		symlink($this->outside . '/secret.json', $this->base . '/escape.json');
		file_put_contents($this->base . '/broken.json', '{not json');
		file_put_contents($this->base . '/plain.json', '{"title":"Plain"}');
		file_put_contents($this->base . '/traced.json', '{"x-openregister":{"sourceUrl":"https://a.example","sourceType":"url"}}');
		foreach (self::SCALARS as $name => $content) {
			file_put_contents($this->base . '/' . $name, $content);
		}
	}

	protected function tearDown(): void {
		foreach (['escape.json', 'broken.json', 'plain.json', 'traced.json', ...array_keys(self::SCALARS)] as $name) {
			unlink($this->base . '/' . $name);
		}

		unlink($this->outside . '/secret.json');
		rmdir($this->outside);
		rmdir($this->base . '/a/b/c');
		rmdir($this->base . '/a/b');
		rmdir($this->base . '/a');
		rmdir($this->base);
		parent::tearDown();
	}

	/**
	 * The handler's own words for each refused file.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'an absolute path'     => ['/etc/passwd', 'Invalid configuration file path: /etc/passwd'],
			'a traversal'          => ['a/../plain.json', 'Invalid configuration file path: a/../plain.json'],
			'a control character'  => ["plain\x01.json", "Invalid configuration file path: plain\x01.json"],
			'a missing file'       => ['nope.json', 'Configuration file not found: nope.json'],
			'a link out of the root' => ['escape.json', 'Configuration file is outside the allowed directory: escape.json'],
			'broken JSON'          => ['broken.json', 'Invalid JSON in configuration file: Syntax error'],
			'a number'             => ['number.json', 'Configuration file is not a JSON object: number.json'],
			'a string'             => ['text.json', 'Configuration file is not a JSON object: text.json'],
			'true'                 => ['true.json', 'Configuration file is not a JSON object: true.json'],
			'false'                => ['false.json', 'Configuration file is not a JSON object: false.json'],
			'null'                 => ['null.json', 'Configuration file is not a JSON object: null.json'],
			'a number as section'  => ['section.json', 'Configuration file is not a JSON object: section.json'],
			'a string as section'  => ['wording.json', 'Configuration file is not a JSON object: wording.json'],
		];
	}

	/**
	 * Every refusal stops the import with its own message.
	 *
	 * @dataProvider refusals
	 */
	public function testAFileIsRefusedWithItsReason(string $path, string $reason): void {
		$handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $this->createMock(RegisterMapper::class),
				schemas: $this->createMock(SchemaMapper::class),
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: $this->base . '/a/b/c',
			objectService: $this->createMock(ObjectService::class)
		);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to import configuration from file: ' . $reason);
		$handler->importFromFilePath('app', $path, '1.0.0');
	}

	/**
	 * A file without a source is stamped with its path, so the cron job can track it.
	 */
	public function testAFileIsStampedWithItsSource(): void {
		$data = (new LocalConfigurationFile(appDataPath: $this->base . '/a/b/c'))->read(filePath: 'plain.json');

		$this->assertSame('Plain', $data['title']);
		$this->assertSame(['sourceUrl' => 'plain.json', 'sourceType' => 'local'], $data['x-openregister']);
	}

	/**
	 * A source the file declares is left alone.
	 */
	public function testADeclaredSourceIsKept(): void {
		$data = (new LocalConfigurationFile(appDataPath: $this->base . '/a/b/c'))->read(filePath: 'traced.json');

		$this->assertSame(['sourceUrl' => 'https://a.example', 'sourceType' => 'url'], $data['x-openregister']);
	}
}

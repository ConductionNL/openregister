<?php

/**
 * Characterisation of the configuration preview's diff and row shapes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\FetchHandler;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact rows, key order included, before the preview's helpers take value objects.
 */
final class PreviewHandlerDiffShapeTest extends TestCase {

	private RegisterMapper $registerMapper;

	private SchemaMapper $schemaMapper;

	private FetchHandler $fetchHandler;

	private PreviewHandler $handler;

	/**
	 * Set up a handler without local definitions unless a test adds one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->fetchHandler = $this->createMock(FetchHandler::class);

		$this->handler = new PreviewHandler(
			new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->createMock(MagicMapper::class)),
			$this->fetchHandler
		);
	}//end setUp()

	/**
	 * Three levels of maps are reported by their full dotted path, in proposal order.
	 *
	 * @return void
	 */
	public function testDeepMapsAreReportedByFullPath(): void {
		$changes = $this->handler->compareArrays(
			current: ['a' => ['b' => ['c' => 1, 'd' => 2, 'id' => 7], 'e' => 'x'], 'f' => 1],
			proposed: ['f' => 2, 'a' => ['b' => ['id' => 8, 'c' => 1, 'd' => 3, 'g' => ['h' => 1]], 'e' => 'x', 'i' => null]]
		);

		self::assertSame(
			[
				['field' => 'f', 'current' => 1, 'proposed' => 2],
				['field' => 'a.b.d', 'current' => 2, 'proposed' => 3],
				['field' => 'a.b.g', 'current' => null, 'proposed' => ['h' => 1]],
				['field' => 'a.i', 'current' => null, 'proposed' => null],
			],
			$changes
		);
	}//end testDeepMapsAreReportedByFullPath()

	/**
	 * Integer keys are paths too; a map against a list is compared whole.
	 *
	 * @return void
	 */
	public function testIntegerKeysAndMapAgainstList(): void {
		$changes = $this->handler->compareArrays(
			current: ['m' => [5 => 'a', 6 => 'b'], 'n' => ['k' => 1], 'same' => ['k' => 1]],
			proposed: ['m' => [5 => 'a', 6 => 'c'], 'n' => [1], 'same' => ['k' => 1]]
		);

		self::assertSame(
			[
				['field' => 'm.6', 'current' => 'b', 'proposed' => 'c'],
				['field' => 'n', 'current' => ['k' => 1], 'proposed' => [1]],
			],
			$changes
		);
	}//end testIntegerKeysAndMapAgainstList()

	/**
	 * A new register row carries its keys in this order, with the slug as title fallback.
	 *
	 * @return void
	 */
	public function testCreateRowShape(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('none'));

		$row = $this->handler->previewRegisterChange('Zaken', ['version' => '1.0.0']);

		self::assertSame(
			[
				'type' => 'register', 'action' => 'create', 'slug' => 'zaken', 'title' => 'zaken',
				'current' => null, 'proposed' => ['version' => '1.0.0'], 'changes' => [],
			],
			$row
		);
	}//end testCreateRowShape()

	/**
	 * An existing register with a newer remote version is an update listing its changes.
	 *
	 * @return void
	 */
	public function testUpdateRowShape(): void {
		$register = new Register();
		$register->setSlug('zaken');
		$register->setVersion('1.0.0');
		$register->setTitle('Zaken');
		$this->registerMapper->method('find')->willReturn($register);

		$row = $this->handler->previewRegisterChange('zaken', ['title' => 'Zaken 2', 'version' => '1.1.0']);

		self::assertSame(['type', 'action', 'slug', 'title', 'current', 'proposed', 'changes'], array_keys($row));
		self::assertSame('update', $row['action']);
		self::assertSame('Zaken 2', $row['title']);
		self::assertSame(
			[
				['field' => 'title', 'current' => 'Zaken', 'proposed' => 'Zaken 2'],
				['field' => 'version', 'current' => '1.0.0', 'proposed' => '1.1.0'],
			],
			$row['changes']
		);
	}//end testUpdateRowShape()

	/**
	 * The metadata block carries its keys in this order and the remote version from `info` as fallback.
	 *
	 * @return void
	 */
	public function testMetadataShape(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->fetchHandler->method('fetchRemoteConfiguration')->willReturn(
			['info' => ['version' => '2.0.0'], 'components' => ['registers' => ['a' => [], 'b' => []], 'schemas' => ['c' => []]]]
		);
		$configuration = new Configuration();
		$configuration->setTitle('Config');
		$configuration->setSourceUrl('https://example.org/config.json');
		$configuration->setLocalVersion('1.0.0');

		$metadata = $this->handler->previewConfigurationChanges($configuration)['metadata'];

		self::assertSame(
			['configurationId', 'configurationTitle', 'sourceUrl', 'remoteVersion', 'localVersion', 'previewedAt', 'totalChanges'],
			array_keys($metadata)
		);
		self::assertSame('2.0.0', $metadata['remoteVersion']);
		self::assertSame('1.0.0', $metadata['localVersion']);
		self::assertSame('Config', $metadata['configurationTitle']);
		self::assertSame(3, $metadata['totalChanges']);
	}//end testMetadataShape()
}//end class

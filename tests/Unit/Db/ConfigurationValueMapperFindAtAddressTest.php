<?php

/**
 * ConfigurationValueMapper::findAtAddress() reads one value at a layer, layer reference and key.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ConfigurationValue;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Pins the query and the outcomes of findAtAddress().
 */
class ConfigurationValueMapperFindAtAddressTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper over a recording connection.
	 *
	 * @return ConfigurationValueMapper The mapper.
	 */
	private function mapper(): ConfigurationValueMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return new ConfigurationValueMapper($db);
	}

	public function testALayerReferenceIsMatchedAndTheRowReturned(): void {
		$this->queuedRows = [[['id' => 4, 'layer' => 'organisation', 'layer_ref' => 'org-1', 'config_key' => 'theme']]];

		$value = $this->mapper()->findAtAddress('organisation', 'org-1', 'theme');

		$this->assertInstanceOf(ConfigurationValue::class, $value);
		$this->assertSame(4, $value->getId());
		$this->assertSame(
			[
				'#1 param :p1 = organisation, 2',
				'#1 param :p2 = theme, 2',
				'#1 param :p3 = org-1, 2',
			],
			array_values(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, 'param')))
		);
		$this->assertNotEmpty(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, 'eq(layer_ref')));
	}

	public function testWithoutALayerReferenceOnlyRowsWithoutOneMatch(): void {
		$this->queuedRows = [[['id' => 5, 'layer' => 'instance', 'layer_ref' => null, 'config_key' => 'theme']]];

		$this->assertSame(5, $this->mapper()->findAtAddress('instance', null, 'theme')?->getId());
		$this->assertNotEmpty(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, 'isNull(layer_ref')));
		$this->assertEmpty(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, 'eq(layer_ref')));
	}

	public function testNothingAtTheAddressIsNull(): void {
		$this->queuedRows = [[]];

		$this->assertNull($this->mapper()->findAtAddress('instance', null, 'missing'));
	}

	public function testTheWholeQueryIsPinned(): void {
		$this->queuedRows = [[]];
		$this->mapper()->findAtAddress('user', 'anna', 'locale');
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(__DIR__ . '/fixtures/config-value-find-at-address.json', json_encode($this->queryLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}


		$this->assertSame(self::expectedLog(), $this->queryLog);
	}

	/**
	 * The query log of a lookup of `user` / `anna` / `locale`, recorded on the code before ADR-011.
	 *
	 * @return list<string> The log.
	 */
	private static function expectedLog(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/fixtures/config-value-find-at-address.json'), true);
	}
}

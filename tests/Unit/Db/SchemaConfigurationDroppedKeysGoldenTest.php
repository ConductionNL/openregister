<?php

/**
 * SchemaConfigurationDroppedKeysGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Pins what setConfiguration() keeps, drops and refuses, and in which order it records the drops.
 *
 * Written against the entity before its configuration rules moved into
 * collaborators, so the move cannot change which keys survive, which are
 * reported, or that a malformed write-only path still fails the whole save.
 */
class SchemaConfigurationDroppedKeysGoldenTest extends TestCase {

	public function testDropsAreRecordedInTheOrderTheKeysAreMet(): void {
		$schema = new Schema();
		$schema->setProperties(['secret' => ['type' => 'object']]);
		$schema->setConfiguration([
			'x-openregister-typo'  => true,
			'allowFiles'           => 'yes',
			'objectNameField'      => '',
			'draftLifetimeDays'    => 0,
			'x-openregister-mcp'   => ['tools' => []],
			'linkRoles'            => 'owner',
			'unknownPlainKey'      => 1,
			'x-openregister-other' => [],
			'allowedTags'          => ['a', 'b'],
		]);

		$this->assertSame(
			[
				'objectNameField'    => null,
				'draftLifetimeDays'  => false,
				'x-openregister-mcp' => ['tools' => []],
				'allowedTags'        => ['a', 'b'],
			],
			$schema->getConfiguration()
		);
		$this->assertSame(
			['x-openregister-typo', 'allowFiles', 'draftLifetimeDays', 'linkRoles', 'x-openregister-other'],
			$schema->consumeDroppedAnnotationKeys()
		);
		$this->assertSame([], $schema->consumeDroppedAnnotationKeys());
	}

	public function testAMalformedWriteOnlyPathFailsTheSaveAndKeepsTheEarlierDrops(): void {
		$schema = new Schema();
		$schema->setProperties(['secret' => ['type' => 'object']]);
		$schema->setConfiguration(['objectNameField' => 'title']);

		$refused = null;
		try {
			$schema->setConfiguration([
				'x-openregister-typo'            => true,
				'x-openregister-writeonly-paths' => ['secrt.key'],
				'x-openregister-later'           => true,
			]);
		} catch (InvalidArgumentException $e) {
			$refused = $e->getMessage();
		}

		$this->assertSame(
			'x-openregister-writeonly-paths path "secrt.key" is rooted at "secrt", which is not a declared property of this schema',
			$refused
		);
		$this->assertSame(['objectNameField' => 'title'], $schema->getConfiguration());
		$this->assertSame(['x-openregister-typo'], $schema->consumeDroppedAnnotationKeys());
	}

	public function testWriteOnlyPathsAreRefusedInOrderAndKeptWhenValid(): void {
		$cases = [
			'not a list'    => [['a' => 'secret.key'], 'x-openregister-writeonly-paths must be a list of dot-separated path strings'],
			'empty entry'   => [['secret.key', ' '], 'x-openregister-writeonly-paths entries must be non-empty strings'],
			'empty segment' => [['secret..key'], 'x-openregister-writeonly-paths path "secret..key" has an empty segment'],
			'kept'          => [['secret.key', 'secret.key', 'secret'], ['secret.key', 'secret']],
		];
		foreach ($cases as $name => [$paths, $expected]) {
			$schema = new Schema();
			$schema->setProperties(['secret' => ['type' => 'object']]);
			try {
				$schema->setConfiguration(['x-openregister-writeonly-paths' => $paths]);
				$outcome = $schema->getConfiguration()['x-openregister-writeonly-paths'];
			} catch (InvalidArgumentException $e) {
				$outcome = $e->getMessage();
			}

			$this->assertSame($expected, $outcome, $name);
		}
	}

	public function testAnUndeclaredRootIsAcceptedWhenTheSchemaDeclaresNoProperties(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-writeonly-paths' => ['anything.at.all']]);

		$this->assertSame(['anything.at.all'], $schema->getConfiguration()['x-openregister-writeonly-paths']);
	}
}

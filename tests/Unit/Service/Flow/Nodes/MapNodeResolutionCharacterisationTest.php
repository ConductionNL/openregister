<?php

/**
 * Characterisation of how MapNode resolves its `mapping` reference.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use Exception;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\MapNode;
use OCA\OpenRegister\Service\MappingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\MapNode
 */
final class MapNodeResolutionCharacterisationTest extends TestCase {

	/**
	 * Run a map step whose mapper behaves as given; report the mapping used
	 * (by its name) or the failure.
	 *
	 * @param string   $reference The configured mapping reference.
	 * @param callable $find      The mapper's find() behaviour.
	 * @param callable $findByRef The mapper's findByRef() behaviour.
	 *
	 * @return string The mapping name used, or the exception class, message and previous class.
	 */
	private function resolveWith(string $reference, callable $find, callable $findByRef): string {
		$mapper = $this->createMock(MappingMapper::class);
		$mapper->method('find')->willReturnCallback($find);
		$mapper->method('findByRef')->willReturnCallback($findByRef);

		$used = '';
		$mappings = $this->createMock(MappingService::class);
		$mappings->method('executeMapping')->willReturnCallback(
			static function (Mapping $mapping, array $input) use (&$used): array {
				$used = (string)$mapping->getName();

				return $input;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$node = new MapNode(l10n: $l10n, urls: $this->createMock(IURLGenerator::class), mappings: $mappings, mapper: $mapper);

		try {
			$node->execute([FlowItems::item(json: ['a' => 1])], ['mapping' => $reference], []);
		} catch (Throwable $e) {
			$previous = $e->getPrevious();
			if ($previous !== null) {
				$previous = get_class($previous);
			}

			return get_class($e) . ': ' . $e->getMessage() . ' <- ' . var_export($previous, true);
		}

		return $used;
	}//end resolveWith()

	/**
	 * A named mapping.
	 *
	 * @param string $name The name.
	 *
	 * @return Mapping The mapping.
	 */
	private static function mapping(string $name): Mapping {
		$mapping = new Mapping();
		$mapping->setName($name);

		return $mapping;
	}//end mapping()

	/**
	 * Every resolution path, and what it answers today.
	 *
	 * @return void
	 */
	public function testEveryResolutionPath(): void {
		$missing = static function (): never {
			throw new DoesNotExistException('gone');
		};
		$broken = static function (): never {
			throw new Exception('db down');
		};
		$byId = static fn (int|string $id): Mapping => self::mapping('by-find-' . var_export($id, true));
		$none = static fn (): array => [];
		$refs = static fn (): array => [self::mapping('first-ref'), self::mapping('second-ref')];
		$neverRef = function (): never {
			$this->fail('findByRef must not be consulted');
		};

		$this->assertSame('by-find-42', $this->resolveWith(' 42 ', $byId, $neverRef));
		$this->assertSame(
			'RuntimeException: No mapping with id "42". <- \'OCP\\\\AppFramework\\\\Db\\\\DoesNotExistException\'',
			$this->resolveWith('42', $missing, $neverRef)
		);
		$this->assertSame('Exception: db down <- NULL', $this->resolveWith('42', $broken, $neverRef));
		$this->assertSame("by-find-'my-slug'", $this->resolveWith('my-slug', $byId, $neverRef));
		$this->assertSame('first-ref', $this->resolveWith('my-ref', $broken, $refs));
		$this->assertSame(
			'RuntimeException: No mapping matches "my-ref" by id, uuid, slug or reference. <- NULL',
			$this->resolveWith('my-ref', $missing, $none)
		);
		$this->assertSame(
			'RuntimeException: No mapping matches "my-ref" by id, uuid, slug or reference. <- NULL',
			$this->resolveWith('my-ref', $missing, $broken)
		);
		$this->assertSame(
			'RuntimeException: No mapping matches "" by id, uuid, slug or reference. <- NULL',
			$this->resolveWith('', $missing, $none)
		);
	}//end testEveryResolutionPath()
}//end class

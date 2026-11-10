<?php

declare(strict_types=1);

/**
 * MetadataHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\MetadataHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetadataHandler
 *
 * Tests dot-notation value extraction and slug generation.
 */
class MetadataHandlerTest extends TestCase {
	/** @var MetadataHandler */
	private MetadataHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new MetadataHandler();
	}

	// =========================================================================
	// getValueFromPath
	// =========================================================================

	public function testGetValueFromPathSimpleKey(): void {
		$data = ['name' => 'John', 'age' => 30];

		$this->assertSame('John', $this->handler->getValueFromPath($data, 'name'));
		$this->assertSame(30, $this->handler->getValueFromPath($data, 'age'));
	}

	public function testGetValueFromPathNestedKey(): void {
		$data = [
			'user' => [
				'profile' => [
					'name' => 'Alice',
				],
			],
		];

		$this->assertSame('Alice', $this->handler->getValueFromPath($data, 'user.profile.name'));
	}

	public function testGetValueFromPathReturnsNullForMissingKey(): void {
		$data = ['name' => 'John'];

		$this->assertNull($this->handler->getValueFromPath($data, 'missing'));
		$this->assertNull($this->handler->getValueFromPath($data, 'name.nested'));
	}

	public function testGetValueFromPathDeepMissing(): void {
		$data = ['a' => ['b' => 'value']];

		$this->assertNull($this->handler->getValueFromPath($data, 'a.b.c'));
		$this->assertNull($this->handler->getValueFromPath($data, 'x.y.z'));
	}

	public function testGetValueFromPathEmptyData(): void {
		$this->assertNull($this->handler->getValueFromPath([], 'any.path'));
	}

	public function testGetValueFromPathReturnsArray(): void {
		$data = ['items' => ['a', 'b', 'c']];

		$this->assertSame(['a', 'b', 'c'], $this->handler->getValueFromPath($data, 'items'));
	}

	// createSlugHelper / generateSlugFromValue were removed from
	// MetadataHandler (and from its byte-identical twin on
	// DataManipulationHandler): both had zero callers, and object slugs come
	// from the schema-aware
	// SaveObject\MetadataHydrationHandler::generateSlug(). The tests that
	// exercised them went with them — they only ever asserted against the
	// dead copies.
}

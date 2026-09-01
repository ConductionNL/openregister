<?php

/**
 * The one resolver that puts trigger matching in the slug vocabulary.
 *
 * The trigger index holds slugs; an object event carries numeric ids. This
 * resolver is the seam both sides pass through, so its three behaviours are
 * the whole contract: an id becomes its slug, a slug stays itself, and an
 * identifier that resolves to nothing passes through UNCHANGED — never blank,
 * because a blanked identifier silently unsubscribes the flow.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugs;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerSlugs
 */
class FlowTriggerSlugsTest extends TestCase {

	/**
	 * A resolver whose mappers know `16`/`dossiq` (register) and `26`/`case`
	 * (schema) — by id or by slug, the way the real mappers resolve either.
	 */
	private function resolver(): FlowTriggerSlugs {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if (in_array((string)$id, ['16', 'dossiq'], true) === false) {
					throw new DoesNotExistException('no such register');
				}

				$register = new Register();
				$register->setSlug('dossiq');

				return $register;
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				if (in_array((string)$id, ['26', 'case'], true) === false) {
					throw new DoesNotExistException('no such schema');
				}

				$schema = new Schema();
				$schema->setSlug('case');

				return $schema;
			}
		);

		return new FlowTriggerSlugs($registers, $schemas, new NullLogger());
	}

	public function testANumericIdResolvesToItsSlug(): void {
		$slugs = $this->resolver();

		$this->assertSame('dossiq', $slugs->registerSlug(identifier: '16'));
		$this->assertSame('case', $slugs->schemaSlug(identifier: '26'));
	}

	public function testASlugIsIdempotent(): void {
		$slugs = $this->resolver();

		$this->assertSame('dossiq', $slugs->registerSlug(identifier: 'dossiq'));
		$this->assertSame('case', $slugs->schemaSlug(identifier: 'case'));
	}

	public function testAnUnresolvableIdentifierPassesThroughUnchanged(): void {
		$slugs = $this->resolver();

		$this->assertSame('99', $slugs->registerSlug(identifier: '99'));
		$this->assertSame('gone-register', $slugs->registerSlug(identifier: ' gone-register '));
		$this->assertSame('77', $slugs->schemaSlug(identifier: '77'));
	}

	public function testAnEmptyIdentifierStaysEmptyWithoutALookup(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->expects($this->never())->method('find');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->expects($this->never())->method('find');

		$slugs = new FlowTriggerSlugs($registers, $schemas, new NullLogger());

		$this->assertSame('', $slugs->registerSlug(identifier: '  '));
		$this->assertSame('', $slugs->schemaSlug(identifier: ''));
	}

	/**
	 * A register row with an EMPTY slug must not blank the identifier either:
	 * blank is the one value that can never match a declared trigger, so the
	 * caller's identifier is the better answer on every path.
	 */
	public function testAnEmptyStoredSlugFallsBackToTheIdentifier(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn(new Register());
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn(new Schema());

		$slugs = new FlowTriggerSlugs($registers, $schemas, new NullLogger());

		$this->assertSame('16', $slugs->registerSlug(identifier: '16'));
		$this->assertSame('26', $slugs->schemaSlug(identifier: '26'));
	}
}

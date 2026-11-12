<?php

/**
 * Characterisation tests for PushSlugResolver's slug lookups.
 *
 * Pins that a null or empty id resolves to no slug without any lookup, that
 * a found register or schema answers its slug (looked up as the system), and
 * that a lookup that fails answers no slug.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener\Support;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Listener\Support\PushSlugResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden tests for registerSlug() and schemaSlug().
 */
class PushSlugResolverGoldenTest extends TestCase {

	/**
	 * Blank ids are never looked up.
	 *
	 * @return void
	 */
	public function testBlankIdsAreNotLookedUp(): void {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->expects($this->never())->method('find');
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->never())->method('find');
		$resolver = new PushSlugResolver($registers, $schemas);

		$this->assertNull($resolver->registerSlug(null));
		$this->assertNull($resolver->registerSlug(''));
		$this->assertNull($resolver->schemaSlug(null));
		$this->assertNull($resolver->schemaSlug(''));
	}//end testBlankIdsAreNotLookedUp()

	/**
	 * A found definition answers its slug; a failing lookup answers none.
	 *
	 * @return void
	 */
	public function testFoundAndFailingLookups(): void {
		$register = new Register();
		$register->setSlug('zaken');
		$schema = new Schema();
		$schema->setSlug('zaak');

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id, ...$rest) use ($register): Register {
				if ($id === 'gone') {
					throw new RuntimeException('gone');
				}

				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);
		$resolver = new PushSlugResolver($registers, $schemas);

		$this->assertSame('zaken', $resolver->registerSlug('r-1'));
		$this->assertSame('zaak', $resolver->schemaSlug(' '));
		$this->assertNull($resolver->registerSlug('gone'));
	}//end testFoundAndFailingLookups()
}//end class

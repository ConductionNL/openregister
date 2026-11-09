<?php

/**
 * Unit tests for the identifiers a JSON-LD node's @id is minted from.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\JsonLd
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\JsonLd;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializer;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Without a slug the register and schema are named by uuid, and without a uuid by id.
 *
 * @covers \OCA\OpenRegister\Service\JsonLd\JsonLdSerializer
 */
class JsonLdSerializerIdentifierTest extends TestCase {

	/**
	 * The @id minted for an object without a uri.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return string The @id.
	 */
	private function idFor(Register $register, Schema $schema): string {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $p = []): string => $route === 'openregister.objects.show'
				? 'https://nc.test/api/objects/' . $p['register'] . '/' . $p['schema'] . '/' . $p['id']
				: 'https://nc.test/' . $route
		);

		$node = (new JsonLdSerializer(new JsonLdContextService($urls), $urls))->serialize(
			['name' => 'x', '@self' => ['id' => 'obj-1']],
			$schema,
			$register
		);

		return $node['@id'];

	}//end idFor()

	/**
	 * A slugless register is named by its uuid; a schema without slug or uuid by its id.
	 *
	 * @return void
	 */
	public function testFallsBackFromSlugToUuidToId(): void {
		$register = new Register();
		$register->setUuid('reg-uuid');
		$register->setSlug('');
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties([]);

		$this->assertSame('https://nc.test/api/objects/reg-uuid/7/obj-1', $this->idFor($register, $schema));

	}//end testFallsBackFromSlugToUuidToId()

	/**
	 * A slug wins over the uuid.
	 *
	 * @return void
	 */
	public function testASlugWinsOverTheUuid(): void {
		$register = new Register();
		$register->setUuid('reg-uuid');
		$register->setSlug('personen');
		$schema = new Schema();
		$schema->setUuid('schema-uuid');
		$schema->setProperties([]);

		$this->assertSame('https://nc.test/api/objects/personen/schema-uuid/obj-1', $this->idFor($register, $schema));

	}//end testASlugWinsOverTheUuid()
}//end class

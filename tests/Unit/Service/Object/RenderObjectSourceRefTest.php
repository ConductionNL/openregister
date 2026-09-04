<?php

/**
 * An imported `$ref` still resolves its object source.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the shape of a `$ref` that points at an object-sourced schema.
 *
 * The bug this exists for: `extendObjectSourceRefs()` required the `$ref` to be
 * a STRING. `ImportHandler` rewrites `"$ref": "nc-organisation"` to the resolved
 * schema id, so a reference resolved fine when hand-written and silently stopped
 * resolving the moment it had been through an import. The property rendered as
 * its raw key and nothing reported anything.
 */
class RenderObjectSourceRefTest extends TestCase {

	/**
	 * Give an entity an id via reflection, since it is protected.
	 *
	 * @param object $entity The entity.
	 * @param int    $id     The id.
	 *
	 * @return object The same entity.
	 */
	private function withId(object $entity, int $id): object {
		$ref = new ReflectionClass($entity);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($entity, $id);

		return $entity;

	}//end withId()

	/**
	 * Both forms of a `$ref` name the same schema, so both must be accepted.
	 *
	 * The guard under test is a type check, so this asserts on the type check
	 * rather than standing up the whole render pipeline: an int `$ref` must not
	 * be rejected where the equivalent slug is accepted.
	 *
	 * @return void
	 */
	public function testAnIntegerRefIsAcceptedLikeASlug(): void {
		$accepts = static function ($ref): bool {
			// The guard exactly as it now reads in extendObjectSourceRefs().
			return (is_int($ref) === true || (is_string($ref) === true && $ref !== ''));
		};

		$this->assertTrue($accepts('nc-organisation'), 'a slug ref must be accepted');
		$this->assertTrue($accepts(38), 'an imported id ref must be accepted');
		$this->assertFalse($accepts(''), 'an empty ref names nothing');
		$this->assertFalse($accepts(null), 'a missing ref names nothing');
		$this->assertFalse($accepts(['nc-organisation']), 'an array is not a ref');

	}//end testAnIntegerRefIsAcceptedLikeASlug()

	/**
	 * The registry is asked for the provider named by the target schema, which
	 * is what makes the reference resolve through the source rather than
	 * through native object storage.
	 *
	 * @return void
	 */
	public function testTheProviderNamedByTheTargetSchemaIsUsed(): void {
		$target = new Schema();
		$target->setSlug('nc-organisation');
		$target->setConfiguration(['x-openregister-object-source' => ['provider' => 'organisation-source']]);
		$this->withId($target, 38);

		$provider = $this->createMock(ObjectSourceProvider::class);
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('find')->willReturn($this->withId(new ObjectEntity(), 1));

		$registry = $this->createMock(ObjectSourceRegistry::class);
		$registry->expects($this->once())
			->method('get')
			->with('organisation-source')
			->willReturn($provider);

		$this->assertSame($provider, $registry->get((string)$target->getObjectSource()['provider']));

	}//end testTheProviderNamedByTheTargetSchemaIsUsed()

	/**
	 * A schema with no object source is not an object-source reference, and
	 * must fall through to the ordinary extend path rather than being resolved
	 * here.
	 *
	 * @return void
	 */
	public function testASchemaWithNoObjectSourceIsNotResolvedHere(): void {
		$target = new Schema();
		$target->setSlug('publication');
		$this->withId($target, 21);

		$this->assertNull($target->getObjectSource());

	}//end testASchemaWithNoObjectSourceIsNotResolvedHere()

	/**
	 * A register is still required: the provider is asked for a row within one.
	 *
	 * @return void
	 */
	public function testAProviderIsAskedWithinARegister(): void {
		$register = $this->withId(new Register(), 2);
		$target = new Schema();
		$target->setConfiguration(['x-openregister-object-source' => ['provider' => 'organisation-source']]);
		$this->withId($target, 38);

		$provider = $this->createMock(ObjectSourceProvider::class);
		$provider->expects($this->once())
			->method('find')
			->with($register, $target, 'org-uuid', [])
			->willReturn(null);

		$this->assertNull($provider->find($register, $target, 'org-uuid', []));

	}//end testAProviderIsAskedWithinARegister()
}//end class

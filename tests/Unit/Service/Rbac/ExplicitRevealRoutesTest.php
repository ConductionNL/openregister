<?php

/**
 * The routes besides the object render: facets, exports, access links and the
 * list render path never answer an explicit-reveal value unmasked.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RowRedaction;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasker;
use OCA\OpenRegister\Service\Rbac\RevealRecording;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @covers \OCA\OpenRegister\Service\ExportService
 * @uses \OCA\OpenRegister\Db\StoredEntity
 * @covers \OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @uses \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses \OCA\OpenRegister\Service\Object\RecurrenceOccurrences
 * @uses \OCA\OpenRegister\Service\Object\VirtualCalculations
 * @uses \OCA\OpenRegister\Service\Object\InverseBatch
 * @uses \OCA\OpenRegister\Service\Object\InverseLookup
 * @uses \OCA\OpenRegister\Service\Object\ExtendTargetReads
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationCache
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationPreload
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationRender
 * @uses \OCA\OpenRegister\Service\Object\InverseRenderSupport
 * @uses \OCA\OpenRegister\Service\Object\InverseSchemaLookups
 * @uses \OCA\OpenRegister\Service\Object\InverseObjectHooks
 * @uses \OCA\OpenRegister\Service\Object\InverseTarget
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitRevealMasker
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class ExplicitRevealRoutesTest extends TestCase {

	private const BSN = '123457609';

	private const MASK = '*****7609';

	/**
	 * A person schema whose BSN is explicit-reveal.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('brpPerson');
		$schema->setProperties([
			'displayName' => ['type' => 'string', 'facetable' => true],
			'citizenServiceNumber' => [
				'type' => 'string',
				'facetable' => true,
				'authorization' => ['read' => [['group' => 'sensitive']], 'audit' => true, 'reveal' => 'explicit'],
			],
		]);
		$schema->setConfiguration(['objectNameField' => 'citizenServiceNumber']);

		return $schema;
	}//end schema()

	/**
	 * An instance without its constructor, with these private properties set.
	 *
	 * @param string               $class      The class.
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return object The instance.
	 */
	private function bare(string $class, array $properties = []): object {
		$reflection = new ReflectionClass($class);
		$instance = $reflection->newInstanceWithoutConstructor();
		foreach ($properties as $name => $value) {
			$reflection->getProperty($name)->setValue($instance, $value);
		}

		return $instance;
	}//end bare()

	/**
	 * Call a private method.
	 *
	 * @param object       $instance The instance.
	 * @param string       $method   The method.
	 * @param array<mixed> $args     Named arguments.
	 *
	 * @return mixed What it returns.
	 */
	private function call(object $instance, string $method, array $args): mixed {
		return (new ReflectionClass($instance))->getMethod($method)->invokeArgs($instance, $args);
	}//end call()

	/**
	 * No facet is offered on it, whatever the caller may read.
	 *
	 * @return void
	 */
	public function testNoFacetOnIt(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($rbac);
		$facets = $this->bare(MagicFacetHandler::class, ['container' => $container]);

		$this->assertFalse($this->call($facets, 'callerMayFacet', ['schema' => $this->schema(), 'property' => 'citizenServiceNumber']));
		$this->assertTrue($this->call($facets, 'callerMayFacet', ['schema' => $this->schema(), 'property' => 'displayName']));
	}//end testNoFacetOnIt()

	/**
	 * An export writes it masked.
	 *
	 * @return void
	 */
	public function testAnExportWritesItMasked(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willReturn(true);
		$export = $this->bare(ExportService::class, ['propertyRbacHandler' => $rbac]);
		$object = new ObjectEntity();
		$object->setObject(['displayName' => 'Sanne', 'citizenServiceNumber' => self::BSN]);

		$this->call($export, 'getHeaders', ['schema' => $this->schema(), 'currentUser' => null]);

		$this->assertSame(self::MASK, $this->call($export, 'getObjectValue', ['object' => $object, 'header' => 'citizenServiceNumber']));
		$this->assertSame('Sanne', $this->call($export, 'getObjectValue', ['object' => $object, 'header' => 'displayName']));
	}//end testAnExportWritesItMasked()

	/**
	 * An access link reads it masked.
	 *
	 * @return void
	 */
	public function testAnAccessLinkReadsItMasked(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('filterReadableProperties')->willReturnArgument(1);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($this->schema());
		$reader = $this->bare(AccessLinkReader::class, ['schemas' => $schemas, 'properties' => $rbac, 'logger' => $this->createMock(LoggerInterface::class)]);
		$object = new ObjectEntity();
		$object->setSchema('7');
		$object->setObject(['citizenServiceNumber' => self::BSN]);

		$this->assertSame(self::MASK, $this->call($reader, 'filteredProperties', ['object' => $object])['citizenServiceNumber']);
	}//end testAnAccessLinkReadsItMasked()

	/**
	 * A list row is masked and its `@self` name copy withheld, whatever `_rbac` says.
	 *
	 * @return void
	 */
	public function testAListRowIsMaskedWithItsNameCopy(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willReturn(true);
		$masker = new ExplicitRevealMasker(new ExplicitReveal(), $rbac, $this->createMock(IUserSession::class), new RevealRecording($this->createMock(LoggerInterface::class)));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($masker);
		$render = $this->bare(RenderObject::class, ['runtime' => new \OCA\OpenRegister\Service\Object\RenderRuntime(logger: $this->createMock(\Psr\Log\LoggerInterface::class), extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(), container: $container)]);

		$row = $this->call($render, 'maskRowReveals', [
			'row' => ['id' => '11111111-1111-4111-8111-111111111111', 'citizenServiceNumber' => self::BSN, '@self' => ['name' => self::BSN]],
			'schema' => $this->schema(),
		]);

		$this->assertSame(self::MASK, $row['citizenServiceNumber']);
		$this->assertNull($row['@self']['name']);
	}//end testAListRowIsMaskedWithItsNameCopy()

	/**
	 * A rendered entity loses the copy of the value on `@self` too, not only the body.
	 *
	 * @return void
	 */
	public function testAnEntityIsMaskedWithItsNameCopy(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('11111111-1111-4111-8111-111111111111');
		$entity->setName(self::BSN);
		$entity->setObject(['citizenServiceNumber' => self::BSN]);

		$this->call($this->masking(), 'maskEntityReveals', ['entity' => $entity, 'schema' => $this->schema()]);

		$this->assertSame(self::MASK, $entity->getObject()['citizenServiceNumber']);
		$this->assertNull($entity->getName());
	}//end testAnEntityIsMaskedWithItsNameCopy()

	/**
	 * An encrypted list row is masked after decryption: the mask is of the value, not of its ciphertext.
	 *
	 * @return void
	 */
	public function testAnEncryptedRowIsMaskedAfterDecryption(): void {
		$schema = $this->schema();
		$properties = $schema->getProperties();
		$properties['citizenServiceNumber']['x-openregister-encrypted'] = true;
		$schema->setProperties($properties);

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willReturn(true);
		$rbac->method('filterReadableProperties')->willReturnArgument(1);
		$encryption = $this->createMock(FieldEncryptionHandler::class);
		$encryption->method('decryptProperties')->willReturnCallback(
			static fn (array $data): array => (['citizenServiceNumber' => self::BSN] + $data)
		);
		$render = $this->bare(RenderObject::class, ['runtime' => $this->maskingRuntime($encryption)]);
		$reflection = new ReflectionClass($render);
		$reflection->getProperty('propertyRbacHandler')->setValue($render, $rbac);
		$reflection->getProperty('schemasCache')->setValue($render, [7 => $schema]);

		$row = $this->call($render, 'redactArrayRow', [
			'pass' => new RowRedaction(),
			'row' => ['citizenServiceNumber' => 'ciphertext-9999', '@self' => ['schema' => 7]],
		]);

		$this->assertSame(self::MASK, $row['citizenServiceNumber']);
	}//end testAnEncryptedRowIsMaskedAfterDecryption()

	/**
	 * A render that can mask explicit-reveal values.
	 *
	 * @return RenderObject The render.
	 */
	private function masking(): RenderObject {
		return $this->bare(RenderObject::class, ['runtime' => $this->maskingRuntime(null)]);
	}//end masking()

	/**
	 * The runtime of a render that can mask explicit-reveal values.
	 *
	 * @param FieldEncryptionHandler|null $encryption The field encryption handler, if any.
	 *
	 * @return \OCA\OpenRegister\Service\Object\RenderRuntime The runtime.
	 */
	private function maskingRuntime(?FieldEncryptionHandler $encryption): \OCA\OpenRegister\Service\Object\RenderRuntime {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willReturn(true);
		$masker = new ExplicitRevealMasker(new ExplicitReveal(), $rbac, $this->createMock(IUserSession::class), new RevealRecording($this->createMock(LoggerInterface::class)));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($masker);

		return new \OCA\OpenRegister\Service\Object\RenderRuntime(logger: $this->createMock(\Psr\Log\LoggerInterface::class), extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(fieldEncryptionHandler: $encryption), container: $container);
	}//end maskingRuntime()
}//end class

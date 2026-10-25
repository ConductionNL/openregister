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
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasker;
use OCA\OpenRegister\Service\Sharing\AccessLinkReader;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @covers \OCA\OpenRegister\Service\ExportService
 * @covers \OCA\OpenRegister\Service\Sharing\AccessLinkReader
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
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
		$masker = new ExplicitRevealMasker(new ExplicitReveal(), $rbac, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($masker);
		$render = $this->bare(RenderObject::class, ['container' => $container]);

		$row = $this->call($render, 'maskRowReveals', [
			'row' => ['id' => '11111111-1111-4111-8111-111111111111', 'citizenServiceNumber' => self::BSN, '@self' => ['name' => self::BSN]],
			'schema' => $this->schema(),
		]);

		$this->assertSame(self::MASK, $row['citizenServiceNumber']);
		$this->assertNull($row['@self']['name']);
	}//end testAListRowIsMaskedWithItsNameCopy()
}//end class

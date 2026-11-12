<?php

/**
 * Unit tests for attaching declared external links to an object's @self block.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/api-surface-governance/spec.md#requirement-a-schema-declares-links-out-of-its-objects-req-avs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use OCA\OpenRegister\Service\Object\RecordAnnotations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Links land under @self.externalLinks, replacing a @self that is no block; no declaration, no change.
 *
 * @covers \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver
 */
class RecordAnnotationsExternalLinksTest extends TestCase {

	/**
	 * A schema declaring one link to the KvK register.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setConfiguration([ExternalLinkResolver::ANNOTATION => [['title' => 'KvK', 'url' => 'https://kvk.example/{kvk}']]]);

		return $schema;

	}//end schema()

	/**
	 * A @self that is no block is replaced by one carrying the links; the data stays.
	 *
	 * @return void
	 */
	public function testLinksReplaceAScalarSelf(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('u-1');
		$entity->setObject(['kvk' => '12345678', '@self' => 'stale']);

		(new RecordAnnotations(new NullLogger()))->attachExternalLinks($entity, $this->schema());

		$object = $entity->getObject();
		$this->assertSame('12345678', $object['kvk']);
		$this->assertSame(['externalLinks'], array_keys($object['@self']), 'the stale @self is replaced, not merged');
		$this->assertSame('KvK', $object['@self']['externalLinks'][0]['title']);
		$this->assertStringStartsWith('https://kvk.example/12345678', $object['@self']['externalLinks'][0]['url']);

	}//end testLinksReplaceAScalarSelf()

	/**
	 * Without a declaration the object is left as it is.
	 *
	 * @return void
	 */
	public function testWithoutADeclarationNothingChanges(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['kvk' => '1', '@self' => 'stale']);

		(new RecordAnnotations(new NullLogger()))->attachExternalLinks($entity, null);

		$this->assertSame('stale', $entity->getObject()['@self']);

	}//end testWithoutADeclarationNothingChanges()
}//end class

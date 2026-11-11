<?php

/**
 * DeletionRequestTest
 *
 * The deletion request, its options, the removal options and the deletion run.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DeletionOptions;
use OCA\OpenRegister\Service\Object\DeletionRequest;
use OCA\OpenRegister\Service\Object\DeletionRun;
use OCA\OpenRegister\Service\Object\RemovalOptions;
use PHPUnit\Framework\TestCase;

class DeletionRequestTest extends TestCase {

	public function testARequestDefaultsToARootSoftDeleteWithTheLegacyLookup(): void {
		$request = new DeletionRequest(register: 1, schema: 'slug', uuid: 'u-1');

		$this->assertTrue($request->options->isRoot());
		$this->assertFalse($request->options->scoped);
		$this->assertNull($request->options->preResolved);
		$this->assertFalse($request->options->permanent);
	}//end testARequestDefaultsToARootSoftDeleteWithTheLegacyLookup()

	public function testACascadeSubDeletionIsNotARoot(): void {
		$this->assertFalse((new DeletionOptions(originalObjectId: 'parent'))->isRoot());
	}//end testACascadeSubDeletionIsNotARoot()

	public function testRemovalOptionsDefaultToASoftUntaggedRemovalWithoutATable(): void {
		$options = new RemovalOptions();

		$this->assertNull($options->cascadeContext);
		$this->assertFalse($options->permanent);
		$this->assertNull($options->table);
	}//end testRemovalOptionsDefaultToASoftUntaggedRemovalWithoutATable()

	public function testARunWithConcreteEntitiesHasATableAndATriggerSlug(): void {
		$object   = new ObjectEntity();
		$register = new Register();
		$schema   = new Schema();
		$schema->setSlug('cases');

		$run = new DeletionRun(context: ['object' => $object, 'register' => $register, 'schema' => $schema], uuid: 'u-1');

		$this->assertSame($object, $run->object());
		$this->assertSame($register, $run->table()?->register);
		$this->assertSame($schema, $run->table()?->schema);
		$this->assertSame('cases', $run->triggerSlug());
	}//end testARunWithConcreteEntitiesHasATableAndATriggerSlug()

	public function testARunWithIdentifiersOnlyHasNoTableAndNoTriggerSlug(): void {
		$run = new DeletionRun(context: ['object' => new ObjectEntity(), 'register' => 1, 'schema' => 2], uuid: 'u-1');

		$this->assertNull($run->table());
		$this->assertNull($run->triggerSlug());
		$this->assertNull((new DeletionRun(context: ['object' => new ObjectEntity(), 'register' => new Register()], uuid: 'u-1'))->table());
	}//end testARunWithIdentifiersOnlyHasNoTableAndNoTriggerSlug()
}//end class

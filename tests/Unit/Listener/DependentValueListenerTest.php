<?php

/**
 * The dependent value table on the save path: what it stops, what it lets
 * through, and what it costs a schema that declares none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\DependentValueListener;
use OCA\OpenRegister\Service\Rules\DependentValueTable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-REO-005 on the one evaluation point.
 *
 * @package OCA\OpenRegister\Tests\Unit\Listener
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
class DependentValueListenerTest extends TestCase {

	/**
	 * A schema whose `resultaat` follows its `zaaktype`.
	 *
	 * @param bool $withTable False for a schema declaring no table at all.
	 *
	 * @return Schema The schema.
	 */
	private function schema(bool $withTable = true): Schema {
		$properties = [
			'zaaktype' => ['type' => 'string', 'enum' => ['bezwaar', 'klacht']],
			'resultaat' => ['type' => 'string', 'enum' => ['gegrond', 'ongegrond', 'verleend']],
		];

		if ($withTable === true) {
			$properties['resultaat'][DependentValueTable::ANNOTATION] = [
				'controlledBy' => 'zaaktype',
				'allowed' => ['bezwaar' => ['gegrond', 'ongegrond']],
			];
		}

		$schema = new Schema();
		$schema->setId(11);
		$schema->setUuid('11111111-1111-1111-1111-111111111111');
		$schema->setSlug('bezwaar');
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * An object entity carrying the given data.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('11111111-1111-1111-1111-111111111111');
		$object->setObject($data);

		return $object;
	}//end entity()

	/**
	 * The listener over a mapper that answers with the given schema.
	 *
	 * @param Schema $schema The schema the mapper answers with.
	 *
	 * @return DependentValueListener The listener.
	 */
	private function listener(Schema $schema): DependentValueListener {
		$mapper = $this->createMock(originalClassName: SchemaMapper::class);
		$mapper->method('find')->willReturn($schema);

		return new DependentValueListener(
			tables: new DependentValueTable(),
			schemas: $mapper,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A create outside the table is refused, naming both properties.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testACreateOutsideTheTableIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(['zaaktype' => 'bezwaar', 'resultaat' => 'verleend']));
		$this->listener($this->schema())->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(DependentValueListener::ERROR_CODE, $event->getErrors()['code']);
		$this->assertStringContainsString('resultaat', $event->getErrors()['message']);
		$this->assertStringContainsString('zaaktype', $event->getErrors()['message']);
	}//end testACreateOutsideTheTableIsRefused()

	/**
	 * A create inside the table proceeds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testACreateInsideTheTableProceeds(): void {
		$event = new ObjectCreatingEvent($this->entity(['zaaktype' => 'bezwaar', 'resultaat' => 'gegrond']));
		$this->listener($this->schema())->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testACreateInsideTheTableProceeds()

	/**
	 * An update that changes only the dependent property is judged against
	 * the zaaktype the object already has.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAnUpdateIsJudgedAgainstTheStoredControllingValue(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['resultaat' => 'verleend']),
			$this->entity(['zaaktype' => 'bezwaar', 'resultaat' => 'gegrond'])
		);
		$this->listener($this->schema())->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAnUpdateIsJudgedAgainstTheStoredControllingValue()

	/**
	 * 🔴 THE REGRESSION OF TASK 5.3, at this gate. A schema declaring no table
	 * is not refused, and the guard never asks the store anything about it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testASchemaWithNoTableIsUntouched(): void {
		$event = new ObjectCreatingEvent($this->entity(['zaaktype' => 'klacht', 'resultaat' => 'verleend']));
		$this->listener($this->schema(withTable: false))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testASchemaWithNoTableIsUntouched()

	/**
	 * The guard failing for a reason of its own does not stop the save.
	 *
	 * A table that cannot be read must not become the reason nothing in the
	 * register can be written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testTheGuardFailingDoesNotStopTheSave(): void {
		$mapper = $this->createMock(originalClassName: SchemaMapper::class);
		$mapper->method('find')->willThrowException(new \RuntimeException('no schema'));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$listener = new DependentValueListener(
			tables: new DependentValueTable(),
			schemas: $mapper,
			logger: $logger
		);

		$event = new ObjectCreatingEvent($this->entity(['zaaktype' => 'bezwaar', 'resultaat' => 'verleend']));
		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testTheGuardFailingDoesNotStopTheSave()

	/**
	 * The listener is subscribed to BOTH write events.
	 *
	 * 🔴 REQ-REO-004's property at this gate: a create-only subscription would
	 * leave every update unguarded and every test above would still pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testTheListenerIsSubscribedToBothWriteEvents(): void {
		$application = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertIsString($application);
		$this->assertStringContainsString(
			'registerEventListener(ObjectCreatingEvent::class, DependentValueListener::class)',
			$application
		);
		$this->assertStringContainsString(
			'registerEventListener(ObjectUpdatingEvent::class, DependentValueListener::class)',
			$application
		);
	}//end testTheListenerIsSubscribedToBothWriteEvents()
}//end class

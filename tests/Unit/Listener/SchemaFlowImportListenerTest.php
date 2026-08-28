<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Listener\SchemaFlowImportListener;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * `x-openregister-flows` materialises into the flow store on schema save.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class SchemaFlowImportListenerTest extends TestCase {
	/**
	 * @var array<int, Flow>
	 */
	private array $inserted = [];

	/**
	 * @var array<int, Flow>
	 */
	private array $updated = [];

	/**
	 * Build the listener over a store pre-loaded with $existing.
	 *
	 * @param array<int, Flow> $existing Flows already in the store.
	 *
	 * @return SchemaFlowImportListener The listener.
	 */
	private function listener(array $existing = [], ?ContainerInterface $container = null): SchemaFlowImportListener {
		$this->inserted = [];
		$this->updated = [];

		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willReturn($existing);
		$mapper->method('insert')->willReturnCallback(function (Flow $f): Flow {
			$this->inserted[] = $f;
			return $f;
		});
		$mapper->method('update')->willReturnCallback(function (Flow $f): Flow {
			$this->updated[] = $f;
			return $f;
		});

		return new SchemaFlowImportListener($mapper, new \Psr\Log\NullLogger(), $container);
	}

	/**
	 * A container whose OrganisationService answers with $uuid.
	 *
	 * @param string|null $uuid  The active organisation's uuid, or null for
	 *                           "there is an OrganisationService but no active
	 *                           organisation".
	 * @param boolean     $blows Whether resolving it throws, which models an
	 *                           instance where the service is not registered.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(?string $uuid, bool $blows = false): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);

		if ($blows === true) {
			$container->method('get')->willThrowException(new \RuntimeException('not registered'));
			return $container;
		}

		$organisation = null;
		if ($uuid !== null) {
			$organisation = new class($uuid) {
				public function __construct(private string $uuid) {
				}

				public function getUuid(): string {
					return $this->uuid;
				}
			};
		}

		$service = new class($organisation) {
			public function __construct(private ?object $organisation) {
			}

			public function getActiveOrganisation(): ?object {
				return $this->organisation;
			}
		};

		$container->method('get')->willReturn($service);

		return $container;
	}

	/**
	 * A schema carrying the given declarations.
	 *
	 * @param array<int, mixed> $declared The declared flows.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $declared): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration(['x-openregister-flows' => $declared]);

		return $schema;
	}

	private function fire(SchemaFlowImportListener $listener, Schema $schema): void {
		$listener->handle(new SchemaCreatedEvent($schema));
	}

	public function testADeclaredFlowIsImported(): void {
		$listener = $this->listener();
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'trigger' => 'object.created', 'nodes' => [['id' => 'a']], 'edges' => []],
		]));

		$this->assertCount(1, $this->inserted);
		$this->assertSame('Triage', $this->inserted[0]->getName());
		$this->assertSame('case', $this->inserted[0]->getTriggerSchema());
		$this->assertSame('object.created', $this->inserted[0]->getTrigger());
	}

	/**
	 * THE SAFETY PROPERTY. A schema save is not a person volunteering to run a
	 * graph as themselves, so a declared flow arrives inert.
	 */
	public function testADeclaredFlowArrivesDisabledAndOwnerless(): void {
		$listener = $this->listener();
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'enabled' => true, 'owner' => 'admin', 'nodes' => [], 'edges' => []],
		]));

		$flow = $this->inserted[0];
		$this->assertFalse((bool)$flow->getEnabled(), 'a declaration must not arrive enabled');
		$this->assertNull($flow->getOwner(), 'a declaration must not name its own owner');
		$this->assertFalse($flow->canDispatch());
	}

	/**
	 * A re-import UPDATES rather than duplicating — a declaration carries no
	 * uuid, so identity is (app, name, trigger schema).
	 */
	public function testAReimportUpdatesRatherThanDuplicating(): void {
		$existing = new Flow();
		$existing->setUuid('u1');
		$existing->setApp('openregister');
		$existing->setName('Triage');
		$existing->setTriggerSchema('case');
		$existing->setEnabled(true);
		$existing->setOwner('alice');

		$listener = $this->listener([$existing]);
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'nodes' => [['id' => 'b']], 'edges' => []],
		]));

		$this->assertCount(0, $this->inserted, 'a second copy must not be created');
		$this->assertCount(1, $this->updated);
		$this->assertSame([['id' => 'b']], $this->updated[0]->getNodes(), 'the graph is refreshed');
	}

	/**
	 * An app upgrade must not silently re-enable a flow an administrator turned
	 * off, nor re-point whose identity it runs as.
	 */
	public function testAReimportDoesNotResurrectEnabledOrOwner(): void {
		$existing = new Flow();
		$existing->setUuid('u1');
		$existing->setApp('openregister');
		$existing->setName('Triage');
		$existing->setTriggerSchema('case');
		$existing->setEnabled(false);
		$existing->setOwner('alice');

		$listener = $this->listener([$existing]);
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'enabled' => true, 'owner' => 'mallory', 'nodes' => [], 'edges' => []],
		]));

		$this->assertFalse((bool)$this->updated[0]->getEnabled(), 'an upgrade must not re-enable it');
		$this->assertSame('alice', $this->updated[0]->getOwner(), 'an upgrade must not re-point the owner');
	}

	public function testASchemaWithNoDeclarationImportsNothing(): void {
		$listener = $this->listener();
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration([]);

		$this->fire($listener, $schema);

		$this->assertSame([], $this->inserted);
	}

	/**
	 * One malformed declaration must not stop its siblings arriving, nor fail
	 * the schema save it rode in on.
	 */
	public function testAMalformedDeclarationIsSkippedNotFatal(): void {
		$listener = $this->listener();
		$this->fire($listener, $this->schema([
			['trigger' => 'object.created'],
			['name' => 'Good', 'nodes' => [], 'edges' => []],
		]));

		$this->assertCount(1, $this->inserted);
		$this->assertSame('Good', $this->inserted[0]->getName());
	}

	/**
	 * 🔴 AN IMPORTED FLOW MUST BELONG TO A TENANT.
	 *
	 * Every flow READ is organisation-scoped, so a flow stored with a null
	 * organisation is returned by nothing: it never appears in the flows list,
	 * so it can never be opened, so it can never be adopted — while sitting
	 * perfectly intact in the table. Measured 2026-08-28 against a live
	 * instance: the first shipped `x-openregister-flows` declaration was
	 * invisible to `/api/flows`, next to two seeded flows that carried an
	 * organisation and listed fine.
	 */
	public function testAnImportedFlowIsStampedWithTheActiveOrganisation(): void {
		$listener = $this->listener([], $this->container('org-1'));
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'nodes' => [], 'edges' => []],
		]));

		$this->assertSame('org-1', $this->inserted[0]->getOrganisation());
	}

	/**
	 * With no container the flow still imports.
	 *
	 * The listener must stay constructible on an instance where
	 * OrganisationService is not registered: refusing the import would turn a
	 * missing optional service into a failed schema save.
	 */
	public function testWithNoContainerTheFlowStillImportsWithoutAnOrganisation(): void {
		$listener = $this->listener();
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'nodes' => [], 'edges' => []],
		]));

		$this->assertCount(1, $this->inserted);
		$this->assertNull($this->inserted[0]->getOrganisation());
	}

	/**
	 * A container that cannot resolve the service is not fatal either.
	 */
	public function testAnUnresolvableOrganisationServiceIsNotFatal(): void {
		$listener = $this->listener([], $this->container(null, blows: true));
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'nodes' => [], 'edges' => []],
		]));

		$this->assertCount(1, $this->inserted);
		$this->assertNull($this->inserted[0]->getOrganisation());
	}

	/**
	 * A registered service with NO active organisation resolves to null rather
	 * than to the empty string, which would be a real-looking tenant that owns
	 * nothing.
	 */
	public function testNoActiveOrganisationResolvesToNullNotAnEmptyString(): void {
		$listener = $this->listener([], $this->container(null));
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'nodes' => [], 'edges' => []],
		]));

		$this->assertNull($this->inserted[0]->getOrganisation());
	}

	/**
	 * An empty uuid is treated as no organisation for the same reason.
	 */
	public function testAnEmptyUuidResolvesToNull(): void {
		$listener = $this->listener([], $this->container(''));
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'nodes' => [], 'edges' => []],
		]));

		$this->assertNull($this->inserted[0]->getOrganisation());
	}

	/**
	 * 🔴 A RE-IMPORT MUST NOT RE-STAMP THE ORGANISATION.
	 *
	 * The stamp sits in the create branch beside `enabled`/`owner` precisely so
	 * an upgrade running as a different tenant cannot move an already-adopted
	 * flow out from under the organisation using it.
	 */
	public function testAReimportDoesNotMoveAnExistingFlowToAnotherOrganisation(): void {
		$existing = new Flow();
		$existing->setUuid('u1');
		$existing->setApp('openregister');
		$existing->setName('Triage');
		$existing->setTriggerSchema('case');
		$existing->setOrganisation('org-owner');

		$listener = $this->listener([$existing], $this->container('org-upgrader'));
		$this->fire($listener, $this->schema([
			['name' => 'Triage', 'nodes' => [], 'edges' => []],
		]));

		$this->assertSame('org-owner', $this->updated[0]->getOrganisation());
	}
}

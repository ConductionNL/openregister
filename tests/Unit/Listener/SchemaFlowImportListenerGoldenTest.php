<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowPublishOptions;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\SchemaFlowImportListener;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden recording of the declared-flow import: which flows are created or
 * updated, under which organisation (tenant), which fields an update may and
 * may not touch, which container services are asked, and what is logged.
 * Recorded on the code before the A29 refactor.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class SchemaFlowImportListenerGoldenTest extends TestCase {

	/**
	 * Everything observed, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The listener over a store holding $existing, with a container answering the given parts.
	 *
	 * @param array<int, Flow> $existing Flows already stored.
	 * @param string|null $container 'none', or a mode: 'ok', 'org-throws', 'org-empty', 'publish-throws'.
	 *
	 * @return SchemaFlowImportListener The listener.
	 */
	private function listener(array $existing, ?string $container): SchemaFlowImportListener {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willReturnCallback(
			function (FlowSelection $selection, ListQuery $query) use ($existing): array {
				$this->trace[] = ['findAllFlows', $selection->app, $selection->applicationSlug, $selection->organisation, $selection->enabled, $query->limit, ($query->offset ?? 0)];

				return $existing;
			}
		);
		foreach (['insert', 'update'] as $verb) {
			$mapper->method($verb)->willReturnCallback(
				function (Flow $flow) use ($verb): Flow {
					$this->trace[] = [$verb, self::fieldsOf(flow: $flow)];

					return $flow;
				}
			);
		}

		$trace = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Record into the trace.
			 *
			 * @param array<int, mixed> $trace The trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record one log call without file/line.
			 *
			 * @param mixed $level The level.
			 * @param \Stringable|string $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, \Stringable|string $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$uuid = '/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/';
				if (isset($context['flow']) === true) {
					$context['flow'] = preg_replace($uuid, 'UUID', (string)$context['flow']);
				}

				$this->trace[] = ['log', $level, preg_replace($uuid, 'UUID', (string)$message), $context];
			}
		};

		$resolved = null;
		if ($container !== 'none' && $container !== null) {
			$resolved = $this->container(mode: $container);
		}

		return new SchemaFlowImportListener($mapper, $logger, $resolved);
	}//end listener()

	/**
	 * A container whose services behave as the mode says.
	 *
	 * @param string $mode The mode.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(string $mode): ContainerInterface {
		$organisation = new class($mode) {
			/**
			 * Remember the mode.
			 *
			 * @param string $mode The mode.
			 */
			public function __construct(private string $mode) {
			}

			/**
			 * The organisation for a new entity.
			 *
			 * @return string|null The uuid.
			 */
			public function getOrganisationForNewEntity(): ?string {
				if ($this->mode === 'org-throws') {
					throw new RuntimeException('no org service');
				}

				if ($this->mode === 'org-empty') {
					return '';
				}

				return 'org-1';
			}
		};
		$versions = $this->createMock(FlowVersionServiceInterface::class);
		if ($mode === 'publish-throws') {
			$versions->method('publish')->willThrowException(new RuntimeException('cannot publish'));
			$versions->method('publishShippedUpdate')->willThrowException(new RuntimeException('cannot publish'));
		} else {
			$versions->method('publish')->willReturnCallback(
				function (Flow $flow, FlowPublishOptions $options) {
					$publishedBy = $options->publishedBy;
					$this->trace[] = ['publish', $flow->getName(), $publishedBy];

					return new FlowVersion();
				}
			);
			$versions->method('publishShippedUpdate')->willReturnCallback(
				function (Flow $flow) {
					$this->trace[] = ['publishShippedUpdate', $flow->getName()];
					$version = new FlowVersion();
					$version->setVersion(3);

					return $version;
				}
			);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($organisation, $versions): object {
				$this->trace[] = ['get', $id];

				return match ($id) {
					FlowVersionServiceInterface::class => $versions,
					default => $organisation,
				};
			}
		);

		return $container;
	}//end container()

	/**
	 * The observable fields of a stored flow; uuid and timestamps as presence only.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private static function fieldsOf(Flow $flow): array {
		return [
			'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$flow->getUuid()),
			'app' => $flow->getApp(),
			'name' => $flow->getName(),
			'triggerSchema' => $flow->getTriggerSchema(),
			'enabled' => $flow->getEnabled(),
			'owner' => $flow->getOwner(),
			'organisation' => $flow->getOrganisation(),
			'description' => $flow->getDescription(),
			'trigger' => $flow->getTrigger(),
			'triggerRegister' => $flow->getTriggerRegister(),
			'cron' => $flow->getCron(),
			'executionMode' => $flow->getExecutionMode(),
			'nodes' => $flow->getNodes(),
			'edges' => $flow->getEdges(),
			'limits' => $flow->getLimits(),
			'created' => $flow->getCreated() !== null,
			'updated' => $flow->getUpdated() !== null,
		];
	}//end fieldsOf()

	/**
	 * A schema declaring the given flows.
	 *
	 * @param mixed $declared The declaration.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(mixed $declared): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration(['x-openregister-flows' => $declared]);

		return $schema;
	}//end schema()

	/**
	 * A stored flow.
	 *
	 * @param string $name The name.
	 * @param string $triggerSchema The trigger schema.
	 *
	 * @return Flow The flow.
	 */
	private static function stored(string $name, string $triggerSchema): Flow {
		$flow = new Flow();
		$flow->setUuid('11111111-1111-4111-8111-111111111111');
		$flow->setApp('procest');
		$flow->setName($name);
		$flow->setTriggerSchema($triggerSchema);
		$flow->setEnabled(true);
		$flow->setOwner('admin');
		$flow->setOrganisation('org-old');

		return $flow;
	}//end stored()

	/**
	 * First import: a new, disabled, ownerless flow stamped with the organisation for new entities, published as nobody.
	 *
	 * @return void
	 */
	public function testFirstImport(): void {
		$declared = [
			'not a declaration',
			['name' => '  Intake  ', 'description' => 'd', 'trigger' => 'object.created', 'cron' => '* * * * *', 'nodes' => ['n'], 'edges' => 'e', 'limits' => null],
			['name' => ' '],
		];
		$this->listener(existing: [], container: 'ok')->handle(new SchemaCreatedEvent(self::schema(declared: $declared)));

		$this->assertSame(
			[
				['findAllFlows', 'openregister', null, null, null, 500, 0],
				['get', 'OCA\OpenRegister\Service\OrganisationServiceInterface'],
				['insert', ['uuid' => 1, 'app' => 'openregister', 'name' => 'Intake', 'triggerSchema' => 'case', 'enabled' => false, 'owner' => null, 'organisation' => 'org-1', 'description' => 'd', 'trigger' => 'object.created', 'triggerRegister' => null, 'cron' => '* * * * *', 'executionMode' => 'async', 'nodes' => ['n'], 'edges' => ['e'], 'limits' => [], 'created' => true, 'updated' => true]],
				['get', FlowVersionServiceInterface::class],
				['publish', 'Intake', null],
				['log', 'info', '[SchemaFlowImport] Imported declared flow "Intake" for schema "case" (disabled until adopted).', []],
				['log', 'warning', '[SchemaFlowImport] Could not import a declared flow on schema "case": a declared flow needs a name', []],
			],
			$this->trace
		);
	}//end testFirstImport()

	/**
	 * A re-import updates the matching flow (same name AND trigger schema), keeps enabled, owner and organisation, and asks for a shipped-update publish.
	 *
	 * @return void
	 */
	public function testReimportUpdatesOnlyTheMatch(): void {
		$existing = [self::stored(name: 'Intake', triggerSchema: 'other'), self::stored(name: 'Intake', triggerSchema: 'case')];
		$declared = [['name' => 'Intake', 'app' => 'procest', 'executionMode' => 'sync', 'triggerRegister' => 'r']];
		$old = self::schema(declared: []);
		$this->listener(existing: $existing, container: 'ok')->handle(new SchemaUpdatedEvent(self::schema(declared: $declared), $old));

		$this->assertSame(
			[
				['findAllFlows', 'procest', null, null, null, 500, 0],
				['update', ['uuid' => 1, 'app' => 'procest', 'name' => 'Intake', 'triggerSchema' => 'case', 'enabled' => true, 'owner' => 'admin', 'organisation' => 'org-old', 'description' => null, 'trigger' => null, 'triggerRegister' => 'r', 'cron' => null, 'executionMode' => 'sync', 'nodes' => [], 'edges' => [], 'limits' => [], 'created' => false, 'updated' => true]],
				['get', FlowVersionServiceInterface::class],
				['publishShippedUpdate', 'Intake'],
				['log', 'info', '[SchemaFlowImport] Published the updated declaration of flow "UUID" as version 3.', []],
			],
			$this->trace
		);
	}//end testReimportUpdatesOnlyTheMatch()

	/**
	 * Failing services never fail the import: an unresolvable or empty organisation stamps none, a failing publish is logged.
	 *
	 * @return void
	 */
	public function testFailingServicesAreNotFatal(): void {
		$this->listener(existing: [], container: 'org-throws')->handle(new SchemaCreatedEvent(self::schema(declared: [['name' => 'A']])));
		$this->listener(existing: [], container: 'org-empty')->handle(new SchemaCreatedEvent(self::schema(declared: [['name' => 'B']])));
		$this->listener(existing: [], container: 'publish-throws')->handle(new SchemaCreatedEvent(self::schema(declared: [['name' => 'C']])));
		$this->listener(existing: [self::stored(name: 'D', triggerSchema: 'case')], container: 'publish-throws')
			->handle(new SchemaCreatedEvent(self::schema(declared: [['name' => 'D', 'app' => 'procest']])));

		$organisations = [];
		$logs = [];
		foreach ($this->trace as $entry) {
			if ($entry[0] === 'insert') {
				$organisations[] = $entry[1]['organisation'];
			}

			if ($entry[0] === 'log') {
				$logs[] = [$entry[1], $entry[2], $entry[3]];
			}
		}

		$this->assertSame([null, null, 'org-1'], $organisations);
		$this->assertSame(
			[
				['warning', '[SchemaFlowImport] Could not resolve an organisation for a declared flow; it will import but stay unlisted: no org service', []],
				['info', '[SchemaFlowImport] Imported declared flow "A" for schema "case" (disabled until adopted).', []],
				['info', '[SchemaFlowImport] Imported declared flow "B" for schema "case" (disabled until adopted).', []],
				['warning', '[SchemaFlowImport] Imported flow "UUID" could not be published: cannot publish. It exists as a draft and will not back a run until it is published.', ['flow' => 'UUID']],
				['info', '[SchemaFlowImport] Imported declared flow "C" for schema "case" (disabled until adopted).', []],
				['warning', '[SchemaFlowImport] The updated declaration of flow "UUID" could not be published: cannot publish. The previous version keeps serving.', ['flow' => 'UUID']],
			],
			$logs
		);
	}//end testFailingServicesAreNotFatal()

	/**
	 * No container: the flow imports without an organisation and is not published.
	 *
	 * @return void
	 */
	public function testNoContainer(): void {
		$this->listener(existing: [], container: 'none')->handle(new SchemaCreatedEvent(self::schema(declared: [['name' => 'A']])));
		$this->listener(existing: [self::stored(name: 'A', triggerSchema: 'case')], container: 'none')
			->handle(new SchemaCreatedEvent(self::schema(declared: [['name' => 'A', 'app' => 'procest']])));

		$this->assertSame(['findAllFlows', 'insert', 'log', 'findAllFlows', 'update'], array_column($this->trace, 0));
		$this->assertNull($this->trace[1][1]['organisation']);
	}//end testNoContainer()

	/**
	 * Nothing declared, a non-list declaration, or another event: nothing is read.
	 *
	 * @return void
	 */
	public function testNothingToImport(): void {
		$listener = $this->listener(existing: [], container: 'ok');
		$listener->handle(new SchemaCreatedEvent(self::schema(declared: [])));
		$listener->handle(new SchemaCreatedEvent(self::schema(declared: 'x')));
		$listener->handle(new Event());

		$this->assertSame([], $this->trace);
	}//end testNothingToImport()
}//end class

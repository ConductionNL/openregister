<?php

/**
 * Materialises a schema's declared flows into the flow store.
 *
 * `x-openregister-flows` is how an app SHIPS a flow in its register file, the
 * same way it ships a lifecycle or a notification (ADR-031). Every other
 * `x-openregister-*` extension is read from the schema at runtime, so declaring
 * it is enough. Flows are different: they live in their own table, because that
 * is what gives them run history, retention, oversight and a builder. So a
 * declaration has to be IMPORTED rather than merely read.
 *
 * Importing on schema save — rather than only on register import — means a flow
 * declared by an app and a flow authored in the builder end up in exactly the
 * same place, and a register re-import updates rather than duplicates.
 *
 * A declared flow arrives DISABLED and OWNERLESS. A schema save is not a person
 * volunteering to run a graph as themselves: the acting user may be an admin
 * doing an upgrade, and a flow's owner is the identity its runs execute as.
 * Adopting a flow is a deliberate act, so it is stored, visible and inert until
 * somebody makes it theirs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Db\ListQuery;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports `x-openregister-flows` declarations into the flow store.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class SchemaFlowImportListener implements IEventListener, SchemaFlowImportListenerInterface {
	/**
	 * The configuration key carrying declared flows.
	 *
	 * @var string
	 */
	public const ANNOTATION_KEY = 'x-openregister-flows';

	/**
	 * What a flow that could not be published is said to leave in place, by publish kind:
	 * the message up to the flow uuid, and the sentence after the error.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const UNPUBLISHED = [
		'versionOne' => [
			'[SchemaFlowImport] Imported flow "',
			'. It exists as a draft and will not back a run until it is published.',
		],
		'shippedUpdate' => [
			'[SchemaFlowImport] The updated declaration of flow "',
			'. The previous version keeps serving.',
		],
	];

	/**
	 * The warning logged when no organisation can be resolved for a declared flow, before the error.
	 *
	 * @var string
	 */
	private const NO_ORGANISATION = '[SchemaFlowImport] Could not resolve an organisation for a declared flow; '
		. 'it will import but stay unlisted: ';

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface $flows The flow store.
	 * @param LoggerInterface $logger Records what was imported, and what could not be.
	 * @param ContainerInterface|null $container Resolves the organisation a declared flow
	 *                                           belongs to. Nullable and LAST so adding it
	 *                                           shifts no positional caller; absent, the
	 *                                           flow still imports but stays unlisted,
	 *                                           which is logged rather than silent.
	 */
	public function __construct(
		private readonly FlowMapperInterface $flows,
		private readonly LoggerInterface $logger,
		private readonly ?ContainerInterface $container = null,
	) {

	}//end __construct()

	/**
	 * Import on schema create and update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function handle(Event $event): void {
		$schema = null;
		if ($event instanceof SchemaCreatedEvent) {
			$schema = $event->getSchema();
		}

		if ($event instanceof SchemaUpdatedEvent) {
			$schema = $event->getNewSchema();
		}

		if ($schema === null) {
			return;
		}

		$this->importFor(schema: $schema);

	}//end handle()

	/**
	 * Import every flow this schema declares.
	 *
	 * A declaration that fails is logged and SKIPPED rather than aborting the
	 * rest: a schema save must not fail because one of its flows is malformed,
	 * and one bad flow must not stop its siblings from arriving.
	 *
	 * @param Schema $schema The schema that was saved.
	 *
	 * @return void
	 *
	 * Public so the configuration import can run it for a schema whose save
	 * changed nothing, and so fired no SchemaUpdatedEvent: a shipped flow an
	 * administrator deleted, or one declared before this importer existed,
	 * still arrives on the next import.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-an-import-of-an-unchanged-schema-still-installs-what-it-declares
	 */
	public function importFor(Schema $schema): void {
		$declared = (($schema->getConfiguration() ?? [])[self::ANNOTATION_KEY] ?? null);
		if (is_array($declared) === false || $declared === []) {
			return;
		}

		$schemaSlug = (string)$schema->getSlug();
		foreach (array_filter($declared, 'is_array') as $declaration) {
			$this->importOne(declaration: $declaration, schemaSlug: $schemaSlug);
		}

	}//end importFor()

	/**
	 * Import one declaration; a failure is logged and the declaration skipped.
	 *
	 * @param array<string, mixed> $declaration The declared flow.
	 * @param string $schemaSlug The declaring schema's slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function importOne(array $declaration, string $schemaSlug): void {
		try {
			$this->upsert(declaration: $declaration, schemaSlug: $schemaSlug);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SchemaFlowImport] Could not import a declared flow on schema "'
					. $schemaSlug . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end importOne()

	/**
	 * Create or update the stored flow for one declaration.
	 *
	 * Identity is (app, name, trigger schema). A register re-import must UPDATE
	 * the flow it shipped last time rather than adding a second copy — the
	 * declaration has no uuid to match on, because the app author does not mint
	 * one and two instances importing the same register must not collide on it.
	 *
	 * `enabled` and `owner` are NOT taken from the declaration on update, so an
	 * app upgrade cannot silently re-enable a flow an administrator switched
	 * off, nor re-point whose identity it runs as.
	 *
	 * @param array<string, mixed> $declaration The declared flow.
	 * @param string $schemaSlug The declaring schema's slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function upsert(array $declaration, string $schemaSlug): void {
		$run = new DeclaredFlowRun();
		$run->name = self::declaredName(declaration: $declaration);
		$run->app = (string)($declaration['app'] ?? 'openregister');
		$run->schemaSlug = $schemaSlug;
		$existing = $this->findDeclared(run: $run);
		$flow = ($existing ?? $this->newFlow(run: $run));
		self::applyDeclaration(flow: $flow, declaration: $declaration);

		if ($existing === null) {
			$this->insertDeclared(flow: $flow, run: $run);
			return;
		}

		$this->flows->update($flow);
		$this->publishShippedUpdate(flow: $flow);

	}//end upsert()

	/**
	 * The trimmed name of a declared flow.
	 *
	 * @param array<string, mixed> $declaration The declared flow.
	 *
	 * @return string The name.
	 *
	 * @throws InvalidArgumentException When the declaration has no name.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private static function declaredName(array $declaration): string {
		$name = trim((string)($declaration['name'] ?? ''));
		if ($name === '') {
			throw new InvalidArgumentException('a declared flow needs a name');
		}

		return $name;
	}//end declaredName()

	/**
	 * The stored flow this declaration shipped last time: same app, name and trigger schema.
	 *
	 * @param DeclaredFlowRun $run The declaring app, flow name and declaring schema's slug.
	 *
	 * @return Flow|null The flow, or null on a first import.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function findDeclared(DeclaredFlowRun $run): ?Flow {
		foreach ($this->flows->findAllFlows(selection: new FlowSelection(app: $run->app), query: new ListQuery(limit: 500)) as $candidate) {
			if ($candidate->getName() === $run->name && (string)$candidate->getTriggerSchema() === $run->schemaSlug) {
				return $candidate;
			}
		}

		return null;
	}//end findDeclared()

	/**
	 * A new flow for a first import: inert until adopted, and owned by a tenant.
	 *
	 * @param DeclaredFlowRun $run The declaring app, flow name and declaring schema's slug.
	 *
	 * @return Flow The new, not yet stored flow.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function newFlow(DeclaredFlowRun $run): Flow {
		$flow = new Flow();
		$flow->setUuid($this->newUuid());
		$flow->setApp($run->app);
		$flow->setName($run->name);
		$flow->setTriggerSchema($run->schemaSlug);
		$flow->setCreated(new DateTime());

		// Inert until adopted. See the class docblock.
		$flow->setEnabled(false);
		$flow->setOwner(null);

		// 🔴 AND IT MUST BELONG TO A TENANT, or it is imported into
		// invisibility. Every flow READ is organisation-scoped
		// (`FlowService::findAll()`), so a flow stored with no organisation
		// is returned by nothing: it does not appear in the flows list, so
		// it cannot be opened, so it can never be adopted — while sitting
		// perfectly intact in the table. Measured 2026-08-28: the first
		// shipped `x-openregister-flows` declaration imported with
		// organisation NULL and was invisible to the API that lists it,
		// next to two seeded flows that carried one and showed up fine.
		$flow->setOrganisation($this->activeOrganisation());

		return $flow;
	}//end newFlow()

	/**
	 * Copy the declared graph and settings onto the flow (never enabled, owner or organisation).
	 *
	 * @param Flow $flow The flow being imported.
	 * @param array<string, mixed> $declaration The declared flow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private static function applyDeclaration(Flow $flow, array $declaration): void {
		$flow->setDescription(($declaration['description'] ?? null));
		$flow->setTrigger(($declaration['trigger'] ?? null));
		$flow->setTriggerRegister(($declaration['triggerRegister'] ?? null));
		$flow->setCron(($declaration['cron'] ?? null));
		$flow->setExecutionMode((string)($declaration['executionMode'] ?? Flow::MODE_ASYNC));
		$flow->setNodes((array)($declaration['nodes'] ?? []));
		$flow->setEdges((array)($declaration['edges'] ?? []));
		$flow->setLimits((array)($declaration['limits'] ?? []));
		$flow->setUpdated(new DateTime());
	}//end applyDeclaration()

	/**
	 * Store a first-imported flow, publish its version 1 and say so.
	 *
	 * @param Flow            $flow The new flow.
	 * @param DeclaredFlowRun $run  The flow name and declaring schema's slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function insertDeclared(Flow $flow, DeclaredFlowRun $run): void {
		$stored = $this->flows->insert($flow);
		$this->publishVersionOne(flow: $stored);
		$this->logger->info(
			message: '[SchemaFlowImport] Imported declared flow "' . $run->name . '" for schema "' . $run->schemaSlug
				. '" (disabled until adopted).'
		);
	}//end insertDeclared()

	/**
	 * Publish a changed declaration as the flow's next version.
	 *
	 * Runs walk the PUBLISHED version, so an app upgrade that fixed a shipped
	 * flow but only rewrote its head changed nothing that ran. The rules for
	 * when an upgrade may publish live in
	 * {@see FlowVersionServiceInterface::publishShippedUpdate()}.
	 *
	 * Never raises, for the same reason as {@see self::publishVersionOne()}:
	 * a flow must not abort a schema import. A refusal leaves the published
	 * version serving and the shipped graph on the head.
	 *
	 * @param Flow $flow The updated flow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shipped-flow-update-is-published/specs/flow-definition-versioning/spec.md#requirement-a-changed-shipped-flow-is-published-as-the-next-version
	 */
	private function publishShippedUpdate(Flow $flow): void {
		if ($this->container === null) {
			return;
		}

		try {
			$version = $this->container->get(FlowVersionServiceInterface::class)->publishShippedUpdate(flow: $flow);
			$this->logPublishedUpdate(flow: $flow, version: $version);
		} catch (Throwable $e) {
			$this->warnUnpublished(flow: $flow, wording: self::unpublishedWording(kind: 'shippedUpdate', error: $e));
		}

	}//end publishShippedUpdate()

	/**
	 * Say which version a shipped update was published as; nothing when it was not published.
	 *
	 * @param Flow $flow The updated flow.
	 * @param FlowVersion|null $version The published version, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shipped-flow-update-is-published/specs/flow-definition-versioning/spec.md#requirement-a-changed-shipped-flow-is-published-as-the-next-version
	 */
	private function logPublishedUpdate(Flow $flow, ?FlowVersion $version): void {
		if ($version !== null) {
			$this->logger->info(
				message: '[SchemaFlowImport] Published the updated declaration of flow "' . $flow->getUuid()
					. '" as version ' . $version->getVersion() . '.'
			);
		}
	}//end logPublishedUpdate()

	/**
	 * Warn that a flow could not be published, and what that leaves in place.
	 *
	 * @param Flow                       $flow    The flow.
	 * @param array{0: string, 1: string} $wording The message up to the flow uuid, and after it,
	 *                                            from {@see self::unpublishedWording()}.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function warnUnpublished(Flow $flow, array $wording): void {
		$this->logger->warning(
			message: $wording[0] . $flow->getUuid() . $wording[1],
			context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flow->getUuid()]
		);
	}//end warnUnpublished()

	/**
	 * The unpublished warning around the flow uuid: what precedes it, and the
	 * error plus what the failure leaves in place after it.
	 *
	 * @param string    $kind  A key of UNPUBLISHED.
	 * @param Throwable $error What went wrong.
	 *
	 * @return array{0: string, 1: string} The message up to the flow uuid, and after it.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private static function unpublishedWording(string $kind, Throwable $error): array {
		[$prefix, $consequence] = self::UNPUBLISHED[$kind];
		return [$prefix, '" could not be published: ' . $error->getMessage() . $consequence];
	}//end unpublishedWording()

	/**
	 * Publish version 1 of a freshly imported flow.
	 *
	 * 🔴 A SHIPPED FLOW MUST NOT ARRIVE AS AN UNRUNNABLE DRAFT. Since
	 * versioning, `FlowRunService::queue()` refuses any flow with no published
	 * version. An app that declares a flow in `x-openregister-flows` is
	 * shipping a finished process, not a work in progress — leaving it a draft
	 * would mean adopting it (enabling it) still ran nothing, with no error to
	 * explain why.
	 *
	 * Published, but still DISABLED and unowned: publishing answers "which
	 * graph would run", adoption answers "may it run at all". They are separate
	 * questions and this changes only the first.
	 *
	 * Never raises. A failure here leaves the flow importable and unpublished,
	 * which an operator can fix by publishing it; throwing would abort a schema
	 * import over a flow.
	 *
	 * @param Flow $flow The freshly inserted flow.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function publishVersionOne(Flow $flow): void {
		if ($this->container === null) {
			return;
		}

		try {
			$this->container->get(FlowVersionServiceInterface::class)->publish(flow: $flow);
		} catch (Throwable $e) {
			$this->warnUnpublished(flow: $flow, wording: self::unpublishedWording(kind: 'versionOne', error: $e));
		}

	}//end publishVersionOne()

	/**
	 * The organisation a newly imported flow belongs to.
	 *
	 * Resolved through the container rather than injected so this listener
	 * stays constructible where OrganisationService is not registered — a
	 * declared flow should still import on such an instance, even though a
	 * null organisation leaves it unlisted.
	 *
	 * @return string|null The organisation uuid, or null when unresolvable.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function activeOrganisation(): ?string {
		$uuid = (string)$this->organisationForNewEntity();
		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end activeOrganisation()

	/**
	 * What the organisation service answers for a new entity; null without a
	 * container or when the service cannot be reached (logged).
	 *
	 * @return mixed The organisation uuid as the service returns it.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function organisationForNewEntity(): mixed {
		if ($this->container === null) {
			return null;
		}

		try {
			// 🔴 `getOrganisationForNewEntity()`, NOT `getActiveOrganisation()`.
			// A schema import runs during install and during
			// `occ maintenance:repair` — with NO user session, so there is no
			// ACTIVE organisation to find and the flow imported ownerless and
			// invisible all over again. This is the same call every ordinary
			// object save makes, and it falls back to the DEFAULT organisation
			// exactly for callers with no session. Measured 2026-08-28: the fix
			// that resolved the active organisation passed on a dev stack that
			// happened to have one, and failed in CI, which does not.
			return $this->container
				->get('OCA\OpenRegister\Service\OrganisationServiceInterface')
				->getOrganisationForNewEntity();
		} catch (Throwable $e) {
			$this->logger->warning(message: self::NO_ORGANISATION . $e->getMessage());
			return null;
		}
	}//end organisationForNewEntity()

	/**
	 * Mint a v4 uuid.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function newUuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr(0x40 | (ord($bytes[6]) & 0x0f));
		$bytes[8] = chr(0x80 | (ord($bytes[8]) & 0x3f));

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end newUuid()
}//end class

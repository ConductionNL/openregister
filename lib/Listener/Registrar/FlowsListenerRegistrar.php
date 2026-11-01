<?php

/**
 * FlowsListenerRegistrar: the event listeners of the Flows component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Registrar;

use OCA\OpenRegister\ContextChat\ContentProviderRegistrationListener;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ViewAlertCrossedEvent;
use OCA\OpenRegister\Listener\ApprovalChainAdvanceListener;
use OCA\OpenRegister\Listener\CommentsEntityListener;
use OCA\OpenRegister\Listener\FileChangeListener;
use OCA\OpenRegister\Listener\FilesSidebarListener;
use OCA\OpenRegister\Listener\FlowEngineRegistrationListener;
use OCA\OpenRegister\Listener\HandoffQueueDrainListener;
use OCA\OpenRegister\Listener\MailAppScriptListener;
use OCA\OpenRegister\Listener\TablesTableDeletedListener;
use OCA\OpenRegister\Listener\ViewAlertCrossedListener;
use OCA\OpenRegister\Listener\WebhookEventListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Comments\CommentsEntityEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

/**
 * Registers the listeners of the Flows component on events no other component listens to.
 *
 * An event with listeners from more than one component stays in
 * ListenerRegistrar, in one ordered list, because listeners of one event run
 * in registration order.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every listener
 *   and every event it subscribes to; that coupling is what it is for.
 */
final class FlowsListenerRegistrar {

	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listeners carry their own specs.
	 */
	public static function register(IRegistrationContext $context): void {
		self::registerFileAndDiscoveryListeners(context: $context);
		self::registerTriggerListeners(context: $context);
		self::registerPageScriptListeners(context: $context);
	}//end register()

	/**
	 * Register the file, discovery and platform listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerFileAndDiscoveryListeners(IRegistrationContext $context): void {
		// FileChangeListener for automatic file text extraction.
		$context->registerEventListener(NodeCreatedEvent::class, FileChangeListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FileChangeListener::class);

		// REQ-OAS-007: a write into a frozen or archived object's folder
		// through Nextcloud Files or WebDAV is aborted; the files API refuses
		// through FileWriteGuard in FilesController.
		$context->registerEventListener(\OCP\Files\Events\Node\BeforeNodeWrittenEvent::class, \OCA\OpenRegister\Listener\FrozenNodeWriteListener::class);
		$context->registerEventListener(\OCP\Files\Events\Node\BeforeNodeCreatedEvent::class, \OCA\OpenRegister\Listener\FrozenNodeWriteListener::class);
		$context->registerEventListener(\OCP\Files\Events\Node\BeforeNodeDeletedEvent::class, \OCA\OpenRegister\Listener\FrozenNodeWriteListener::class);
		$context->registerEventListener(\OCP\Files\Events\Node\BeforeNodeRenamedEvent::class, \OCA\OpenRegister\Listener\FrozenNodeWriteListener::class);

		// Flow node discovery. OpenRegister contributes its own built-ins
		// through the same event every consuming app uses, so the contribution
		// path is exercised by its owner and cannot rot unnoticed.
		$context->registerEventListener(
			\OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class,
			\OCA\OpenRegister\Listener\FlowNodeRegistrationListener::class
		);

		// Flow oversight. Apps contribute the checks that may STOP a run — a
		// kill switch, a spend budget, a maintenance window — through the same
		// idiom as flow nodes. The engine hardcodes none of them, which is what
		// keeps app-specific safety logic out of it.
		//
		// There is deliberately no resolver event beside these two. Flows live
		// in one native table, so "which app owns this flow id" has a single
		// answer and `FlowLocator` reads it directly; the resolver registry
		// existed only to arbitrate between per-app object stores.
		$context->registerEventListener(
			\OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent::class,
			\OCA\OpenRegister\Listener\FlowOversightRegistrationListener::class
		);

		// Principal discovery. WHO a step may ask: `user` and `group` are
		// Nextcloud's own and are contributed here; a position on a body, a
		// function, a case role are contributed by the app that owns the
		// concept. None of that knowledge can move into the engine without it
		// growing opinions about municipal organisation charts.
		//
		// ⚠️ NOT the resolver registry the comment above disclaims. That one
		// arbitrated between per-app object stores for flow OWNERSHIP; this
		// one answers "who does this reference mean", which is a different
		// question with a different answer per instance.
		$context->registerEventListener(
			\OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent::class,
			\OCA\OpenRegister\Listener\PrincipalResolverRegistrationListener::class
		);

		// Party roles across a merge. `mdm-merge` owns the merge; the party
		// vocabulary — the roles both parties held, their addresses, and
		// putting both back on a reversal — is contributed here rather than
		// written as a second merge that could disagree with the first.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\ObjectsMergedEvent::class,
			\OCA\OpenRegister\Listener\PartyMergeListener::class
		);

		// Advertise the `openregister` OCM resource type in /ocm-provider discovery.
		$context->registerEventListener(
			\OCP\OCM\Events\ResourceTypeRegisterEvent::class,
			\OCA\OpenRegister\Listener\OcmResourceTypeListener::class
		);

		// Register OpenRegister as a Context Chat (OCP\ContextChat) content
		// provider — softly gated by IContentManager::isContextChatAvailable()
		// inside the listener body, so an instance without the `context_chat`
		// app installed is entirely unaffected.
		$context->registerEventListener(
			\OCP\ContextChat\Events\ContentProviderRegisterEvent::class,
			ContentProviderRegistrationListener::class
		);
	}//end registerFileAndDiscoveryListeners()

	/**
	 * Register the flow trigger, delivery and engine listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerTriggerListeners(IRegistrationContext $context): void {
		// Object-lifecycle flow triggers: queue a run for every flow wired to a
		// lifecycle event. Create / update / delete plus lock / unlock / revert /
		// state-change — the last four were declared in the event catalog but had
		// no listener firing them until now, so a flow could select them and never
		// run.
		$context->registerEventListener(ObjectLockedEvent::class, \OCA\OpenRegister\Listener\FlowTriggerListener::class);
		$context->registerEventListener(ObjectUnlockedEvent::class, \OCA\OpenRegister\Listener\FlowTriggerListener::class);
		$context->registerEventListener(ObjectRevertedEvent::class, \OCA\OpenRegister\Listener\FlowTriggerListener::class);

		// Non-object native flow triggers: a file or a user is not an OpenRegister
		// object, so its event rides on the run as a payload the worker seeds the
		// first item from. A flow wired to file.created / user.created runs on
		// these just as it does on object events.
		$context->registerEventListener(\OCP\Files\Events\Node\NodeCreatedEvent::class, \OCA\OpenRegister\Listener\NativeFlowTriggerListener::class);
		$context->registerEventListener(\OCP\Files\Events\Node\NodeWrittenEvent::class, \OCA\OpenRegister\Listener\NativeFlowTriggerListener::class);
		$context->registerEventListener(\OCP\Files\Events\Node\NodeDeletedEvent::class, \OCA\OpenRegister\Listener\NativeFlowTriggerListener::class);
		$context->registerEventListener(\OCP\User\Events\UserCreatedEvent::class, \OCA\OpenRegister\Listener\NativeFlowTriggerListener::class);
		$context->registerEventListener(\OCP\User\Events\UserDeletedEvent::class, \OCA\OpenRegister\Listener\NativeFlowTriggerListener::class);
		$context->registerEventListener(\OCP\Share\Events\ShareCreatedEvent::class, \OCA\OpenRegister\Listener\NativeFlowTriggerListener::class);
		$context->registerEventListener(\OCP\Share\Events\ShareDeletedEvent::class, \OCA\OpenRegister\Listener\NativeFlowTriggerListener::class);
		$context->registerEventListener(\OCP\SystemTag\TagAssignedEvent::class, \OCA\OpenRegister\Listener\NativeFlowTriggerListener::class);
		$context->registerEventListener(\OCP\SystemTag\TagUnassignedEvent::class, \OCA\OpenRegister\Listener\NativeFlowTriggerListener::class);

		// BulkActionRegistrationListener for the built-in bulk actions. A leaf
		// app registers its own action on the same event (ADR-022).
		$context->registerEventListener(
			\OCA\OpenRegister\Event\BulkActionRegistrationEvent::class,
			\OCA\OpenRegister\Listener\BulkActionRegistrationListener::class
		);

		// Tables schema-lifecycle listener — retire the managed virtual schema of
		// a deleted Tables table. Guarded by class_exists so boot never fatals on
		// an instance without the (soft-dependency) Tables app installed.
		if (class_exists('OCA\\Tables\\Event\\TableDeletedEvent') === true) {
			$context->registerEventListener('OCA\\Tables\\Event\\TableDeletedEvent', TablesTableDeletedListener::class);
		}

		// A completed task sequence advances the approval chain it belongs to.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskSequenceCompletedEvent::class,
			ApprovalChainAdvanceListener::class
		);

		// A saved view's count alert: the sweep decides when it fires, this
		// listener tells the people it names, on the channels it declares.
		$context->registerEventListener(ViewAlertCrossedEvent::class, ViewAlertCrossedListener::class);

		// Native Nextcloud Flow (workflowengine) composition — expose OR objects
		// as a Flow entity and OR flows as a Flow operation. Guarded by
		// class_exists so boot never fatals on an instance without the (soft
		// dependency) workflowengine app.
		if (class_exists('OCP\\WorkflowEngine\\Events\\RegisterEntitiesEvent') === true) {
			$context->registerEventListener(
				\OCP\WorkflowEngine\Events\RegisterEntitiesEvent::class,
				FlowEngineRegistrationListener::class
			);
			$context->registerEventListener(
				\OCP\WorkflowEngine\Events\RegisterOperationsEvent::class,
				FlowEngineRegistrationListener::class
			);
		}

		// Queue-mode handoff drain on app enable: a provider may have appeared.
		// The schema-save triggers stay in ListenerRegistrar.
		$context->registerEventListener(\OCP\App\Events\AppEnableEvent::class, HandoffQueueDrainListener::class);

		// WebhookEventListener for webhook delivery.
		// OPS-2: register for EVERY event the listener's extractPayload() handles,
		// not just create — otherwise update/delete/lock/revert/register/schema
		// webhooks silently never deliver.
		$context->registerEventListener(ObjectLockedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(ObjectUnlockedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(ObjectRevertedEvent::class, WebhookEventListener::class);
	}//end registerTriggerListeners()

	/**
	 * Register the page script and comments listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerPageScriptListeners(IRegistrationContext $context): void {
		// FilesSidebarListener injects the sidebar tab script into the Files app.
		$context->registerEventListener('OCA\Files\Event\LoadAdditionalScriptsEvent', FilesSidebarListener::class);

		// MailAppScriptListener injects the mail sidebar script into the Mail app
		// (gated to the Mail app via TemplateResponse->getApp() === 'mail').
		$context->registerEventListener(
			\OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
			MailAppScriptListener::class
		);

		// IntegrationGlobalScriptListener loads the shared integration-registry
		// bootstrap on EVERY full-page render so the registry is installed +
		// populated universally — letting leaves render inside any consuming
		// app's object detail page (e.g. an OpenCatalogi publication) without
		// that app bootstrapping the registry itself (universal-shared-integration-registry).
		$context->registerEventListener(
			\OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
			\OCA\OpenRegister\Listener\IntegrationGlobalScriptListener::class
		);

		// LeafScriptListener is the OTHER half of that, and without it the
		// listener above delivers an empty promise for cross-app leaves. It
		// installs and populates the registry with OpenRegister's OWN built-ins
		// — it cannot contain a sibling app's Vue components, because those
		// live in that app's bundle and Nextcloud serves only the current app's
		// scripts.
		//
		// Measured 2026-09-04: no code path anywhere enqueued a providing app's
		// bundle on a consuming page. Every addScript() here names
		// 'openregister', nc-vue injects no scripts, and the providing apps
		// register no template listener. So an ADR-066 leaf reached OCS
		// discovery, satisfied gate-24 on both halves, and rendered NOTHING —
		// `humaniq-hours` has been dark on dossiq case pages for as long as it
		// has shipped.
		//
		// This enqueues each providing app's dedicated `leaves` entry, scoped
		// to pages of apps that ship a register descriptor of their own.
		$context->registerEventListener(
			\OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
			\OCA\OpenRegister\Listener\LeafScriptListener::class
		);

		// PushClientScriptListener loads the always-on, opt-in Web Push
		// subscribe client on EVERY full-page render (openregister-web-push-engine).
		// The client never prompts on load — it only subscribes on a user
		// gesture / settings toggle.
		$context->registerEventListener(
			\OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
			\OCA\OpenRegister\Listener\PushClientScriptListener::class
		);

		// CommentsEntityListener registers "openregister" objectType for Nextcloud Comments.
		$context->registerEventListener(CommentsEntityEvent::class, CommentsEntityListener::class);
	}//end registerPageScriptListeners()
}//end class

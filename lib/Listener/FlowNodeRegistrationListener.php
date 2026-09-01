<?php

/**
 * Registers OpenRegister's own built-in flow node types.
 *
 * OpenRegister contributes its nodes through exactly the same event every other
 * app uses ({@see RegisterFlowNodesEvent}) rather than seeding the registry
 * directly from the container. If the owner of the mechanism does not use the
 * mechanism, the mechanism rots: a bug in contribution would show up for
 * consuming apps and never for us.
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
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode;
use OCA\OpenRegister\Service\Flow\Nodes\EndNode;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNode;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNode;
use OCA\OpenRegister\Service\Flow\Nodes\MapNode;
use OCA\OpenRegister\Service\Flow\Nodes\MergeNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Contributes the built-in node types.
 *
 * @template-implements IEventListener<RegisterFlowNodesEvent>
 */
class FlowNodeRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SetFieldsNode $setFields The built-in "Edit fields" node.
	 * @param ExplodeNode $explode The built-in "Explode" node.
	 * @param FilterNode $filter The built-in "Filter" node.
	 * @param WaitNode $wait The built-in "Wait" node.
	 * @param AwaitSignalNode $awaitSignal The built-in "Wait for an answer" node.
	 * @param SwitchNode $switch The built-in "Switch" node.
	 * @param EndNode $end The built-in "End" node.
	 * @param MergeNode $merge The built-in "Merge" node.
	 * @param LoopNode $loop The built-in "Loop over items" node.
	 * @param SubFlowNode $subFlow The built-in "Run a flow" node.
	 * @param RouterNode $router The built-in "Route items" node.
	 * @param ObjectWriteNode $objectWrite The built-in "Write an object" node.
	 * @param ObjectReadNode $objectRead The built-in "Read objects" node.
	 * @param FlowStateNode $flowState The built-in "Flow state" node.
	 * @param MapNode $map The built-in "Map" node.
	 * @param IterateNode $iterate The built-in "Repeat until done" node.
	 * @param SendNotificationNode $sendNotification The built-in "Send a notification" node.
	 * @param SendEmailNode $sendEmail The built-in "Send an email" node.
	 * @param SendTalkMessageNode $sendTalkMessage The built-in "Send a Talk message" node.
	 * @param TriggerObjectNode $triggerObject The "When an object changes" entry point.
	 * @param TriggerScheduleNode $triggerSchedule The "On a schedule" entry point.
	 * @param TriggerManualNode $triggerManual The "When someone runs it" entry point.
	 * @param UserTaskNode $userTask The built-in "Ask a person" node.
	 * @param PortalTaskNode $portalTask The built-in "Ask a party outside the organisation" node.
	 */
	public function __construct(
		private readonly SetFieldsNode $setFields,
		private readonly ExplodeNode $explode,
		private readonly FilterNode $filter,
		private readonly WaitNode $wait,
		private readonly AwaitSignalNode $awaitSignal,
		private readonly SwitchNode $switch,
		private readonly EndNode $end,
		private readonly MergeNode $merge,
		private readonly LoopNode $loop,
		private readonly SubFlowNode $subFlow,
		private readonly RouterNode $router,
		private readonly ObjectWriteNode $objectWrite,
		private readonly ObjectReadNode $objectRead,
		private readonly FlowStateNode $flowState,
		private readonly MapNode $map,
		private readonly IterateNode $iterate,
		private readonly SendNotificationNode $sendNotification,
		private readonly SendEmailNode $sendEmail,
		private readonly SendTalkMessageNode $sendTalkMessage,
		private readonly TriggerObjectNode $triggerObject,
		private readonly TriggerScheduleNode $triggerSchedule,
		private readonly TriggerManualNode $triggerManual,
		private readonly UserTaskNode $userTask,
		private readonly PortalTaskNode $portalTask,
	) {

	}//end __construct()

	/**
	 * Register the built-ins.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterFlowNodesEvent) === false) {
			return;
		}

		$event->registerNode(node: $this->setFields);
		$event->registerNode(node: $this->explode);
		$event->registerNode(node: $this->filter);
		$event->registerNode(node: $this->wait);
		$event->registerNode(node: $this->awaitSignal);
		$event->registerNode(node: $this->switch);
		$event->registerNode(node: $this->end);
		$event->registerNode(node: $this->merge);
		$event->registerNode(node: $this->loop);
		$event->registerNode(node: $this->subFlow);
		$event->registerNode(node: $this->router);
		$event->registerNode(node: $this->objectWrite);
		$event->registerNode(node: $this->objectRead);
		$event->registerNode(node: $this->flowState);
		$event->registerNode(node: $this->map);
		$event->registerNode(node: $this->iterate);

		// Messaging. Three nodes, not one "send" with a channel picker: the
		// three differ in config shape and failure modes, so three flat forms
		// beat one union form. Deliberately NO send-webhook — outbound HTTP is
		// OpenConnector's job (ADR-094), and `activity`/`web-push` are not
		// channels here either: activity is an audit surface, web-push rides
		// along with send-notification exactly as it does declaratively.
		$event->registerNode(node: $this->sendNotification);
		$event->registerNode(node: $this->sendEmail);
		$event->registerNode(node: $this->sendTalkMessage);

		// The human step (flow-user-task-node). Registered beside await-signal
		// deliberately: the two are a pair, and their palette descriptions
		// state which is for a system that calls back and which is for a
		// performer who has to be found, told, and allowed to say no.
		$event->registerNode(node: $this->userTask);

		// The third waiter (flow-portal-task): a party OUTSIDE the instance,
		// matched from the case and reached through the portal seam. The three
		// palette descriptions are written as a set: signal for a system that
		// calls back, user task for a performer in the organisation, portal
		// task for a party outside it.
		$event->registerNode(node: $this->portalTask);

		// Entry points. Registered like any other node so the palette can offer
		// them and the preflight can check their config — a trigger is where a
		// run BEGINS, not work it performs, and each `execute()` is a
		// pass-through.
		$event->registerNode(node: $this->triggerObject);
		$event->registerNode(node: $this->triggerSchedule);
		$event->registerNode(node: $this->triggerManual);

	}//end handle()
}//end class

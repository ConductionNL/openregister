<?php

/**
 * The Talk channel's post, as a call-shared unit.
 *
 * Extracted from AnnotationNotificationDispatcher so both callers post a chat
 * message through ONE implementation. Two paths, deliberately distinct:
 *
 * - `postAsBot()` is the declarative dispatcher's existing behaviour,
 *   verbatim — a broadcast to a configured room from the `openregister` bot
 *   actor, best-effort.
 * - `postAsUser()` is the flow messaging path: the message is attributed to
 *   the flow run's ACTING user, which requires that user to be a participant
 *   of the target conversation. "Not a participant" is a failure with that
 *   reason — it is NEVER an auto-join, which would be a privacy-relevant side
 *   effect performed by a messaging convenience.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use DateTime;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IServerContainer;
use Psr\Log\LoggerInterface;

/**
 * Posts a chat message to a Talk conversation.
 */
class TalkSender {

	public const OUTCOME_DISPATCHED = 'dispatched';

	public const OUTCOME_KILL_SWITCH = 'kill-switch';

	public const OUTCOME_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param IClientService $httpClient HTTP client for the bot post.
	 * @param LoggerInterface $logger Logger for post diagnostics.
	 * @param IConfig|null $config Config service for the local OCS base URL.
	 * @param NotificationChannelPolicy|null $channelPolicy The subsystem's per-channel kill switches; null means enabled.
	 * @param IServerContainer|null $serverContainer Container used to reach the Talk app for the attributed path.
	 */
	public function __construct(
		private readonly IClientService $httpClient,
		private readonly LoggerInterface $logger,
		private readonly ?IConfig $config = null,
		private readonly ?NotificationChannelPolicy $channelPolicy = null,
		private readonly ?IServerContainer $serverContainer = null,
	) {

	}//end __construct()

	/**
	 * Post a chat message to a Talk room as the `openregister` bot.
	 *
	 * The declarative dispatcher's behaviour, verbatim: best-effort, via the
	 * local OCS endpoint, no attribution to a person.
	 *
	 * @param string $token The Talk conversation token.
	 * @param string $message The message text.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function postAsBot(string $token, string $message): string {
		if ($token === '') {
			return self::OUTCOME_FAILED;
		}

		if ($this->channelPolicy !== null && $this->channelPolicy->isChannelEnabled(channel: 'talk') === false) {
			return self::OUTCOME_KILL_SWITCH;
		}

		try {
			$client = $this->httpClient->newClient();
			// Talk's chat endpoint is internal to the NC instance, so route
			// via the configured overwrite host or fall back to the loopback.
			$base = 'http://localhost';
			if ($this->config !== null) {
				$base = (string)$this->config->getSystemValue('overwrite.cli.url', 'http://localhost');
			}

			$base = rtrim($base, '/');
			$url = $base . '/ocs/v2.php/apps/spreed/api/v1/chat/' . rawurlencode($token);

			$client->post(
				$url,
				[
					'headers' => [
						'OCS-APIRequest' => 'true',
						'Accept' => 'application/json',
						'Content-Type' => 'application/x-www-form-urlencoded',
					],
					'body' => [
						'message' => $message,
						'actorType' => 'bots',
						'actorId' => 'openregister',
					],
					'timeout' => 5,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[TalkSender] talk to "%s" failed: %s', $token, $e->getMessage())
			);
			return self::OUTCOME_FAILED;
		}//end try

		return self::OUTCOME_DISPATCHED;
	}//end postAsBot()

	/**
	 * Post a chat message to a Talk conversation AS a user.
	 *
	 * The flow messaging path: the message appears from the acting user, so
	 * replies have an addressee and the audit trail an actor. The user MUST
	 * already be a participant of the conversation; "not a participant" is a
	 * failure with that reason, never an auto-join.
	 *
	 * @param string $token The Talk conversation token.
	 * @param string $message The message text.
	 * @param string $actorUid The acting user's uid.
	 *
	 * @return string OUTCOME_DISPATCHED or OUTCOME_KILL_SWITCH.
	 *
	 * @throws TalkSendException When Talk is unavailable, the conversation is
	 *                           unknown, the acting user is not a participant,
	 *                           or the post is refused.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function postAsUser(string $token, string $message, string $actorUid): string {
		if ($token === '') {
			throw new TalkSendException('No Talk conversation token was given.');
		}

		if ($this->channelPolicy !== null && $this->channelPolicy->isChannelEnabled(channel: 'talk') === false) {
			return self::OUTCOME_KILL_SWITCH;
		}

		$this->postViaTalkApp(token: $token, message: $message, actorUid: $actorUid);

		return self::OUTCOME_DISPATCHED;
	}//end postAsUser()

	/**
	 * Perform the attributed post through the Talk app's own services.
	 *
	 * Talk publishes no OCP surface for posting as a user, so the classes are
	 * resolved by name through the container — a duck-typed integration that
	 * fails LOUDLY on every miss: Talk absent, conversation unknown, actor not
	 * a participant, API shape changed. A silent no-op here would be a
	 * COMPLETED run hiding an undelivered message.
	 *
	 * Protected so unit tests can substitute the Talk side without a Talk
	 * installation; the decision logic above it stays under test either way.
	 *
	 * @param string $token The conversation token.
	 * @param string $message The message text.
	 * @param string $actorUid The acting user's uid.
	 *
	 * @return void
	 *
	 * @throws TalkSendException On every non-delivery, naming the reason.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	protected function postViaTalkApp(string $token, string $message, string $actorUid): void {
		if ($this->serverContainer === null) {
			throw new TalkSendException('Talk posting is unavailable: no server container to reach the Talk app.');
		}

		try {
			$manager = $this->serverContainer->get('OCA\\Talk\\Manager');
		} catch (\Throwable $e) {
			throw new TalkSendException('Talk (spreed) is not installed, so no Talk message can be sent.');
		}

		try {
			// Resolving the room FOR the user is also the participant check:
			// Talk refuses the lookup for a conversation the user is not in.
			$room = $manager->getRoomForUserByToken($token, $actorUid);
			$participantService = $this->serverContainer->get('OCA\\Talk\\Service\\ParticipantService');
			$participant = $participantService->getParticipant($room, $actorUid, false);
			$chatManager = $this->serverContainer->get('OCA\\Talk\\Chat\\ChatManager');
			$chatManager->sendMessage($room, $participant, 'users', $actorUid, $message, new DateTime());
		} catch (TalkSendException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$class = get_class($e);
			if (str_contains($class, 'ParticipantNotFound') === true || str_contains($class, 'RoomNotFound') === true) {
				throw new TalkSendException(
					sprintf(
						'User "%s" is not a participant of Talk conversation "%s"; the message was not sent and the user was not auto-joined.',
						$actorUid,
						$token
					)
				);
			}

			throw new TalkSendException(
				sprintf('Talk post to "%s" as "%s" failed: %s', $token, $actorUid, $e->getMessage())
			);
		}//end try
	}//end postViaTalkApp()
}//end class

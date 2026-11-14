<?php

/**
 * The email channel's composition/handoff, as a call-shared unit.
 *
 * Extracted from AnnotationNotificationDispatcher so both callers — the
 * declarative dispatcher and the flow messaging service — compose and hand a
 * transactional mail to the ONE mailer. The unit reports its outcome instead
 * of swallowing it, because the two callers disagree about what a failure
 * means: the declarative path stays best-effort (SMTP not configured is
 * normal in dev containers), while a flow send node turns a failed handoff
 * into a step failure — a COMPLETED run must never hide an undelivered
 * message.
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
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCA\OpenRegister\Notifications\Service\OutboundMailLinkWriter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Composes and hands off a transactional email to a Nextcloud user.
 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-009
 */
class EmailSender implements EmailSenderInterface {

	public const OUTCOME_KILL_SWITCH = 'kill-switch';

	public const OUTCOME_NO_ADDRESS = 'no-address';

	public const OUTCOME_FAILED = 'failed';

	/**
	 * The unsubscribe header helper, built on first use.
	 *
	 * @var UnsubscribeHeaders|null
	 */
	private ?UnsubscribeHeaders $unsubscribeHeaders = null;

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager User resolver for the recipient's address.
	 * @param IMailer $mailer The mailer.
	 * @param LoggerInterface $logger Logger for handoff diagnostics.
	 * @param NotificationChannelPolicy|null $channelPolicy The subsystem's per-channel kill switches; null means enabled.
	 * @param OutboundMailLinkWriter|null $outboundLinks Writes the outbound link of a mail bound to an object; null writes none.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IMailer $mailer,
		private readonly LoggerInterface $logger,
		private readonly ?NotificationChannelPolicy $channelPolicy = null,
		private readonly ?OutboundMailLinkWriter $outboundLinks = null,
	) {
	}//end __construct()

	/**
	 * Send a transactional email to a Nextcloud user.
	 *
	 * Resolves the user's email via IUserManager. Never throws: the outcome
	 * says what happened, and each caller decides what a non-delivery means —
	 * the declarative dispatcher logs and moves on, the flow messaging
	 * service escalates a failure into a step failure.
	 *
	 * @param string $uid Recipient user UID.
	 * @param string $subject Email subject line.
	 * @param string $body Email body text.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function send(string $uid, string $subject, string $body): string {
		return $this->sendMail(uid: $uid, content: new MailContent(subject: $subject, body: $body));
	}//end send()

	/**
	 * Send a transactional email to a Nextcloud user; the outcome of {@see send()}.
	 *
	 * @param string      $uid     Recipient user UID.
	 * @param MailContent $content The subject and body.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function sendMail(string $uid, MailContent $content): string {
		if ($this->emailDisabled() === true) {
			return self::OUTCOME_KILL_SWITCH;
		}

		return $this->sendToUser(uid: $uid, content: $content);
	}//end sendMail()

	/**
	 * Send a transactional email to a bare address.
	 *
	 * Most melders never sign in. Making them Nextcloud users to hold a name
	 * and an address is a permission surface nobody wants and an account
	 * nobody uses, so a party without an account is reached over the
	 * addresses it holds. The kill switch, the never-throws contract and the
	 * outcome vocabulary are the account path's, unchanged.
	 *
	 * @param MailAddressee $addressee The address and the name to address it to.
	 * @param MailContent   $content   The subject, body and unsubscribe material.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004
	 */
	public function sendMailTo(MailAddressee $addressee, MailContent $content): string {
		if ($this->emailDisabled() === true) {
			return self::OUTCOME_KILL_SWITCH;
		}

		$to = trim($addressee->address);

		return $this->sendToParty(to: [$to => self::nameFor(address: $to, displayName: $addressee->displayName)], content: $content);
	}//end sendMailTo()

	/**
	 * Whether the email channel's kill switch is set.
	 *
	 * @return bool True when the channel is switched off.
	 */
	private function emailDisabled(): bool {
		return $this->channelPolicy !== null && $this->channelPolicy->isChannelEnabled(channel: 'email') === false;
	}//end emailDisabled()

	/**
	 * Mail a Nextcloud user at the address their account holds.
	 *
	 * @param string      $uid     Recipient user UID.
	 * @param MailContent $content The subject and body.
	 *
	 * @return string OUTCOME_NO_ADDRESS, OUTCOME_FAILED or OUTCOME_DISPATCHED.
	 */
	private function sendToUser(string $uid, MailContent $content): string {
		try {
			$to = $this->addressOf(uid: $uid);
			if ($to === null) {
				return self::OUTCOME_NO_ADDRESS;
			}

			$messageId = $this->deliver(to: $to, content: $content);
		} catch (\Throwable $e) {
			// SMTP not configured is normal in dev containers; the caller
			// decides whether this outcome escalates.
			$this->logger->debug(
				sprintf('[EmailSender] email to "%s" failed (%s)', $uid, $e->getMessage())
			);
			return self::OUTCOME_FAILED;
		}

		$this->linkSent(content: $content, messageId: $messageId, recipient: (string) reset($to));

		return self::OUTCOME_DISPATCHED;
	}//end sendToUser()

	/**
	 * The address a user's account holds, mapped to their display name.
	 *
	 * @param string $uid The user UID.
	 *
	 * @return array<string, string>|null The address and name, or null when the user or the address is missing.
	 */
	private function addressOf(string $uid): ?array {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return null;
		}

		$to = $user->getEMailAddress();
		if ($to === null || $to === '') {
			return null;
		}

		return [$to => $user->getDisplayName()];
	}//end addressOf()

	/**
	 * Mail a party at a bare address.
	 *
	 * @param array<string, string> $to      The trimmed address mapped to the name to address it to.
	 * @param MailContent           $content The subject, body and unsubscribe material.
	 *
	 * @return string OUTCOME_NO_ADDRESS, OUTCOME_FAILED or OUTCOME_DISPATCHED.
	 */
	private function sendToParty(array $to, MailContent $content): string {
		if (array_key_first($to) === '') {
			return self::OUTCOME_NO_ADDRESS;
		}

		try {
			$messageId = $this->deliver(to: $to, content: $content);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[EmailSender] email to the address of a party failed (%s)', $e->getMessage())
			);
			return self::OUTCOME_FAILED;
		}

		$this->linkSent(content: $content, messageId: $messageId, recipient: (string) reset($to));

		return self::OUTCOME_DISPATCHED;
	}//end sendToParty()

	/**
	 * Record a sent mail bound to an object as its outbound link.
	 *
	 * The mail went out either way: a failed write is logged and the outcome
	 * stays dispatched, and a reply to it reads as `unthreaded`.
	 *
	 * @param MailContent $content   What was sent.
	 * @param string|null $messageId The minted `Message-ID`, or null when none was set.
	 * @param string      $recipient The recipient's name or address.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	private function linkSent(MailContent $content, ?string $messageId, string $recipient): void {
		if ($content->object === null || $messageId === null || $this->outboundLinks === null) {
			return;
		}

		try {
			$this->outboundLinks->record(object: $content->object, rfcMessageId: $messageId, subject: $content->subject, recipient: $recipient);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('[EmailSender] the sent mail was not linked to its object (%s)', $e->getMessage())
			);
		}
	}//end linkSent()

	/**
	 * Mint and set the `Message-ID` of a mail bound to an object.
	 *
	 * Only a mailer that exposes its mail object can carry it; without one
	 * there is no id to record and the mail is sent unlinked.
	 *
	 * @param IMessage    $message The message.
	 * @param MailContent $content What it says.
	 *
	 * @return string|null The id with its angle brackets, or null when none was set.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	private function mintMessageId(IMessage $message, MailContent $content): ?string {
		if ($content->object === null || $this->outboundLinks === null || method_exists($message, 'getSymfonyEmail') === false) {
			return null;
		}

		$id = bin2hex(random_bytes(16)).'@'.self::messageIdDomain();
		try {
			$headers = $message->getSymfonyEmail()->getHeaders();
			if ($headers->has('Message-ID') === true) {
				$headers->remove('Message-ID');
			}

			$headers->addIdHeader('Message-ID', $id);
		} catch (Throwable $e) {
			$this->logger->debug(sprintf('[EmailSender] could not set the Message-ID (%s)', $e->getMessage()));
			return null;
		}

		return '<'.$id.'>';
	}//end mintMessageId()

	/**
	 * The right-hand side of a minted `Message-ID`: this host's name.
	 *
	 * @return string The domain.
	 */
	private static function messageIdDomain(): string {
		$host = strtolower((string) gethostname());
		if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
			return 'openregister.localhost';
		}

		return $host;
	}//end messageIdDomain()

	/**
	 * Compose the message and hand it to the mailer.
	 *
	 * @param array<string, string> $to      The recipient address mapped to its display name.
	 * @param MailContent           $content The subject, body and unsubscribe material.
	 *
	 * @return string|null The minted `Message-ID` of a mail bound to an object, else null.
	 *
	 * @throws \Throwable When the handoff fails.
	 */
	private function deliver(array $to, MailContent $content): ?string {
		$msg = $this->mailer->createMessage();
		$msg->setTo($to);
		$msg->setSubject($content->subject);
		$msg->setPlainBody($content->body);
		if ($content->unsubscribe !== null) {
			// Best effort: false means the headers could not be set, and
			// the mail still goes out with the link in its body.
			$this->unsubscribeHeaders()->apply(message: $msg, unsubscribe: $content->unsubscribe);
		}

		$messageId = $this->mintMessageId(message: $msg, content: $content);
		$this->mailer->send($msg);

		return $messageId;
	}//end deliver()

	/**
	 * The unsubscribe header helper, built on first use.
	 *
	 * @return UnsubscribeHeaders The helper.
	 */
	private function unsubscribeHeaders(): UnsubscribeHeaders {
		$this->unsubscribeHeaders ??= new UnsubscribeHeaders(logger: $this->logger);

		return $this->unsubscribeHeaders;
	}//end unsubscribeHeaders()

	/**
	 * The name to address a bare address to: the display name, else the address.
	 *
	 * @param string $address     The trimmed address.
	 * @param string $displayName The display name, '' for none.
	 *
	 * @return string The name.
	 */
	private static function nameFor(string $address, string $displayName): string {
		if ($displayName === '') {
			return $address;
		}

		return $displayName;
	}//end nameFor()
}//end class

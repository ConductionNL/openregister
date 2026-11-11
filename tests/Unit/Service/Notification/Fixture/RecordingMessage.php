<?php

/**
 * A mail message that records what was set on it, headers included.
 *
 * Nextcloud's own message class exposes the Symfony mail object through
 * `getSymfonyEmail()`; this fixture does the same with a recording header
 * bag, so a test can read back the List-Unsubscribe headers. Built with
 * `$exposesMailObject = false` it is a mailer that does not expose it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture;

use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMessage;

/**
 * Records recipients, subject, body and headers.
 */
class RecordingMessage implements IMessage {

	/**
	 * The recipients, address => name.
	 *
	 * @var array<string, string>
	 */
	public array $to = [];

	public string $subject = '';

	public string $body = '';

	/**
	 * The headers, name => value.
	 *
	 * @var array<string, string>
	 */
	public array $headers = [];

	public function setSubject(string $subject): IMessage {
		$this->subject = $subject;
		return $this;
	}

	public function setPlainBody(string $body): IMessage {
		$this->body = $body;
		return $this;
	}

	public function setHtmlBody(string $body): IMessage {
		return $this;
	}

	public function attach(IAttachment $attachment): IMessage {
		return $this;
	}

	public function attachInline(string $body, string $name, ?string $contentType = null): IMessage {
		return $this;
	}

	public function setFrom(array $addresses): IMessage {
		return $this;
	}

	public function setReplyTo(array $addresses): IMessage {
		return $this;
	}

	public function setTo(array $recipients): IMessage {
		$this->to = $recipients;
		return $this;
	}

	public function setCc(array $recipients): IMessage {
		return $this;
	}

	public function setBcc(array $recipients): IMessage {
		return $this;
	}

	public function useTemplate(IEMailTemplate $emailTemplate): IMessage {
		return $this;
	}

	public function setAutoSubmitted(string $value): IMessage {
		return $this;
	}
}

<?php

/**
 * Notifications records a sent mail's object link through a Kernel port; Integrations answers it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\MailObjectBinding;
use OCA\OpenRegister\Kernel\Contracts\OutboundMailLinkRecorderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\OutboundMailLinkWriter;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The port is bound by Integrations to the writer of the email-link table, and the sender types against it.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\OutboundMailLinkWriter
 * @uses   \OCA\OpenRegister\Kernel\Contracts\MailObjectBinding
 * @uses   \OCA\OpenRegister\Db\EmailLink
 */
final class OutboundMailLinkRecorderPortTest extends TestCase {

	public function testIntegrationsBindsThePortToTheWriter(): void {
		$this->assertSame(OutboundMailLinkWriter::class, IntegrationWiring::BINDINGS[OutboundMailLinkRecorderInterface::class]);
		$this->assertTrue(is_subclass_of(OutboundMailLinkWriter::class, OutboundMailLinkRecorderInterface::class));
	}

	public function testTheSenderTypesAgainstThePortAndKeepsItOptional(): void {
		$parameter = null;
		foreach ((new ReflectionClass(EmailSender::class))->getConstructor()?->getParameters() ?? [] as $candidate) {
			if ($candidate->getName() === 'outboundLinks') {
				$parameter = $candidate;
			}
		}

		$this->assertNotNull($parameter);
		$type = $parameter->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(OutboundMailLinkRecorderInterface::class, $type->getName());
		$this->assertTrue($parameter->allowsNull());
		$this->assertNull($parameter->getDefaultValue());
	}

	public function testThePortWritesOneOutboundLinkForTheObject(): void {
		$inserted = [];
		$links = $this->createMock(EmailLinkMapperInterface::class);
		$links->expects($this->once())->method('insert')->willReturnCallback(
			static function (EmailLink $link) use (&$inserted): EmailLink {
				$inserted[] = $link;
				return $link;
			}
		);

		$recorder = new OutboundMailLinkWriter(links: $links);
		$recorder->record(
			object: new MailObjectBinding(objectUuid: 'obj-1', registerId: 4, schemaId: 7, linkedBy: 'alice'),
			rfcMessageId: '<m-1@example.org>',
			subject: 'Your request',
			recipient: 'bob@example.org'
		);

		$this->assertCount(1, $inserted);
		$this->assertSame('obj-1', $inserted[0]->getObjectUuid());
		$this->assertSame(EmailLink::DIRECTION_OUTBOUND, $inserted[0]->getDirection());
		$this->assertSame('alice', $inserted[0]->getLinkedBy());
	}
}//end class

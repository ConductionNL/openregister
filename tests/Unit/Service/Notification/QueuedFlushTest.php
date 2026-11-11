<?php

/**
 * QueuedFlushTest.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Service\Notification\QueuedFlush;
use PHPUnit\Framework\TestCase;

/**
 * A queued group's flush reads the first row's payload and the merged summary.
 */
class QueuedFlushTest extends TestCase {
	public function testAFullPayloadRebuildsTheObjectAndTheHistoryDetail(): void {
		$row = new QueuedNotification();
		$row->setRuleKey('rule-1');
		$row->setRecipient('alice');
		$row->setPayload((string)json_encode([
			'channels' => ['email'],
			'name' => 'Nieuwe melding',
			'locale' => 'nl',
			'objectUuid' => 'u-1',
			'objectRegister' => 3,
			'objectSchema' => 'zaken',
			'objectName' => 'Zaak 1',
		]));

		$flush = new QueuedFlush($row, ['S', 'M']);

		$this->assertSame('alice', $flush->recipient);
		$this->assertSame('S', $flush->subject);
		$this->assertSame('M', $flush->message);
		$this->assertSame(['email'], $flush->channels);
		$this->assertSame('Nieuwe melding', $flush->name);
		$this->assertSame('u-1', $flush->object->getUuid());
		$this->assertSame('3', $flush->object->getRegister());
		$this->assertSame('zaken', $flush->object->getSchema());
		$this->assertSame('Zaak 1', $flush->object->getName());
		$this->assertSame('rule-1', $flush->origin->ruleId);
		$this->assertSame($flush->object, $flush->origin->object);
		$this->assertSame('S', $flush->detail->subject);
		$this->assertSame('nl', $flush->detail->locale);
	}

	public function testABarePayloadFallsBackToTheRuleKeyAndNoLocale(): void {
		$row = new QueuedNotification();
		$row->setRuleKey('rule-2');
		$row->setRecipient('bob');
		$row->setPayload('{}');

		$flush = new QueuedFlush($row, ['', '']);

		$this->assertSame([], $flush->channels);
		$this->assertSame('rule-2', $flush->name);
		$this->assertSame('', $flush->object->getUuid());
		$this->assertNull($flush->object->getRegister());
		$this->assertNull($flush->object->getName());
		$this->assertNull($flush->detail->locale);
	}
}

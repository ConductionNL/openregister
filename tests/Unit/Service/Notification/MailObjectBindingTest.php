<?php

/**
 * MailObjectBinding: the object a sent mail is linked to, read from its serialised form.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\MailObjectBinding;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\MailObjectBinding
 */
class MailObjectBindingTest extends TestCase {

	public function testTheUuidRegisterAndSchemaAreRead(): void {
		$binding = MailObjectBinding::fromObjectJson(json: ['uuid' => 'case-1', '@self' => ['register' => '4', 'schema' => 9]], linkedBy: 'alice');

		$this->assertNotNull($binding);
		$this->assertSame(['case-1', 4, 9, 'alice'], [$binding->objectUuid, $binding->registerId, $binding->schemaId, $binding->linkedBy]);
	}

	public function testTheSelfIdServesWhenTheObjectHasNoUuidKey(): void {
		$binding = MailObjectBinding::fromObjectJson(json: ['@self' => ['id' => 'case-2', 'register' => 4]], linkedBy: 'alice');

		$this->assertSame('case-2', $binding?->objectUuid);
		$this->assertNull($binding?->schemaId);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function unboundObjects(): array {
		return [
			'no uuid'          => [['@self' => ['register' => 4]], 'alice'],
			'no register'      => [['uuid' => 'case-1'], 'alice'],
			'register zero'    => [['uuid' => 'case-1', '@self' => ['register' => '0']], 'alice'],
			'register a slug'  => [['uuid' => 'case-1', '@self' => ['register' => 'zaken']], 'alice'],
			'self not a map'   => [['uuid' => 'case-1', '@self' => 'x'], 'alice'],
			'no acting user'   => [['uuid' => 'case-1', '@self' => ['register' => 4]], ''],
		];
	}

	/**
	 * @dataProvider unboundObjects
	 *
	 * @param array<string, mixed> $json     The object.
	 * @param string               $linkedBy The acting user.
	 */
	public function testAnObjectWithoutUuidRegisterOrActorBindsNothing(array $json, string $linkedBy): void {
		$this->assertNull(MailObjectBinding::fromObjectJson(json: $json, linkedBy: $linkedBy));
	}
}

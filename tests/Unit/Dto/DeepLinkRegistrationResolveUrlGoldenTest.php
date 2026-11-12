<?php

/**
 * Characterisation of DeepLinkRegistration::resolveUrl(): the object placeholders (named
 * ones and every scalar top-level key) and the contact placeholders, URL-encoded, that
 * apply only when a contact context is given.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Dto
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Dto;

use OCA\OpenRegister\Dto\DeepLinkRegistration;
use PHPUnit\Framework\TestCase;

/**
 * Golden URLs of a deep link registration.
 *
 * @covers \OCA\OpenRegister\Dto\DeepLinkRegistration
 */
final class DeepLinkRegistrationResolveUrlGoldenTest extends TestCase {

	/**
	 * The templates, object data, contact context and the URL each resolves to.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}>
	 */
	public static function cases(): array {
		$template = '/apps/x/{register}/{schema}/{uuid}?id={id}&t={title}&n={nested}&c={contactId}&e={contactEmail}&m={contactName}&ent={entityId}';
		return [
			'object only' => [
				$template,
				['uuid' => 'u-1', 'id' => 7, 'register' => 3, 'schema' => 'person', 'title' => 'A b', 'nested' => ['x' => 1]],
				[],
				'/apps/x/3/person/u-1?id=7&t=A b&n={nested}&c={contactId}&e={contactEmail}&m={contactName}&ent={entityId}',
			],
			'missing named keys are empty' => [
				'/{uuid}/{id}/{register}/{schema}/{other}',
				[],
				[],
				'/////{other}',
			],
			'contact context, encoded' => [
				$template,
				['uuid' => 'u-2', 'id' => '8', 'title' => true],
				['contactId' => 'c 1', 'contactEmail' => 'a+b@example.org', 'contactName' => 'Jan & Piet'],
				'/apps/x///u-2?id=8&t=1&n={nested}&c=c+1&e=a%2Bb%40example.org&m=Jan+%26+Piet&ent=u-2',
			],
			'contact keys win over object keys' => [
				'{contactId}|{entityId}|{uuid}',
				['contactId' => 'from-object', 'uuid' => 'u-3'],
				['contactEmail' => 'x'],
				'|u-3|u-3',
			],
			'object keys win over named ones' => [
				'{uuid}|{id}',
				['uuid' => 'u-4', 'id' => 9],
				[],
				'u-4|9',
			],
			'contact context without uuid' => [
				'{entityId}|{contactName}',
				['id' => 1],
				['contactName' => 'Ann'],
				'|Ann',
			],
		];
	}//end cases()

	/**
	 * The template resolves to the expected URL.
	 *
	 * @param string               $template The URL template.
	 * @param array<string, mixed> $object   The object data.
	 * @param array<string, mixed> $contact  The contact context.
	 * @param string               $expected The URL.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testResolveUrl(string $template, array $object, array $contact, string $expected): void {
		$registration = new DeepLinkRegistration(appId: 'x', registerSlug: 'r', schemaSlug: 's', urlTemplate: $template);

		$this->assertSame($expected, $registration->resolveUrl($object, $contact));
	}//end testResolveUrl()
}//end class

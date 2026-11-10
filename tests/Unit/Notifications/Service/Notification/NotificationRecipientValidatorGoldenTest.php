<?php

/**
 * Golden pins for NotificationRecipientValidator: every recipient kind and branch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientValidator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleContext;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationSchemaFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact codes, messages and order the validator returns.
 */
final class NotificationRecipientValidatorGoldenTest extends TestCase {

	/**
	 * The validator returns exactly the pinned errors, in order.
	 *
	 * @param array<string, mixed>             $spec     The rule declaration.
	 * @param array<int, array<string, mixed>> $expected The pinned errors.
	 *
	 * @return void
	 */
	#[DataProvider('rules')]
	public function testValidateMatchesTheGolden(array $spec, array $expected): void {
		$schema = new NotificationSchemaFacts(
			schema: [
				'properties' => [
					'title' => ['type' => 'string'],
					'owner' => ['type' => 'string'],
					'supplierRef' => ['type' => 'string', '$ref' => '#/s'],
					'plainRef' => ['type' => 'string'],
				],
				'authorization' => ['roles' => ['behandelaar' => []]],
			]
		);

		$actual = (new NotificationRecipientValidator())->validate(new NotificationRuleContext(name: 'termijn', spec: $spec, schema: $schema));

		self::assertSame($expected, $actual);
	}//end testValidateMatchesTheGolden()

	/**
	 * Rule declarations and the errors pinned for them.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}>
	 */
	public static function rules(): array {
		return [
			'no recipients' => [
				[
					'recipients' => [],
				],
				[
					[
						'code' => 'notification-no-recipients',
						'message' => 'Notification "termijn" must declare at least one recipient.',
					],
				],
			],
			'recipients not a list' => [
				[
					'recipients' => 'users',
				],
				[
					[
						'code' => 'notification-no-recipients',
						'message' => 'Notification "termijn" must declare at least one recipient.',
					],
				],
			],
			'every kind' => [
				[
					'channels' => [
						'nc-notification',
					],
					'recipients' => [
						'scalar',
						[
							'watchers' => true,
						],
						[
							'watchers' => 'yes',
						],
						[
							'kind' => 'nobody',
						],
						[
							'kind' => 'users',
							'users' => [
								'admin',
							],
						],
						[
							'kind' => 'groups',
							'groups' => [],
						],
						[
							'kind' => 'users',
						],
						[
							'kind' => 'field',
							'field' => 'owner',
						],
						[
							'kind' => 'field',
							'field' => 'missing',
						],
						[
							'kind' => 'field',
						],
						[
							'kind' => 'object-acl',
							'permission' => 'read',
						],
						[
							'kind' => 'object-acl',
							'permission' => 'write',
						],
						[
							'kind' => 'role',
						],
						[
							'kind' => 'role',
							'role' => 'behandelaar',
						],
						[
							'kind' => 'role',
							'role' => 'klant',
						],
						[
							'kind' => 'expression',
							'resolver' => 'App\\Resolver',
						],
						[
							'kind' => 'expression',
						],
						[
							'kind' => 'relation',
						],
						[
							'kind' => 'parties',
						],
						[
							'kind' => 'email',
							'field' => 'supplierRef.contactEmail',
						],
					],
				],
				[
					[
						'code' => 'notification-recipient-malformed',
						'message' => 'Notification "termijn" recipient[0] must be an object.',
					],
					[
						'code' => 'notification-recipient-watchers-not-true',
						'message' => 'Notification "termijn" recipient[2] watchers must be boolean true.',
					],
					[
						'code' => 'notification-bad-recipient-kind',
						'message' => 'Notification "termijn" recipient[3] kind "nobody" not in [users, field, groups, role, rela'
							.'tion, object-acl, expression, watchers, parties, email].',
					],
					[
						'code' => 'notification-recipient-names-nobody',
						'message' => 'Notification "termijn" recipient[5] is kind "groups" but names no groups, so it can never '
							.'resolve to anybody. An unstaffed group is fine and is reported at dispatch; an empty list '
							.'is a stub.',
					],
					[
						'code' => 'notification-recipient-names-nobody',
						'message' => 'Notification "termijn" recipient[6] is kind "users" but names no users, so it can never re'
							.'solve to anybody. An unstaffed group is fine and is reported at dispatch; an empty list is'
							.' a stub.',
					],
					[
						'code' => 'notification-recipient-field-unknown',
						'message' => 'Notification "termijn" recipient[8] field "missing" is not declared on the schema.',
					],
					[
						'code' => 'notification-recipient-field-unknown',
						'message' => 'Notification "termijn" recipient[9] field "" is not declared on the schema.',
					],
					[
						'code' => 'notification-recipient-acl-bad-permission',
						'message' => 'Notification "termijn" recipient[11] kind=object-acl requires permission in [read, manage]'
							.'; got "write".',
					],
					[
						'code' => 'notification-recipient-role-missing',
						'message' => 'Notification "termijn" recipient[12] kind=role requires a non-empty role name.',
					],
					[
						'code' => 'notification-recipient-role-unknown',
						'message' => 'Notification "termijn" recipient[14] role "klant" is not assigned by the schema authorizat'
							.'ion; declared: [behandelaar].',
					],
					[
						'code' => 'notification-recipient-expression-no-resolver',
						'message' => 'Notification "termijn" recipient[16] kind=expression requires a resolver string (DI tag or'
							.' FQCN).',
					],
					[
						'code' => 'notification-recipient-email-needs-email-channel',
						'message' => 'Notification "termijn" recipient[19] is kind=email but the rule has no "email" channel, so'
							.' it would reach nobody.',
					],
				],
			],
			'named index' => [
				[
					'recipients' => [
						'first' => [
							'kind' => 'field',
							'field' => 'nope',
						],
					],
				],
				[
					[
						'code' => 'notification-recipient-field-unknown',
						'message' => 'Notification "termijn" recipient[0] field "nope" is not declared on the schema.',
					],
				],
			],
			'email on an email rule' => [
				[
					'channels' => [
						'email',
					],
					'recipients' => [
						[
							'kind' => 'email',
						],
						[
							'kind' => 'email',
							'field' => ' . ',
						],
						[
							'kind' => 'email',
							'field' => 'title',
						],
						[
							'kind' => 'email',
							'field' => 'missing.contactEmail',
						],
						[
							'kind' => 'email',
							'field' => 'plainRef.contactEmail',
						],
						[
							'kind' => 'email',
							'field' => 'supplierRef.contactEmail',
						],
						[
							'kind' => 'email',
							'field' => ' supplierRef..a.b.c.d ',
						],
						[
							'kind' => 'email',
							'field' => 'plainRef.a.b.c.d.e.f',
						],
					],
				],
				[
					[
						'code' => 'notification-recipient-email-field-missing',
						'message' => 'Notification "termijn" recipient[0] kind=email requires a `field` naming the address, e.g.'
							.' "supplierRef.contactEmail".',
					],
					[
						'code' => 'notification-recipient-email-field-missing',
						'message' => 'Notification "termijn" recipient[1] kind=email requires a `field` naming the address, e.g.'
							.' "supplierRef.contactEmail".',
					],
					[
						'code' => 'notification-recipient-field-unknown',
						'message' => 'Notification "termijn" recipient[3] field "missing" is not declared on the schema.',
					],
					[
						'code' => 'notification-recipient-email-reference-has-no-ref',
						'message' => 'Notification "termijn" recipient[4] field "plainRef.contactEmail" goes through "plainRef",'
							.' which declares no `$ref`: the referenced object cannot be checked against a schema, so th'
							.'e rule would never send.',
					],
					[
						'code' => 'notification-recipient-email-path-too-deep',
						'message' => 'Notification "termijn" recipient[6] field "supplierRef..a.b.c.d" follows more than 3 refer'
							.'ences.',
					],
					[
						'code' => 'notification-recipient-email-reference-has-no-ref',
						'message' => 'Notification "termijn" recipient[7] field "plainRef.a.b.c.d.e.f" goes through "plainRef", '
							.'which declares no `$ref`: the referenced object cannot be checked against a schema, so the'
							.' rule would never send.',
					],
					[
						'code' => 'notification-recipient-email-path-too-deep',
						'message' => 'Notification "termijn" recipient[7] field "plainRef.a.b.c.d.e.f" follows more than 3 refer'
							.'ences.',
					],
				],
			],
		];
	}//end rules()
}//end class

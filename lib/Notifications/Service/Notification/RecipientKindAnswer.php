<?php

/**
 * RecipientKindAnswer: what one recipient will get for one notification kind.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The answer to "why did this person (not) get the message": for one user and
 * one declared notification, the channels that will be used, the layer that
 * decided them, whether an administrator forced them and why, and whether the
 * kind is internal only.
 *
 * It composes the two readings that already exist rather than adding a third:
 * the preference merge ({@see NotificationPreferenceServiceInterface::resolve()},
 * schema default, group default, user override) and the administrator's layer
 * above it ({@see ForcedChannelPolicy::decide()}). A user with an account is
 * inside the organisation, so the audience is internal (design D-3).
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
 */
class RecipientKindAnswer {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface                  $schemaMapper The schemas that declare notifications.
	 * @param NotificationPreferenceServiceInterface $preferences  The preference merge.
	 * @param ForcedChannelPolicy                    $policy       The administrator's layer above it.
	 * @param LoggerInterface                        $logger       The logger.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly NotificationPreferenceServiceInterface $preferences,
		private readonly ForcedChannelPolicy $policy,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The answer for one user and one notification, or null when no schema
	 * with that slug declares that notification.
	 *
	 * @param string             $userId          The recipient's user id.
	 * @param string             $schemaSlug      The schema that declares the notification.
	 * @param string             $notificationKey The notification's key in `x-openregister-notifications`.
	 * @param array<int, string> $scopes          Scopes to read the preference in, narrowest first.
	 *
	 * @return array<string, mixed>|null The answer (user, schema, notification, enabled, channels, layer,
	 *                                   forced, reason, internalOnly, refusal, layers), or null.
	 *
	 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
	 */
	public function answer(string $userId, string $schemaSlug, string $notificationKey, array $scopes = []): ?array {
		$declaration = $this->declaration(schemaSlug: $schemaSlug, notificationKey: $notificationKey);
		if ($declaration === null) {
			return null;
		}

		$effective = $this->preferences->resolve(
			resolution: new PreferenceResolution(
				schemaDefault: $declaration,
				userId: $userId,
				topic: new PreferenceTopic(schemaSlug: $schemaSlug, notificationKey: $notificationKey, scopes: $scopes)
			)
		);
		$decision = $this->policy->decide(
			preference: new KindPreference(resolved: $effective, declaration: $declaration),
			audience: RecipientAudience::Internal
		);

		return [
			'user' => $userId,
			'schema' => $schemaSlug,
			'notification' => $notificationKey,
			'enabled' => $decision['enabled'],
			'channels' => $decision['channels'],
			'layer' => $decision['layer'],
			'forced' => $decision['forced'],
			'reason' => $decision['reason'],
			'internalOnly' => (($declaration[ForcedChannelPolicy::INTERNAL_ONLY] ?? false) === true),
			'refusal' => $decision['refusal'],
			'layers' => ($effective['layers'] ?? []),
		];
	}//end answer()

	/**
	 * The declaration of one notification on the first schema with the slug.
	 *
	 * @param string $schemaSlug      The schema slug.
	 * @param string $notificationKey The notification key.
	 *
	 * @return array<string, mixed>|null The declaration, or null.
	 */
	private function declaration(string $schemaSlug, string $notificationKey): ?array {
		try {
			$schemas = $this->schemaMapper->findAll();
		} catch (Throwable $e) {
			$this->logger->warning('[RecipientKindAnswer] schema enumeration failed: ' . $e->getMessage());
			return null;
		}

		foreach ($schemas as $schema) {
			if ($schema instanceof Schema === false || (string)($schema->getSlug() ?? $schema->getId()) !== $schemaSlug) {
				continue;
			}

			$declared = ((($schema->getConfiguration() ?? [])['x-openregister-notifications'] ?? [])[$notificationKey] ?? null);
			if (is_array($declared) === true) {
				return $declared;
			}
		}

		return null;
	}//end declaration()
}//end class

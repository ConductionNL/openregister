<?php

/**
 * One notification kind's preference lookup: which kind, at which scopes, over which channels.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * The schema slug, notification key, candidate scopes and declared channels
 * one resolution reads its layers for, and the fold of one layer over the
 * answer so far.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class PreferenceLookup {

	/**
	 * Constructor.
	 *
	 * @param string                  $schemaSlug      The owning schema's slug.
	 * @param string                  $notificationKey The notification annotation key.
	 * @param array<int, string>      $scopes          Candidate scopes, narrowest first.
	 * @param array<int, string>|null $allowedChannels The channels the schema declared, opt-in ones included.
	 */
	public function __construct(
		public readonly string $schemaSlug,
		public readonly string $notificationKey,
		public readonly array $scopes,
		public readonly ?array $allowedChannels,
	) {
	}//end __construct()

	/**
	 * Fold one layer's stored value over the answer so far.
	 *
	 * A layer chooses among the channels the schema declared, its default and
	 * its opt-in ones, never beyond them. A group cannot give its members a
	 * channel the rule does not have, and neither can a user.
	 *
	 * @param array{enabled: bool, channels: array<int, string>|null, source: string, scope: string} $base  The answer so far.
	 * @param array{value: array<string, mixed>, scope: string, layer: string}                       $found The layer's value,
	 *        the scope it was pinned to and the layer's name.
	 *
	 * @return array{enabled: bool, channels: array<int, string>|null, source: string, scope: string}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function applyLayer(array $base, array $found): array {
		$value = $found['value'];
		$enabled = (bool)($value['enabled'] ?? $base['enabled']);
		$channels = $base['channels'];
		if (isset($value['channels']) === true && is_array($value['channels']) === true) {
			$channels = array_values(
				array_intersect($value['channels'], ($this->allowedChannels ?? $value['channels']))
			);
		}

		return [
			'enabled' => $enabled,
			'channels' => $channels,
			'source' => $found['layer'],
			'scope' => $found['scope'],
		];
	}//end applyLayer()
}//end class

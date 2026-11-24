<?php

/**
 * ShareCrosses — what a federated share sends: the object's data, its files,
 * its public timeline entries, or a subset, defaulting to the narrowest.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Federation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\FederatedShare;

/**
 * The declaration of what crosses, and the payload it allows.
 *
 * Sharing a case with another organisation should not hand over its internal
 * notes by default, so anything a share does not declare is not sent, and a
 * share that declares nothing sends the narrowest part: the object's data.
 * A declaration naming a part this does not know is refused when the share is
 * made, never silently narrowed, so the sender sees what they asked for or an
 * error.
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
 */
final class ShareCrosses {

	/**
	 * The object's own properties.
	 *
	 * @var string
	 */
	public const DATA = 'data';

	/**
	 * The files attached to the object.
	 *
	 * @var string
	 */
	public const FILES = 'files';

	/**
	 * The object's public timeline entries.
	 *
	 * @var string
	 */
	public const TIMELINE = 'timeline';

	/**
	 * Every part, in their canonical order.
	 *
	 * @var list<string>
	 */
	public const PARTS = [self::DATA, self::FILES, self::TIMELINE];

	/**
	 * What a share that declares nothing sends: the narrowest part.
	 *
	 * @var list<string>
	 */
	public const DEFAULT = [self::DATA];

	/**
	 * The `@self` keys that carry a part other than the data.
	 *
	 * @var array<string, list<string>>
	 */
	private const SELF_KEYS = [
		self::FILES => ['files'],
		self::TIMELINE => ['timeline'],
	];

	/**
	 * The declaration a share is made with, from what the sender asked for.
	 *
	 * @param mixed $requested A list of parts, or null/empty for the default.
	 *
	 * @return list<string> The parts, deduplicated, in canonical order.
	 *
	 * @throws InvalidArgumentException When it is not a list of known parts.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
	 */
	public static function declared(mixed $requested): array {
		if ($requested === null || $requested === [] || $requested === '') {
			return self::DEFAULT;
		}

		if (is_array($requested) === false || array_is_list($requested) === false) {
			throw new InvalidArgumentException('What crosses must be a list of: ' . implode(', ', self::PARTS));
		}

		foreach ($requested as $part) {
			if (is_string($part) === false || in_array($part, self::PARTS, true) === false) {
				throw new InvalidArgumentException('Unknown part of an object to share; known parts are: ' . implode(', ', self::PARTS));
			}
		}

		return array_values(array_intersect(self::PARTS, $requested));
	}//end declared()

	/**
	 * The parts a stored share sends.
	 *
	 * A share made before the declaration existed has none and sends the
	 * default; a stored part this does not know is not sent.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return list<string> The parts.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
	 */
	public static function of(FederatedShare $share): array {
		$stored = $share->getCrosses();
		if (is_array($stored) === false || $stored === []) {
			return self::DEFAULT;
		}

		return array_values(array_intersect(self::PARTS, $stored));
	}//end of()

	/**
	 * An object as a share serves it: only the declared parts.
	 *
	 * Without `data` the object's own properties are left out and only its
	 * `@self` metadata is sent; without `files` or `timeline` those keys are
	 * taken out of `@self`.
	 *
	 * @param array<string, mixed> $rendered The rendered object.
	 * @param list<string>         $crosses  The declared parts.
	 *
	 * @return array<string, mixed> The object, narrowed.
	 *
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
	 */
	public static function shape(array $rendered, array $crosses): array {
		$self = $rendered['@self'] ?? null;
		if (in_array(self::DATA, $crosses, true) === false) {
			$rendered = [];
			if ($self !== null) {
				$rendered['@self'] = $self;
			}
		}

		if (is_array($self) === false) {
			return $rendered;
		}

		foreach (self::SELF_KEYS as $part => $keys) {
			if (in_array($part, $crosses, true) === true) {
				continue;
			}

			foreach ($keys as $key) {
				unset($rendered['@self'][$key]);
			}
		}

		return $rendered;
	}//end shape()
}//end class

<?php

/**
 * ConfigurationLayer — the four places a configuration value can be set.
 *
 * The order is the precedence order REQ-CAD-003 names: instance, register,
 * bundle, subject. Later wins. An instance sets a mail relay once; a register
 * may differ; a bundle binds a set of schemas to a shared answer; and one
 * subject may still override its bundle, which is exactly the exception the
 * bundle exists to make visible.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * The layer vocabulary and its precedence order.
 */
final class ConfigurationLayer {

	/**
	 * The whole instance. One address per key, so it carries no reference.
	 *
	 * @var string
	 */
	public const INSTANCE = 'instance';

	/**
	 * One register.
	 *
	 * @var string
	 */
	public const REGISTER = 'register';

	/**
	 * One named bundle, bound to many subjects.
	 *
	 * @var string
	 */
	public const BUNDLE = 'bundle';

	/**
	 * One subject: a schema, a case type, a role.
	 *
	 * @var string
	 */
	public const SUBJECT = 'subject';

	/**
	 * The layers in precedence order, lowest first. The LAST layer holding a
	 * value is the one in effect.
	 *
	 * @var array<int, string>
	 */
	public const ORDER = [
		self::INSTANCE,
		self::REGISTER,
		self::BUNDLE,
		self::SUBJECT,
	];

	/**
	 * Whether a layer name is one this system knows.
	 *
	 * @param string $layer The layer name.
	 *
	 * @return boolean True when the layer is known.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public static function isKnown(string $layer): bool {
		return in_array($layer, self::ORDER, true);

	}//end isKnown()

	/**
	 * Where a layer sits in the precedence order.
	 *
	 * @param string $layer The layer name.
	 *
	 * @return integer The rank, or -1 when the layer is unknown.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public static function rank(string $layer): int {
		$rank = array_search($layer, self::ORDER, true);
		if ($rank === false) {
			return -1;
		}

		return (int)$rank;

	}//end rank()

	/**
	 * Whether a layer addresses one subject and therefore needs a reference.
	 *
	 * The instance layer does not: there is exactly one instance, and making
	 * callers pass a reference for it would give the same value two spellings
	 * and therefore two rows.
	 *
	 * @param string $layer The layer name.
	 *
	 * @return boolean True when the layer requires a reference.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public static function requiresReference(string $layer): bool {
		return $layer !== self::INSTANCE;

	}//end requiresReference()
}//end class

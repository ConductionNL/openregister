<?php

/**
 * Reads and checks the configuration of a tag-object flow step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCP\IL10N;
use UnexpectedValueException;

/**
 * The operation, tag, target, colour and create rule of a tag step.
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */
final class TagStepConfig {
	/**
	 * The operations a step may name.
	 *
	 * @var list<string>
	 */
	private const OPERATIONS = ['add', 'remove'];

	/**
	 * The values that switch `createIfMissing` off.
	 *
	 * @var list<mixed>
	 */
	private const OFF = [false, 0, '0', 'false'];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the refusals.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Refuse a configuration the run could not act on.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration cannot be run.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function validate(array $config): void {
		if (in_array(($config['operation'] ?? null), self::OPERATIONS, true) === false) {
			throw new UnexpectedValueException($this->l10n->t('A tag step\'s action must be "add" or "remove".'));
		}

		$tag = ($config['tag'] ?? null);
		if (is_string($tag) === false || trim($tag) === '') {
			throw new UnexpectedValueException($this->l10n->t('A tag step needs the name of a tag.'));
		}

		$uuid = ($config['uuid'] ?? null);
		if ($uuid !== null && is_string($uuid) === false) {
			throw new UnexpectedValueException($this->l10n->t('A tag step\'s object must be text.'));
		}

		$this->colour(config: $config);
	}//end validate()

	/**
	 * The configured colour as six lower-case hex digits without `#`, or null.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string|null The colour.
	 *
	 * @throws UnexpectedValueException When the colour is not six hex digits.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function colour(array $config): ?string {
		$color = ($config['color'] ?? null);
		if ($color === null || $color === '') {
			return null;
		}

		if (is_string($color) === false || preg_match('/^#?[0-9a-fA-F]{6}$/', $color) !== 1) {
			throw new UnexpectedValueException(
				$this->l10n->t('A tag step\'s colour must be six hex digits, such as d94c3d.')
			);
		}

		return strtolower(ltrim($color, '#'));
	}//end colour()

	/**
	 * Whether `add` may create a missing tag: on unless the step switched it off.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return bool True unless `createIfMissing` is off.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function mayCreate(array $config): bool {
		return in_array(($config['createIfMissing'] ?? true), self::OFF, true) === false;
	}//end mayCreate()
}//end class

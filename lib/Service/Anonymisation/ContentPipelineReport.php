<?php

/**
 * Reports every content-altering step of the anonymisation pipeline and its state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * One entry per step: `on`, `off` (naming the setting that turned it off) or `always-on`.
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 */
class ContentPipelineReport {
	public const STATE_ON = 'on';
	public const STATE_OFF = 'off';
	public const STATE_ALWAYS_ON = 'always-on';

	/**
	 * The setting that switches each switchable step; a step not listed has no switch.
	 *
	 * @var array<string, string>
	 */
	private const SETTINGS = [
		PipelineSteps::ANONYMISER => 'entityRecognitionEnabled',
	];

	/**
	 * Constructor.
	 *
	 * @param AnonymisationBackendServiceInterface $backend The anonymisation backend state.
	 * @param PipelineSteps                        $steps   The registered steps.
	 */
	public function __construct(
		private readonly AnonymisationBackendServiceInterface $backend,
		private readonly PipelineSteps $steps,
	) {
	}//end __construct()

	/**
	 * Every step with its state, the setting that controls it and the file types it applies to.
	 *
	 * @return list<array{step: string, label: string, state: string, setting: string|null, appliesTo: list<string>|null}>
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
	 */
	public function report(): array {
		$entries = [];
		foreach ($this->steps->ids() as $step) {
			$entries[] = [
				'step' => $step,
				'label' => $this->steps->label(step: $step),
				'state' => $this->stateOf(step: $step),
				'setting' => self::SETTINGS[$step] ?? null,
				'appliesTo' => $this->steps->mimeTypes(step: $step),
			];
		}

		return $entries;
	}//end report()

	/**
	 * The state of one step.
	 *
	 * @param string $step The step id.
	 *
	 * @return string One of the STATE_* values.
	 */
	private function stateOf(string $step): string {
		if (isset(self::SETTINGS[$step]) === false) {
			return self::STATE_ALWAYS_ON;
		}

		if ($this->backend->getState()->entityRecognitionEnabled === true) {
			return self::STATE_ON;
		}

		return self::STATE_OFF;
	}//end stateOf()
}//end class

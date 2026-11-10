<?php

/**
 * One finding about a form: the property it concerns, a code and a message.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * A finding as the validator reports it and an upload refusal carries it.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */
final class FormFinding {
	/**
	 * Constructor.
	 *
	 * @param string $property The property the finding is about; empty for the form as a whole.
	 * @param string $code     The finding code.
	 * @param string $message  The translated message.
	 */
	public function __construct(
		public readonly string $property,
		public readonly string $code,
		public readonly string $message,
	) {
	}//end __construct()

	/**
	 * The finding in the shape the API returns.
	 *
	 * @return array{property: string, code: string, message: string}
	 */
	public function toArray(): array {
		return ['property' => $this->property, 'code' => $this->code, 'message' => $this->message];
	}//end toArray()
}//end class

<?php

/**
 * OpenRegister OrganisationFromPropertyException
 *
 * The refusal of a save whose organisation property cannot be honoured.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Thrown when a save cannot take its organisation from the property the schema names.
 *
 * Three refusals, each with its own answer, because they ask the caller for
 * three different things: a contradiction and an unknown organisation are
 * 422 (the body is wrong), while not being a member is 403 (the body is
 * right and the caller may not write it). Every body names the property and
 * the organisation it named, so a form can mark the field.
 *
 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
 */
class OrganisationFromPropertyException extends Exception {

	/**
	 * `@self.organisation` names one organisation and the property another.
	 *
	 * @var string
	 */
	public const CONTRADICTION = 'organisation-contradicts-property';

	/**
	 * The property names an organisation that does not exist.
	 *
	 * @var string
	 */
	public const UNKNOWN = 'organisation-unknown';

	/**
	 * The caller is not a member of the organisation the property names.
	 *
	 * @var string
	 */
	public const NOT_A_MEMBER = 'organisation-not-a-member';

	/**
	 * Build the refusal.
	 *
	 * @param string               $error        One of the three codes above.
	 * @param int                  $statusCode   The HTTP status the answer carries.
	 * @param string               $property     The property the schema takes the organisation from.
	 * @param string|null          $organisation The organisation the property named.
	 * @param array<string, mixed> $extra        Anything else the body names.
	 * @param string               $message      The message.
	 */
	final private function __construct(
		private readonly string $error,
		private readonly int $statusCode,
		private readonly string $property,
		private readonly ?string $organisation,
		private readonly array $extra,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The body names one organisation and the property another: 422.
	 *
	 * @param string $property       The property.
	 * @param string $organisation   The organisation the property names.
	 * @param string $selfSubmission The organisation `@self.organisation` names.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
	 */
	public static function contradiction(string $property, string $organisation, string $selfSubmission): self {
		return new self(
			error: self::CONTRADICTION,
			statusCode: 422,
			property: $property,
			organisation: $organisation,
			extra: ['selfOrganisation' => $selfSubmission],
			message: 'The organisation "' . $selfSubmission . '" given in @self.organisation is not the one "'
				. $property . '" names ("' . $organisation . '"). This schema takes the organisation from "'
				. $property . '"; leave @self.organisation out.'
		);
	}//end contradiction()

	/**
	 * The property names no existing organisation: 422.
	 *
	 * @param string $property     The property.
	 * @param string $organisation The value it held.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
	 */
	public static function unknown(string $property, string $organisation): self {
		return new self(
			error: self::UNKNOWN,
			statusCode: 422,
			property: $property,
			organisation: $organisation,
			extra: [],
			message: '"' . $property . '" names the organisation "' . $organisation . '", which does not exist.'
		);
	}//end unknown()

	/**
	 * The caller is not a member of the organisation the property names: 403.
	 *
	 * @param string $property     The property.
	 * @param string $organisation The organisation it names.
	 *
	 * @return self The refusal.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
	 */
	public static function notAMember(string $property, string $organisation): self {
		return new self(
			error: self::NOT_A_MEMBER,
			statusCode: 403,
			property: $property,
			organisation: $organisation,
			extra: [],
			message: '"' . $property . '" names the organisation "' . $organisation
				. '", which you are not a member of.'
		);
	}//end notAMember()

	/**
	 * The HTTP status the answer carries.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * The answer body: the code, the property, the organisation and the message.
	 *
	 * @return array<string, mixed> The body.
	 *
	 * @spec openspec/changes/object-organisation-from-a-property/specs/saas-multi-tenant/spec.md#requirement-a-schema-can-derive-an-objects-organisation-from-a-property-req-oop-001
	 */
	public function getBody(): array {
		return array_merge(
			[
				'error' => $this->error,
				'property' => $this->property,
				'organisation' => $this->organisation,
				'message' => $this->getMessage(),
			],
			$this->extra
		);
	}//end getBody()
}//end class

<?php

/**
 * OpenRegister SharedMasterDataWriteException
 *
 * Thrown when an organisation that only CONSUMES a shared register or schema
 * tries to write to it.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use OCP\AppFramework\Http;

/**
 * A consumer of shared master data may read the holder's rows and may not write them.
 *
 * Design D-2 is explicit that read-only to the consumer is a property of the
 * resolution and not of the user interface: a screen that hides the save button
 * is a screen somebody bypasses with the API. So the refusal lives on the write
 * path, and this is the type it raises.
 *
 * The message NAMES THE HOLDER. A bare "forbidden" tells a caseworker in the
 * samenwerkingsverband that the code list is broken. "Organisation X holds this
 * register" tells them who to ask, which is the whole difference between a dead
 * end and a next step.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */
class SharedMasterDataWriteException extends Exception {

	/**
	 * Build the refusal.
	 *
	 * @param string $holderUuid The UUID of the holding organisation.
	 * @param string|null $holderName The holder's name, when known.
	 * @param string $resourceType Either `register` or `schema`.
	 * @param string|null $resourceTitle The title of the register or schema, when known.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function __construct(
		private readonly string $holderUuid,
		private readonly ?string $holderName=null,
		private readonly string $resourceType='register',
		?string $resourceTitle=null,
	) {
		parent::__construct(message: $this->refusal(resourceTitle: $resourceTitle), code: Http::STATUS_FORBIDDEN);
	}//end __construct()

	/**
	 * Compose the refusal sentence, naming the holder and the refused resource.
	 *
	 * @param string|null $resourceTitle The title of the register or schema, when known.
	 *
	 * @return string The message.
	 */
	private function refusal(?string $resourceTitle): string {
		$holder  = $this->holderLabel();
		$subject = $this->subjectLabel(resourceTitle: $resourceTitle);

		return 'This ' . $subject . ' is shared master data held by ' . $holder
			. '. Your organisation reads it and cannot change it. Ask ' . $holder . ' to make the change.';
	}//end refusal()

	/**
	 * The holder as the sentence names it: its name, or its uuid when it has none.
	 *
	 * @return string The holder.
	 */
	private function holderLabel(): string {
		if ($this->holderName === null || $this->holderName === '') {
			return $this->holderUuid;
		}

		return $this->holderName;
	}//end holderLabel()

	/**
	 * The refused resource as the sentence names it: its type, with its title when known.
	 *
	 * @param string|null $resourceTitle The title of the register or schema, when known.
	 *
	 * @return string The resource.
	 */
	private function subjectLabel(?string $resourceTitle): string {
		if ($resourceTitle === null || $resourceTitle === '') {
			return $this->resourceType;
		}

		return $this->resourceType . ' "' . $resourceTitle . '"';
	}//end subjectLabel()

	/**
	 * The UUID of the organisation that holds the shared resource.
	 *
	 * @return string The holder UUID.
	 */
	public function getHolderUuid(): string {
		return $this->holderUuid;

	}//end getHolderUuid()

	/**
	 * The holder's name, when it could be read.
	 *
	 * @return string|null The holder name, or null.
	 */
	public function getHolderName(): ?string {
		return $this->holderName;

	}//end getHolderName()

	/**
	 * What was refused.
	 *
	 * @return string Either `register` or `schema`.
	 */
	public function getResourceType(): string {
		return $this->resourceType;

	}//end getResourceType()
}//end class

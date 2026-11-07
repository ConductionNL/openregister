<?php

/**
 * OpenRegister forms domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Form\FormDefinitionResolverInterface;
use OCA\OpenRegister\Service\Form\FormDestinationValidatorInterface;
use OCA\OpenRegister\Service\Form\FormSubmitServiceInterface;
use OCA\OpenRegister\Service\Form\FormUploadStoreInterface;

/**
 * The domain services of the forms API.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final class FormsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param FormDefinitionResolverInterface   $resolver  Resolves a stored form.
	 * @param FormSubmitServiceInterface        $submitter Creates the destination.
	 * @param FormDestinationValidatorInterface $validator Judges a mapping.
	 * @param FormUploadStoreInterface          $uploads   Holds upload tokens.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function __construct(
		public readonly FormDefinitionResolverInterface $resolver,
		public readonly FormSubmitServiceInterface $submitter,
		public readonly FormDestinationValidatorInterface $validator,
		public readonly FormUploadStoreInterface $uploads,
	) {
	}//end __construct()
}//end class

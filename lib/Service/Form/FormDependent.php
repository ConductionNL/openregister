<?php

/**
 * A published form that submits into a schema, as its owning app announces it.
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
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use Closure;

/**
 * What the schema-save check needs of a form: its mapping, its author and how to unpublish it.
 *
 * The form lives in its owning app (buildiq, portaliq, pipelinq, ...), so
 * the owning app unpublishes it; OpenRegister only calls the closure.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */
final class FormDependent {

	/**
	 * The form's id in its owning app.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * The owning app's id; its `formDestinationBreak` setting decides.
	 *
	 * @var string
	 */
	public readonly string $app;

	/**
	 * The form's title, for the response and the notification.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * `public` or `authenticated`, when the form is not internal.
	 *
	 * @var string|null
	 */
	public readonly ?string $audience;

	/**
	 * Constructor.
	 *
	 * Takes a PublishedForm (as `$form`, or positionally in place of `$id`) or the id, app, title
	 * and audience of the published contract; a PublishedForm wins.
	 *
	 * @param string|PublishedForm|null $id        The form's id in its owning app, or the published form.
	 * @param string|null               $app       The owning app's id.
	 * @param string|null               $title     The form's title.
	 * @param string|null               $author    The user id to notify, when known.
	 * @param array<string, mixed>      $mapping   The form's mapping into the schema.
	 * @param Closure|null              $unpublish Unpublishes the form in its owning app.
	 * @param string|null               $audience  `public` or `authenticated`, when the form is not internal.
	 * @param PublishedForm|null        $form      Which form, in which app, its title and its audience.
	 *
	 * @throws \InvalidArgumentException When neither an id, app and title nor a PublishedForm is given.
	 */
	public function __construct(
		string|PublishedForm|null $id = null,
		?string $app = null,
		?string $title = null,
		public readonly ?string $author = null,
		public readonly array $mapping = [],
		public readonly ?Closure $unpublish = null,
		?string $audience = null,
		?PublishedForm $form = null,
	) {
		if ($id instanceof PublishedForm) {
			$form ??= $id;
			$id = null;
		}

		if ($form === null && ($id === null || $app === null || $title === null)) {
			throw new \InvalidArgumentException('FormDependent needs an id, app and title, or a PublishedForm.');
		}

		$form ??= new PublishedForm(id: (string) $id, app: (string) $app, title: (string) $title, audience: $audience);

		$this->id       = $form->id;
		$this->app      = $form->app;
		$this->title    = $form->title;
		$this->audience = $form->audience;
	}//end __construct()
}//end class

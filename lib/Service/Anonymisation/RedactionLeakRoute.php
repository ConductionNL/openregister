<?php

/**
 * OpenRegister RedactionLeakRoute
 *
 * The ways a redacted value survives in a file that looks redacted, carried
 * over from filinq (REQ-RWB-01) so every path through the engine is checked.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The leak routes a written copy is verified against.
 *
 * A closed list with a guard, not a checklist somebody extends: the verifier
 * refuses to call a copy clean unless every route here was examined, because
 * a verification that reports success for a route it never looked at is
 * indistinguishable from a clean document.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-written-copy-cannot-be-read-back-req-rrs-001-carried-from-filinq-req-rwb-01
 */
final class RedactionLeakRoute {

	/**
	 * The text is still under the mark.
	 */
	public const TEXT_UNDER_MARK = 'text_under_mark';

	/**
	 * A thumbnail or preview stream drawn before the mark was applied.
	 */
	public const EMBEDDED_PREVIEW = 'embedded_preview';

	/**
	 * XMP packets: title, author, subject, keywords, history.
	 */
	public const XMP = 'xmp';

	/**
	 * EXIF and document properties.
	 */
	public const EXIF = 'exif';

	/**
	 * An incremental save or a tracked revision that kept an earlier version.
	 */
	public const INCREMENTAL_UPDATE = 'incremental_update';

	/**
	 * Annotation contents and form field values.
	 */
	public const ANNOTATIONS_AND_FIELDS = 'annotations_and_fields';

	/**
	 * A file attached inside the document.
	 */
	public const EMBEDDED_ATTACHMENT = 'embedded_attachment';

	/**
	 * Every route, in the order they are examined.
	 *
	 * @var list<string>
	 */
	public const ALL = [
		self::TEXT_UNDER_MARK,
		self::EMBEDDED_PREVIEW,
		self::XMP,
		self::EXIF,
		self::INCREMENTAL_UPDATE,
		self::ANNOTATIONS_AND_FIELDS,
		self::EMBEDDED_ATTACHMENT,
	];

	/**
	 * What each route means, for the operator reading a failed verification.
	 *
	 * @var array<string, string>
	 */
	public const DESCRIPTIONS = [
		self::TEXT_UNDER_MARK => 'the redacted text is still in the file, underneath the mark',
		self::EMBEDDED_PREVIEW => 'a preview image inside the file still shows the unredacted page',
		self::XMP => 'the redacted value is still in the XMP metadata',
		self::EXIF => 'the redacted value is still in the EXIF or document properties',
		self::INCREMENTAL_UPDATE => 'an earlier revision of the file is still inside it',
		self::ANNOTATIONS_AND_FIELDS => 'an annotation or form field still holds the redacted value',
		self::EMBEDDED_ATTACHMENT => 'a file attached inside this document still holds the redacted value',
	];
}//end class

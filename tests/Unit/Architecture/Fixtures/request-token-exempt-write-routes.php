<?php

/**
 * Write routes that still skip Nextcloud's request-token check, with the reason.
 *
 * Read by WriteRoutesRequireRequestTokenTest. Reasons start with one kind:
 *   public-token       a share-token or anonymous flow on a public page
 *   cors-api           a CORS API, where Nextcloud refuses a cookie session without the token
 *   header-credential  authenticated by a signed header, not the session cookie
 *
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

return [
	'accessLink#comment' => 'public-token: public share-token or anonymous flow, rate limited',
	'accessLink#upload' => 'public-token: public share-token or anonymous flow, rate limited',
	'credential#brokerRequest' => 'header-credential: authenticated by the signed X-Credential-Token header',
	'federation#createObject' => 'public-token: public share-token or anonymous flow, rate limited',
	'federation#deleteObject' => 'public-token: public share-token or anonymous flow, rate limited',
	'federation#updateObject' => 'public-token: public share-token or anonymous flow, rate limited',
	'forms#submit' => 'public-token: public share-token or anonymous flow, rate limited',
	'forms#upload' => 'public-token: public share-token or anonymous flow, rate limited',
	'journeyRun#answer' => 'public-token: public share-token or anonymous flow, rate limited',
	'journeyRun#save' => 'public-token: public share-token or anonymous flow, rate limited',
	'journeyRun#start' => 'public-token: public share-token or anonymous flow, rate limited',
	'graphQL#execute' => 'cors-api: Nextcloud CORS handling refuses a cookie session without the token',
	'mcpServer#handle' => 'cors-api: Nextcloud CORS handling refuses a cookie session without the token',
	'portalTask#complete' => 'public-token: public share-token or anonymous flow, rate limited',
	'user#login' => 'public-token: public share-token or anonymous flow, rate limited',
	'user#logout' => 'public-token: public share-token or anonymous flow, rate limited',
];

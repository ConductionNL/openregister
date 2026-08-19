<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

/**
 * camelCase route slug (`paymentTransaction#callback`), guarded.
 */
class PaymentTransactionController extends Controller
{
    #[PublicPage]
    #[NoCSRFRequired]
    public function callback(string $reference): JSONResponse
    {
        return new JSONResponse(['reference' => $reference]);
    }
}

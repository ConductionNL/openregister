<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;

/**
 * camelCase route slug (`paymentTransaction#callback`), unguarded.
 *
 * The half of the pair that proves gate-5 can SEE a camelCase slug at all.
 * Its guarded twin lives in the `guarded` fixture.
 */
class PaymentTransactionController extends Controller
{
    public function callback(string $reference): JSONResponse
    {
        return new JSONResponse(['reference' => $reference]);
    }
}

<?php

namespace Modules\Fees\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fees\Services\OnlinePayments;

/** Razorpay and Cashfree webhooks for one institution (resolved from the URL). The raw body is verified before anything is read from it. */
class WebhookController
{
    public function handle(Request $request, string $gateway, OnlinePayments $online): JsonResponse
    {
        $r = $online->webhook($gateway, $request->getContent(), $request->headers->all());

        return response()->json(['result' => $r['result']], $r['status']);
    }
}

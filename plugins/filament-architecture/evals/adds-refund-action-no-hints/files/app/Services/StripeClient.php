<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class StripeClient
{
    public function __construct(private string $secret) {}

    /**
     * Refund part or all of a charge. Returns the Stripe refund id (re_...).
     *
     * @throws RuntimeException when Stripe rejects the refund
     */
    public function refund(string $chargeId, int $amountPence): string
    {
        $response = Http::withToken($this->secret)
            ->asForm()
            ->post('https://api.stripe.com/v1/refunds', [
                'charge' => $chargeId,
                'amount' => $amountPence,
            ]);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message', 'Stripe refund failed'));
        }

        return $response->json('id');
    }
}

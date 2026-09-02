<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Throwable;

/**
 * PaystackService
 *
 * Thin wrapper around Paystack's transaction API. Activation logic does
 * NOT live here — this class only talks to Paystack. SubscriptionService
 * owns what happens after a payment is confirmed successful.
 */
class PaystackService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.paystack.co',
            'headers'  => [
                'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    /**
     * Generate a unique, non-guessable payment reference.
     * Vouchers/payments should never use sequential/short codes (see review
     * note on brute-forceable identifiers).
     */
    public function generateReference(): string
    {
        return 'HBS_' . Str::upper(Str::random(20));
    }

    public function initializeTransaction(string $email, int $amountKobo, string $reference, array $metadata = []): array
    {
        try {
            $response = $this->client->post('/transaction/initialize', [
                'json' => [
                    'email'        => $email,
                    'amount'       => $amountKobo,
                    'reference'    => $reference,
                    'currency'     => config('services.paystack.currency', 'GHS'),
                    'callback_url' => config('services.paystack.callback_url'),
                    'metadata'     => $metadata,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'success'          => $body['status'] ?? false,
                'authorization_url' => $body['data']['authorization_url'] ?? null,
                'access_code'      => $body['data']['access_code'] ?? null,
                'reference'        => $body['data']['reference'] ?? $reference,
                'raw'              => $body,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Called both from the /transaction/verify redirect flow AND as a
     * defense-in-depth check inside the webhook handler — never trust the
     * webhook payload's amount/status alone (see review note on webhook
     * idempotency + double verification).
     */
    public function verifyTransaction(string $reference): array
    {
        try {
            $response = $this->client->get("/transaction/verify/{$reference}");
            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => $body['status'] ?? false,
                'data'    => $body['data'] ?? null,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify the X-Paystack-Signature header against the raw request body.
     * Must be called with the RAW (unparsed) request body — Laravel's
     * request()->getContent() — not a re-encoded json_encode of the parsed
     * array, which can produce a different byte sequence and fail signature
     * verification.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        if (! $signatureHeader) {
            return false;
        }

        $expected = hash_hmac('sha512', $rawBody, config('services.paystack.secret_key'));

        return hash_equals($expected, $signatureHeader);
    }
}

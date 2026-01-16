<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function sendTemplateMessage(?string $to, string $template, array $bodyParams): bool
    {
        $toNumber = $this->normalizeNumber($to);
        $config = config('services.whatsapp');

        if (empty($toNumber) || empty($config['phone_number_id']) || empty($config['access_token'])) {
            Log::warning('WhatsApp send skipped due to missing config or recipient.', [
                'to' => $to,
            ]);
            return false;
        }

        $url = rtrim($config['base_url'] ?? 'https://graph.facebook.com', '/')
            . '/'
            . ltrim($config['api_version'] ?? 'v18.0', '/')
            . '/'
            . $config['phone_number_id']
            . '/messages';

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $toNumber,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => [
                    'code' => $config['template_language'] ?? 'en',
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => array_map(
                            fn ($param) => ['type' => 'text', 'text' => (string) $param],
                            $bodyParams
                        ),
                    ],
                ],
            ],
        ];

        $response = Http::withToken($config['access_token'])
            ->timeout(10)
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('WhatsApp send failed.', [
                'to' => $toNumber,
                'template' => $template,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    private function normalizeNumber(?string $mobile): ?string
    {
        if (empty($mobile)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $mobile);
        if ($digits === '') {
            return null;
        }

        $defaultCountryCode = config('services.whatsapp.default_country_code', '91');
        if (strlen($digits) === 10 && !empty($defaultCountryCode)) {
            return $defaultCountryCode . $digits;
        }

        return $digits;
    }
}

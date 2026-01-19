<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function sendTest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string'],
        ]);

        $result = app(WhatsAppService::class)->sendTemplateMessageResult(
            $validated['to'],
            'new_shht_dispatch_assigned',
            [
                'Demo Client',
                'SO-12345',
                'ORD-98765',
                '15000',
                'Demo Dispatcher',
            ]
        );

        return response()->json([
            'code' => ($result['ok'] ?? false) ? 200 : 500,
            'status' => (bool) ($result['ok'] ?? false),
            'message' => ($result['ok'] ?? false) ? 'WhatsApp test message sent.' : 'Failed to send WhatsApp test message.',
            'error' => $result['error'] ?? null,
            'provider_status' => $result['status'] ?? null,
            'provider_response' => $result['body'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 500);
    }
}

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

        $sent = app(WhatsAppService::class)->sendTemplateMessage(
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
            'code' => $sent ? 200 : 500,
            'status' => $sent,
            'message' => $sent ? 'WhatsApp test message sent.' : 'Failed to send WhatsApp test message.',
        ], $sent ? 200 : 500);
    }
}

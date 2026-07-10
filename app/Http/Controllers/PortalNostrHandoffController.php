<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PortalNostrHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Local same-origin bridge the embedded chat layer (einundzwanzig/group)
 * calls to connect the portal from the same welshman signer used for the chat
 * login — the mobile half of the single login. The WebView can neither reach
 * the remote portal (CORS) nor write the native keystore, so these routes
 * proxy both hops and persist the token.
 */
final class PortalNostrHandoffController extends Controller
{
    public function __construct(private readonly PortalNostrHandoff $handoff) {}

    /**
     * Issue a portal login challenge (k1) for the in-page signer to sign.
     */
    public function challenge(): JsonResponse
    {
        $k1 = $this->handoff->challenge();

        if ($k1 === null) {
            return response()->json(['status' => 'ERROR'], 502);
        }

        return response()->json(['k1' => $k1]);
    }

    /**
     * Exchange the signed kind-22242 event for a portal token and store it.
     */
    public function store(Request $request): JsonResponse
    {
        // Validate explicitly and return JSON: this app only renders exceptions
        // as JSON for api/* paths (bootstrap/app.php), so $request->validate()
        // would redirect (302) here instead of answering the WebView with 422.
        $validator = Validator::make($request->all(), [
            'k1' => ['required', 'string', 'size:64'],
            'event' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'ERROR', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $ok = $this->handoff->exchange($validated['k1'], $validated['event']);

        return response()->json(['status' => $ok ? 'OK' : 'ERROR'], $ok ? 200 : 502);
    }
}

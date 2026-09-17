<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\PrepareHotspotSessionRequest;
use App\Models\HotspotSession;
use App\Services\HotspotSessionService;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Http\Request;

class HotspotSessionController extends Controller
{
    public function current(Request $request, HotspotSessionService $service)
    {
        $validated = $request->validate([
            'router_id' => ['required', 'integer', 'exists:routers,id'],
        ]);

        try {
            return response()->json($service->current($request->user(), (int) $validated['router_id']));
        } catch (LockTimeoutException) {
            return response()->json(['connected' => null, 'status' => 'unknown', 'session' => null,
                'message' => 'A connection update is in progress. Please retry shortly.']);
        }
    }

    public function prepare(PrepareHotspotSessionRequest $request, HotspotSessionService $service)
    {
        try {
            $prepared = $service->prepare(
                $request->user(),
                $request->integer('router_id'),
                $request->string('login_url')->toString(),
                $request->input('mac_address'),
                $request->input('ip_address'),
            );
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Another connection attempt is already in progress. Please wait a moment.'], 409);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json($prepared, 201);
    }

    public function status(Request $request, string $session, HotspotSessionService $service)
    {
        $sessionModel = HotspotSession::where('public_id', $session)
            ->where('customer_id', $request->user()->id)
            ->with('router')
            ->firstOrFail();

        try {
            $sessionModel = $service->confirm($request->user(), $sessionModel);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'A connection update is in progress. Please retry shortly.'], 409);
        } catch (\RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => 'Could not confirm the hotspot connection. Please retry shortly.'], 502);
        }

        return response()->json([
            'session_id' => $sessionModel->public_id,
            'status' => $sessionModel->status,
            'failure_message' => $sessionModel->failure_message,
            'started_at' => $sessionModel->started_at,
        ]);
    }
}

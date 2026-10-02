<?php

namespace App\Auth\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Auth\Requests\DeviceSyncRequest;
use App\Auth\Requests\DeviceUpdateRequest;
use App\Core\Http\ApiResponse;
use App\Auth\Models\UserDevice;
use App\Auth\Services\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The devices an account is signed in from.
 *
 * Every query here is scoped by the `user_id` behind the bearer token, so the
 * {id} in a path is a row inside the caller's own list or it is a 404 — not a
 * way to reach somebody else's handset.
 *
 * What these routes do not include is deliberate: there is no endpoint that
 * accepts a fingerprint, a face or any other biometric assertion. Biometrics
 * unlock the *device's* stored token, on the device, and the server sees the
 * same Sanctum credential it would have seen after a typed password. `biometric_unlock`
 * is therefore a fact the account displays and can revoke, not a permission.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly DeviceRegistry $devices)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->devices->pruneStaleTokens($user);

        $currentTokenId = $user->currentAccessToken()?->id;

        $devices = UserDevice::where('user_id', $user->id)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (UserDevice $device) => $device->toAccountView(
                $currentTokenId !== null && $device->token_id === $currentTokenId
            ));

        return ApiResponse::success($devices, 'Devices retrieved successfully');
    }

    /**
     * Register or refresh the device making the call.
     *
     * The app uses this after it learns something the sign-in could not have
     * known — the person turned the fingerprint unlock on, or the phone reports
     * a new name. Pointing the row at *this* token is the point: the session
     * answering is the session being described.
     */
    public function sync(DeviceSyncRequest $request): JsonResponse
    {
        $user = $request->user();
        $device = $request->device();

        if ($device === null) {
            return ApiResponse::error(
                'معرّف الجهاز مطلوب لتسجيله',
                'DEVICE_KEY_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $row = $this->devices->record($user, $device, $user->currentAccessToken()?->id);

        return ApiResponse::success(
            $row->toAccountView(true),
            'Device recorded successfully',
            Response::HTTP_CREATED
        );
    }

    public function update(DeviceUpdateRequest $request, int $id): JsonResponse
    {
        $user = $request->user();

        $device = UserDevice::where('user_id', $user->id)->findOrFail($id);
        $device->fill($request->validated())->save();

        $currentTokenId = $user->currentAccessToken()?->id;

        return ApiResponse::success(
            $device->toAccountView($currentTokenId !== null && $device->token_id === $currentTokenId),
            'Device updated successfully'
        );
    }

    /**
     * Remove a device and the session it holds.
     *
     * Both halves happen in one request because half of them is the bug: a row
     * deleted while its token survived would leave the account showing one fewer
     * device and the same number of live credentials.
     *
     * Revoking the device that is asking signs the caller out as soon as its next
     * request is read — the response still succeeds, since what was asked for
     * happened. `current` in the listing is what lets the interface warn first.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $device = UserDevice::where('user_id', $user->id)->findOrFail($id);
        $this->devices->revokeToken($user, $device->token_id);
        $device->delete();

        return ApiResponse::success(null, 'Device revoked successfully');
    }
}

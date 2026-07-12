<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginSecurityService;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthApiController extends Controller
{
    public function __construct(
        private readonly LoginSecurityService $security,
        private readonly OtpService $otp,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $field = filter_var($data['identifier'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::where($field, $data['identifier'])->first();

        if ($user) {
            $this->security->liftExpiredBlock($user);
            $user->refresh();
        }

        if (! $user || $user->isBlocked() || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $this->security->recordFailure($request, $data['identifier'], $user, 'invalid_password');
            }

            return response()->json(['message' => 'Invalid credentials or blocked account.'], 401);
        }

        $this->security->recordSuccess($request, $user);

        if ($this->otp->enabled()) {
            $this->otp->issue($user);

            return response()->json([
                'otp_required' => true,
                'user_id' => $user->id,
                'message' => 'An OTP has been emailed to you.',
            ]);
        }

        return response()->json([
            'otp_required' => false,
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = User::findOrFail($data['user_id']);
        if (! $this->otp->verify($user, $data['code'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        return response()->json(['token' => $user->createToken('api')->plainTextToken]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->permissionSlugs() ? app(\App\Services\Rbac\RbacService::class)->userRoleSlugs($user) : [],
            'permissions' => $user->permissionSlugs(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}

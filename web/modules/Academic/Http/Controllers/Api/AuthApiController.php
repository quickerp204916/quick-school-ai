<?php

namespace Modules\Academic\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Academic\Models\Guardian;
use Modules\Academic\Models\Student;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Institution;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;

/**
 * Token sign-in for the Flutter app. A token works only for the institution it was issued on
 * (ability tenant:{id}); the app calls https://{code}.quickschool.app/api/v1/...
 */
class AuthApiController
{
    public function token(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:100']]);
        $user = User::where('email', strtolower($d['email']))->first();
        $member = $user ? TenantMembership::where('user_id', $user->id)->where('status', 'active')->first() : null;
        if (! $user || ! $member || ! Hash::check($d['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'The email or password is wrong, or this account does not belong to this institution.']);
        }
        $token = $user->createToken($d['device_name'], ['tenant:'.TenantContext::id()], now()->addDays(90));

        return response()->json(['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at?->toIso8601String(), 'role' => $member->role], 201);
    }

    public function revoke(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(CurrentMember $member): JsonResponse
    {
        $profile = Institution::profile();
        $children = collect();
        if (in_array($member->role(), ['parent', 'student'], true)) {
            $children = Student::with(['currentEnrollment.section.level'])->whereIn('id', $member->linkedStudentIds())->get()->map(fn ($s) => Present::student($s));
        }

        return response()->json([
            'user' => ['id' => $member->user()->id, 'name' => $member->user()->name, 'email' => $member->user()->email],
            'role' => $member->role(),
            'permissions' => collect(config('academic.permissions'))->keys()->filter(fn ($p) => $member->can($p))->values(),
            'institution' => ['name' => Institution::tenant()->name, 'code' => Institution::tenant()->code, 'structure' => $profile->structure,
                'terms' => config("academic.structures.{$profile->structure}.terms"), 'attendance_mode' => $profile->attendance_mode],
            'staff' => $member->staff() ? ['id' => $member->staff()->id, 'name' => $member->staff()->name, 'designation' => $member->staff()->designation] : null,
            'students' => $children,
        ]);
    }
}

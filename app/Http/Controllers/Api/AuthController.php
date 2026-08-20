<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController
{
    /** Nhãn mặc định khi client không gửi `device_name`. */
    private const DEFAULT_DEVICE = 'unknown-device';

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $this->issueToken($user, $request->string('device_name')->toString()),
            ],
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email', $data['email'])->first();

        // Một thông báo duy nhất cho cả "email không tồn tại" lẫn "sai mật
        // khẩu". Tách hai trường hợp ra sẽ biến endpoint này thành công cụ dò
        // xem một email có đăng ký hay chưa.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Email hoặc mật khẩu không đúng.',
            ]);
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $this->issueToken($user, $request->string('device_name')->toString()),
            ],
        ]);
    }

    public function logout(): Response
    {
        $token = auth()->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'data' => ['user' => new UserResource(auth()->user())],
        ]);
    }

    /**
     * Luôn trả 204, kể cả khi email không tồn tại.
     *
     * Phân biệt hai trường hợp ở đây cũng rò rỉ đúng thứ mà `login` đã cẩn thận
     * không tiết lộ.
     */
    public function forgotPassword(ForgotPasswordRequest $request): Response
    {
        Password::sendResetLink($request->validated());

        return response()->noContent();
    }

    public function resetPassword(ResetPasswordRequest $request): Response
    {
        $status = Password::reset(
            $request->validated(),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Mật khẩu đổi thì mọi phiên cũ phải chết theo. Nếu ai đó đặt
                // lại mật khẩu vì nghi bị chiếm tài khoản, để token cũ sống
                // tiếp là làm hỏng đúng mục đích của thao tác này.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Mã đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.',
            ]);
        }

        return response()->noContent();
    }

    private function issueToken(User $user, string $deviceName): string
    {
        $name = trim($deviceName) !== '' ? trim($deviceName) : self::DEFAULT_DEVICE;

        return $user->createToken($name)->plainTextToken;
    }
}

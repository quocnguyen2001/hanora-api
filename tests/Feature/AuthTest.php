<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

describe('register', function (): void {
    it('tạo tài khoản và trả token dùng được ngay', function (): void {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Quốc',
            'email' => 'quoc@hanora.test',
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
            'device_name' => 'iPhone của Quốc',
        ])->assertCreated();

        $token = $response->json('data.token');

        expect($token)->toBeString()->not->toBeEmpty();
        $this->assertDatabaseHas('users', ['email' => 'quoc@hanora.test']);

        // Token phải dùng được ngay, không cần đăng nhập thêm bước nữa.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'quoc@hanora.test');
    });

    it('không trả mật khẩu trong response', function (): void {
        $this->postJson('/api/auth/register', [
            'name' => 'Quốc',
            'email' => 'quoc@hanora.test',
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
        ])->assertCreated()
            ->assertJsonMissingPath('data.user.password');
    });

    it('từ chối email đã tồn tại', function (): void {
        User::factory()->create(['email' => 'quoc@hanora.test']);

        $this->postJson('/api/auth/register', [
            'name' => 'Quốc',
            'email' => 'quoc@hanora.test',
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('từ chối mật khẩu ngắn hơn 8 ký tự', function (): void {
        $this->postJson('/api/auth/register', [
            'name' => 'Quốc',
            'email' => 'quoc@hanora.test',
            'password' => 'ngan',
            'password_confirmation' => 'ngan',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    });

    it('cắt device_name dài quá 64 ký tự', function (): void {
        $this->postJson('/api/auth/register', [
            'name' => 'Quốc',
            'email' => 'quoc@hanora.test',
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
            'device_name' => str_repeat('a', 65),
        ])->assertStatus(422)->assertJsonValidationErrors('device_name');
    });
});

describe('login', function (): void {
    beforeEach(function (): void {
        User::factory()->create([
            'email' => 'quoc@hanora.test',
            'password' => 'matkhau123',
        ]);
    });

    it('trả user và token khi đúng thông tin', function (): void {
        $this->postJson('/api/auth/login', [
            'email' => 'quoc@hanora.test',
            'password' => 'matkhau123',
            'device_name' => 'Pixel',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'quoc@hanora.test')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);
    });

    it('ghi device_name vào tên token', function (): void {
        $this->postJson('/api/auth/login', [
            'email' => 'quoc@hanora.test',
            'password' => 'matkhau123',
            'device_name' => 'Pixel',
        ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Pixel']);
    });

    it('dùng nhãn mặc định khi không gửi device_name', function (): void {
        $this->postJson('/api/auth/login', [
            'email' => 'quoc@hanora.test',
            'password' => 'matkhau123',
        ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'unknown-device']);
    });

    it('không tiết lộ email có tồn tại hay không', function (): void {
        // Hai trường hợp khác nhau phải cho ra thông báo giống hệt nhau, nếu
        // không endpoint này thành công cụ dò danh sách email đã đăng ký.
        $saiMatKhau = $this->postJson('/api/auth/login', [
            'email' => 'quoc@hanora.test',
            'password' => 'sai-mat-khau',
        ])->assertStatus(422);

        $khongTonTai = $this->postJson('/api/auth/login', [
            'email' => 'khong-ton-tai@hanora.test',
            'password' => 'sai-mat-khau',
        ])->assertStatus(422);

        expect($saiMatKhau->json('errors'))->toBe($khongTonTai->json('errors'))
            ->and($saiMatKhau->json('errors.email.0'))->toBe('Email hoặc mật khẩu không đúng.');
    });
});

describe('me và logout', function (): void {
    it('trả 401 khi không có token', function (): void {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    });

    it('trả 401 với token rác', function (): void {
        $this->withHeader('Authorization', 'Bearer khong-phai-token')
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    });

    it('vô hiệu hóa token sau khi logout', function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Guard sanctum cache user đã phân giải trong cùng một app instance, mà
        // test thì tái dùng instance đó giữa hai request. Production không có
        // chuyện này — mỗi request là một process mới — nên phải ép quên guard,
        // nếu không test sẽ xanh giả.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    });

    it('chỉ xóa token của thiết bị đang đăng xuất', function (): void {
        // Đăng xuất trên điện thoại không được đá luôn phiên trên máy tính.
        $user = User::factory()->create();
        $dienThoai = $user->createToken('phone')->plainTextToken;
        $mayTinh = $user->createToken('laptop')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$dienThoai}")
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'laptop']);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$mayTinh}")
            ->getJson('/api/auth/me')
            ->assertOk();
    });
});

describe('token hết hạn', function (): void {
    it('chốt thời hạn token ở 90 ngày theo D11', function (): void {
        expect(config('sanctum.expiration'))->toBe(60 * 24 * 90);
    });

    it('trả 401 với token đã quá hạn', function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // Đẩy ngày tạo lùi quá mốc 90 ngày.
        PersonalAccessToken::query()->update([
            'created_at' => now()->subDays(91),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    });
});

describe('đặt lại mật khẩu', function (): void {
    it('gửi mail kèm link trỏ về frontend', function (): void {
        Notification::fake();
        config(['app.frontend_url' => 'http://localhost:5173']);
        $user = User::factory()->create(['email' => 'quoc@hanora.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'quoc@hanora.test'])
            ->assertNoContent();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            // Link phải trỏ về màn của FE. Notification mặc định của Laravel
            // dựng URL từ route web `password.reset`, mà repo này không có.
            return str_starts_with((string) $url, 'http://localhost:5173/reset-password?');
        });
    });

    it('trả 204 kể cả khi email không tồn tại', function (): void {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'khong-ai@hanora.test'])
            ->assertNoContent();

        Notification::assertNothingSent();
    });

    it('đổi được mật khẩu bằng token hợp lệ', function (): void {
        Notification::fake();
        $user = User::factory()->create(['email' => 'quoc@hanora.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'quoc@hanora.test']);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'quoc@hanora.test',
            'password' => 'matkhaumoi123',
            'password_confirmation' => 'matkhaumoi123',
        ])->assertNoContent();

        $this->postJson('/api/auth/login', [
            'email' => 'quoc@hanora.test',
            'password' => 'matkhaumoi123',
        ])->assertOk();
    });

    it('thu hồi mọi token cũ sau khi đổi mật khẩu', function (): void {
        // Nếu ai đó đặt lại mật khẩu vì nghi bị chiếm tài khoản, để token cũ
        // sống tiếp là làm hỏng đúng mục đích của thao tác này.
        Notification::fake();
        $user = User::factory()->create(['email' => 'quoc@hanora.test']);
        $tokenCu = $user->createToken('phone')->plainTextToken;

        $this->postJson('/api/auth/forgot-password', ['email' => 'quoc@hanora.test']);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'quoc@hanora.test',
            'password' => 'matkhaumoi123',
            'password_confirmation' => 'matkhaumoi123',
        ])->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$tokenCu}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    });

    it('từ chối token đặt lại không hợp lệ', function (): void {
        User::factory()->create(['email' => 'quoc@hanora.test']);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'token-bia-ra',
            'email' => 'quoc@hanora.test',
            'password' => 'matkhaumoi123',
            'password_confirmation' => 'matkhaumoi123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    });
});

describe('throttle', function (): void {
    it('chỉ trừ một lượt cho mỗi request', function (): void {
        // Hồi quy: throttle inline trên route dùng chung khóa cache với
        // `throttle:60,1` của nhóm `api`, khiến mỗi request trừ hai lượt và
        // trần thực tế chỉ còn một nửa. Limiter có tên sửa việc đó.
        User::factory()->create(['email' => 'quoc@hanora.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'quoc@hanora.test',
            'password' => 'sai-mat-khau',
        ])->assertStatus(422);

        expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(5)
            ->and((int) $response->headers->get('X-RateLimit-Remaining'))->toBe(4);
    });

    it('chặn đăng nhập sau 5 lần trong một phút', function (): void {
        User::factory()->create(['email' => 'quoc@hanora.test']);

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/auth/login', [
                'email' => 'quoc@hanora.test',
                'password' => 'sai-mat-khau',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'quoc@hanora.test',
            'password' => 'sai-mat-khau',
        ])->assertStatus(429);
    });

    it('chặn đăng ký sau 3 lần trong một giờ', function (): void {
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/auth/register', [
                'name' => 'Quốc',
                'email' => "quoc{$i}@hanora.test",
                'password' => 'matkhau123',
                'password_confirmation' => 'matkhau123',
            ])->assertCreated();
        }

        $this->postJson('/api/auth/register', [
            'name' => 'Quốc',
            'email' => 'quoc4@hanora.test',
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
        ])->assertStatus(429);
    });
});

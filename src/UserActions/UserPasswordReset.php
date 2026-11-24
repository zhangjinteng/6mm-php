<?php
namespace SixMm\Shared\UserActions;

use Illuminate\Support\Str;

final class UserPasswordReset
{
    /** Call inside the host's authorized/audited transaction. Plaintext is returned only to the caller. */
    public function reset(callable $persistHash): string
    {
        $password = Str::random(16);
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($hash === false || !$persistHash($hash)) throw new \RuntimeException('重置密码失败');
        return $password;
    }
}

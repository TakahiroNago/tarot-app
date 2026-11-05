<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:promote-admin {email}')]
#[Description('Promote an active user to administrator')]
class PromoteUserToAdmin extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('そのメールアドレスのユーザーは見つかりません。');

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->error('利用停止中のユーザーはAdminに昇格できません。');

            return self::FAILURE;
        }

        $user->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->info("{$user->email} をAdminに設定しました。");

        return self::SUCCESS;
    }
}

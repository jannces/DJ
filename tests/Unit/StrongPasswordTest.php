<?php

namespace Tests\Unit;

use App\Rules\StrongPassword;
use PHPUnit\Framework\TestCase;

class StrongPasswordTest extends TestCase
{
    private function fails(string $password): bool
    {
        $failed = false;
        (new StrongPassword)->validate('password', $password, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_rejects_weak_passwords(): void
    {
        $this->assertTrue($this->fails('short'));
        $this->assertTrue($this->fails('alllowercase123!'));
        $this->assertTrue($this->fails('NoNumbersHere!!!'));
        $this->assertTrue($this->fails('NoSymbols12345'));
    }

    public function test_accepts_a_strong_password(): void
    {
        $this->assertFalse($this->fails('Str0ng&Secure!Pass'));
    }
}

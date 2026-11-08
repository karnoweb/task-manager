<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\App;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class DomainErrorTranslationTest extends PackageTestCase
{
    public function test_exposes_stable_code_and_locale_specific_message(): void
    {
        $error = new DomainError('not_found');

        self::assertSame('not_found', $error->errorCode);
        self::assertSame(__('task-manager::errors.not_found'), $error->getMessage());
    }

    public function test_fa_locale_uses_persian_strings(): void
    {
        App::setLocale('fa');
        $error = new DomainError('version_conflict');

        self::assertSame('version_conflict', $error->errorCode);
        self::assertSame(__('task-manager::errors.version_conflict'), $error->getMessage());
        self::assertStringContainsString('نسخه', $error->getMessage());
    }
}

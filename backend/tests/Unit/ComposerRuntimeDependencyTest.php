<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ComposerRuntimeDependencyTest extends TestCase
{
    public function test_telescope_is_available_in_production_dependency_installs(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('laravel/telescope', $composer['require']);
        $this->assertArrayNotHasKey('laravel/telescope', $composer['require-dev']);
    }
}

<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_and_spa_routes_serve_built_frontend_when_present(): void
    {
        $indexPath = public_path('app/index.html');
        $previousIndex = File::exists($indexPath) ? File::get($indexPath) : null;

        File::ensureDirectoryExists(dirname($indexPath));
        File::put($indexPath, '<html><body><div id="root">Built frontend</div><script src="/app/assets/app.js"></script></body></html>');

        try {
            $this->get('/')
                ->assertOk()
                ->assertSee('Built frontend');

            $this->get('/login')
                ->assertOk()
                ->assertSee('/app/assets/app.js', false);
        } finally {
            if ($previousIndex === null) {
                File::delete($indexPath);
            } else {
                File::put($indexPath, $previousIndex);
            }
        }
    }

    public function test_missing_frontend_assets_return_not_found(): void
    {
        $indexPath = public_path('app/index.html');
        $previousIndex = File::exists($indexPath) ? File::get($indexPath) : null;

        File::ensureDirectoryExists(dirname($indexPath));
        File::put($indexPath, '<html><body><div id="root">Built frontend</div></body></html>');

        try {
            $this->get('/app/assets/missing.js')->assertNotFound();
        } finally {
            if ($previousIndex === null) {
                File::delete($indexPath);
            } else {
                File::put($indexPath, $previousIndex);
            }
        }
    }
}

<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The app runs both hosted (Render, https) and on a school computer reached
 * over plain http on the local network.
 */
class LocalInstallTest extends TestCase
{
    private function bootAs(string $env, string $url): string
    {
        $this->app['env'] = $env;
        config(['app.url' => $url]);
        URL::forceScheme(null);
        (new AppServiceProvider($this->app))->boot();

        return url('/login');
    }

    public function test_a_school_computer_on_http_keeps_http_links_in_production(): void
    {
        $this->assertStringStartsWith('http://', $this->bootAs('production', 'http://192.168.1.20:8000'));
    }

    public function test_a_hosted_copy_on_https_always_uses_https_links(): void
    {
        $this->assertStringStartsWith('https://', $this->bootAs('production', 'https://djihs.onrender.com'));
    }

    protected function tearDown(): void
    {
        URL::forceScheme(null);
        parent::tearDown();
    }
}

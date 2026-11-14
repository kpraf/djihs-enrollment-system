<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Encryption\Encrypter;
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

    /** @param  array<string, ?string>  $env */
    private function appConfigWith(array $env): array
    {
        foreach ($env as $k => $v) {
            $v === null ? putenv($k) : putenv("{$k}={$v}");
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
            } else {
                $_ENV[$k] = $_SERVER[$k] = $v;
            }
        }

        return require base_path('config/app.php');
    }

    public function test_a_key_generated_by_render_becomes_a_working_key(): void
    {
        $original = getenv('APP_KEY') ?: null;
        $renderValue = base64_encode(random_bytes(32));
        $key = $this->appConfigWith(['APP_KEY' => $renderValue])['key'];
        $again = $this->appConfigWith(['APP_KEY' => $renderValue])['key'];
        $this->appConfigWith(['APP_KEY' => $original]);

        $this->assertSame($key, $again, 'The same Render value must give the same key on every deploy.');
        $encrypter = new Encrypter(base64_decode(substr($key, 7)), 'AES-256-CBC');
        $this->assertSame('ok', $encrypter->decrypt($encrypter->encrypt('ok')));

        $generated = 'base64:'.base64_encode(random_bytes(32));
        $this->assertSame($generated, $this->appConfigWith(['APP_KEY' => $generated])['key'], 'Normal keys are used as they are.');
        $this->appConfigWith(['APP_KEY' => $original]);
    }

    public function test_the_site_address_comes_from_render_when_app_url_is_not_set(): void
    {
        $original = getenv('APP_URL') ?: null;
        $url = $this->appConfigWith(['APP_URL' => null, 'RENDER_EXTERNAL_URL' => 'https://djihs-enrollment.onrender.com'])['url'];
        $this->appConfigWith(['APP_URL' => $original, 'RENDER_EXTERNAL_URL' => null]);

        $this->assertSame('https://djihs-enrollment.onrender.com', $url);
    }

    protected function tearDown(): void
    {
        URL::forceScheme(null);
        parent::tearDown();
    }
}

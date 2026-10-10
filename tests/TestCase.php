<?php

namespace OnlineEfd\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use OnlineEfd\Concerns\HasEfdProfile;
use OnlineEfd\EfdServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [EfdServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_shops', function ($table) {
            $table->id();
            $table->string('name');
        });
    }

    protected function makeShop(): TestShop
    {
        return TestShop::create(['name' => 'Test Shop']);
    }

    /** Builds a throw-away self-signed .pfx so signing can be tested without real TRA files. */
    protected function makePfx(string $password = 'secret'): string
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        // On Windows PHP often can't find openssl.cnf on its own.
        $candidates = [
            getenv('OPENSSL_CONF') ?: '',
            dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
        ];
        foreach ($candidates as $conf) {
            if ($conf !== '' && is_file($conf)) {
                $config['config'] = $conf;
                break;
            }
        }

        $key = openssl_pkey_new($config);
        $csr = openssl_csr_new(['commonName' => 'EFD TEST'], $key, $config);
        $cert = openssl_csr_sign($csr, null, $key, 30, $config);
        openssl_pkcs12_export($cert, $pfx, $key, $password);

        return $pfx;
    }
}

class TestShop extends Model
{
    use HasEfdProfile;

    protected $table = 'test_shops';

    public $timestamps = false;

    protected $guarded = [];
}

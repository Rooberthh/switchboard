<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\StandardWebhooksVector as Vector;

beforeEach(function () {
    File::deleteDirectory(app_path('Webhooks'));
});

afterEach(function () {
    File::deleteDirectory(app_path('Webhooks'));

    Carbon::setTestNow();
});

it('generates a provider class under App\\Webhooks', function () {
    $this->artisan('make:webhook-provider', ['name' => 'Acme'])->assertSuccessful();

    $source = File::get(app_path('Webhooks/AcmeProvider.php'));

    expect($source)
        ->toContain('namespace App\\Webhooks;')
        ->toContain('final class AcmeProvider extends WebhookProvider')
        ->toContain("return 'acme';")
        ->toContain('new StandardWebhooks($this->secret())')
        ->toContain('provider: static::name()')
        ->toContain('public array $handlers = [');
});

it('prints the steps a generator cannot take for you', function () {
    $this->artisan('make:webhook-provider', ['name' => 'Acme'])
        ->expectsOutputToContain('Switchboard::provider(\\App\\Webhooks\\AcmeProvider::class);')
        ->expectsOutputToContain('POST /webhooks/acme')
        ->expectsOutputToContain("'acme' => ['secret' => env('ACME_WEBHOOK_SECRET')]")
        ->assertSuccessful();
});

it('does not double the suffix when the name already carries it', function () {
    $this->artisan('make:webhook-provider', ['name' => 'AcmeProvider'])->assertSuccessful();

    expect(File::exists(app_path('Webhooks/AcmeProvider.php')))->toBeTrue()
        ->and(File::exists(app_path('Webhooks/AcmeProviderProvider.php')))->toBeFalse();
});

it('names a multi-word provider in kebab case', function () {
    $this->artisan('make:webhook-provider', ['name' => 'AcmePayments'])
        ->expectsOutputToContain('POST /webhooks/acme-payments')
        ->expectsOutputToContain("env('ACME_PAYMENTS_WEBHOOK_SECRET')")
        ->assertSuccessful();

    expect(File::get(app_path('Webhooks/AcmePaymentsProvider.php')))->toContain("return 'acme-payments';");
});

it('leaves an existing provider untouched', function () {
    $this->artisan('make:webhook-provider', ['name' => 'Acme'])->assertSuccessful();

    File::put(app_path('Webhooks/AcmeProvider.php'), '<?php // hand edited');

    // Like Laravel's own make commands: an error, not an overwrite.
    $this->artisan('make:webhook-provider', ['name' => 'Acme'])
        ->expectsOutputToContain('already exists')
        ->doesntExpectOutputToContain('Switchboard::provider(');

    expect(File::get(app_path('Webhooks/AcmeProvider.php')))->toBe('<?php // hand edited');
});

it('generates a provider that receives webhooks once its secret is set', function () {
    Queue::fake();

    $this->artisan('make:webhook-provider', ['name' => 'Acme'])->assertSuccessful();

    require_once app_path('Webhooks/AcmeProvider.php');

    config(['switchboard.providers.acme.secret' => Vector::SECRET]);
    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP));

    Switchboard::provider('App\\Webhooks\\AcmeProvider');

    $body = '{"type":"invoice.paid","timestamp":"2021-02-26T00:00:00Z","data":{"amount":1000}}';

    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach (Vector::headers($body) as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $this->call('POST', 'webhooks/acme', server: $server, content: $body)->assertNoContent();

    $message = InboxMessage::query()->sole();

    expect($message->provider)->toBe('acme')
        ->and($message->event_id)->toBe(Vector::ID)
        ->and($message->event_type)->toBe('invoice.paid')
        ->and($message->data)->toBe(['amount' => 1000]);
});

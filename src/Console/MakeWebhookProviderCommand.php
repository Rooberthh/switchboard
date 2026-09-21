<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generate a provider class that works as soon as its secret is set, then say
 * the two things a generator cannot do for you: register it, and configure
 * the secret.
 *
 * @internal
 */
#[AsCommand(name: 'make:webhook-provider')]
final class MakeWebhookProviderCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:webhook-provider';

    /** @var string */
    protected $description = 'Create a new Switchboard webhook provider class';

    /** @var string */
    protected $type = 'Webhook provider';

    public function handle(): ?bool
    {
        if (parent::handle() === false) {
            return false;
        }

        $class = $this->qualifyClass($this->getNameInput());
        $provider = $this->providerName();

        $this->components->twoColumnDetail('Register it in a service provider\'s boot()', "Switchboard::provider(\\{$class}::class);");
        $this->components->twoColumnDetail('Its endpoint', 'POST /' . trim((string) config('switchboard.inbox.path', 'webhooks'), '/') . "/{$provider}");
        $this->components->twoColumnDetail('Set its secret in config/switchboard.php', "'providers' => ['{$provider}' => ['secret' => env('" . Str::upper(str_replace("-", "_", $provider)) . "_WEBHOOK_SECRET')]]");

        return null;
    }

    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/webhook-provider.stub';
    }

    /**
     * @param string $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Webhooks';
    }

    /**
     * "Acme" and "AcmeProvider" both make AcmeProvider.
     */
    protected function getNameInput(): string
    {
        $name = parent::getNameInput();

        return Str::endsWith($name, 'Provider') ? $name : $name . 'Provider';
    }

    /**
     * @param string $name
     */
    protected function buildClass($name): string
    {
        return str_replace('{{ provider }}', $this->providerName(), parent::buildClass($name));
    }

    /**
     * The provider's name: the class name without its suffix, kebab-cased, so
     * AcmePayments is served at /webhooks/acme-payments.
     */
    private function providerName(): string
    {
        return Str::kebab(Str::beforeLast(class_basename($this->getNameInput()), 'Provider'));
    }

    /**
     * @return list<array{0: string, 1: string|null, 2: int, 3: string}>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if it already exists'],
        ];
    }
}

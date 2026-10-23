<?php

declare(strict_types=1);

use Acelle\Plugin\Smtp2go\Smtp2go\Client;
use Acelle\Plugin\Smtp2go\Smtp2go\Error;
use Acelle\Plugin\Smtp2go\Smtp2goDriver;
use App\Model\SendingServer;
use App\SendingServers\Identities\IdentityKind;
use Tests\TestCase;

uses(TestCase::class);

/** A failed SMTP2GO read used to read as "no domains" / "not found"; it now propagates. */
function smtp2goDriverAnswering(\Closure $post): Smtp2goDriver
{
    $driver = new Smtp2goDriver(new SendingServer(['type' => Smtp2goDriver::TYPE, 'name' => 'SMTP2GO fixture']));
    $client = new class($post) extends Client {
        public function __construct(private readonly \Closure $answer)
        {
        }

        public function post(string $path, array $body = []): array
        {
            return ($this->answer)($path, $body);
        }
    };
    (new ReflectionProperty($driver, 'client'))->setValue($driver, $client);

    return $driver;
}

it('fails the sync when the domain list cannot be read', function () {
    smtp2goDriverAnswering(fn () => throw new Error('SMTP2GO 500', 500))->fetchIdentities();
})->throws(Error::class, 'SMTP2GO 500');

it('lets a failed domain lookup through', function () {
    smtp2goDriverAnswering(fn () => throw new Error('SMTP2GO 401', 401))->fetchIdentity(IdentityKind::DOMAIN, 'x.example');
})->throws(Error::class, 'SMTP2GO 401');

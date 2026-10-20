<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\TransportInterface;

/*
 * Real mail: the Mailgun transport is configured, and the fictional addresses of the demo world never reach it.
 */

it('sends through Mailgun when told to', function () {
    config(['mail.default' => 'mailgun', 'services.mailgun' => ['domain' => 'mg.example.test', 'secret' => 'key-test', 'endpoint' => 'api.mailgun.net', 'scheme' => 'https']]);

    $transport = Mail::mailer('mailgun')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(TransportInterface::class)
        ->and((string) $transport)->toStartWith('mailgun+https://api.mailgun.net')->toContain('domain=mg.example.test');
});

it('never sends a letter to the demo world, and keeps the real recipients of a mixed one', function () {
    config(['mail.default' => 'array']);
    Event::fake([MessageSent::class]);
    $demo = 'ion.botnaru@'.config('demo.email_domain');

    Mail::raw('Only a fictional recipient', fn ($m) => $m->to($demo)->subject('Demo'));
    Mail::raw('A real one too', fn ($m) => $m->to([$demo, 'someone@example.org'])->cc(strtoupper($demo))->subject('Mixed'));

    $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1)
        ->and(array_map(fn ($a) => $a->getAddress(), $sent[0]->getOriginalMessage()->getTo()))->toBe(['someone@example.org'])
        ->and($sent[0]->getOriginalMessage()->getCc())->toBe([]);
});

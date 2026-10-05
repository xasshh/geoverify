<?php

declare(strict_types=1);

use App\Domain\Party\Actions\RequestSignInCode;
use App\Domain\Sms\LogSms;
use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsUndelivered;
use App\Domain\Sms\TermiiSms;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Text messages: the one way a code reaches a phone.
 *
 * What is worth proving is the shape Termii is sent, that a refusal reaches
 * the person as an error rather than as a text that never comes, and that a
 * live code never lands in the log anywhere but a developer machine.
 */
beforeEach(function () {
    config()->set('services.sms.driver', 'termii');
    config()->set('services.termii.base_url', 'https://termii.example');
    config()->set('services.termii.api_key', 'tl_test_key');
    config()->set('services.termii.sender_id', 'GeoVerify');
    config()->set('services.termii.channel', 'dnd');
});

it('sends a sign-in code through Termii on the dnd channel', function () {
    Http::fake(['termii.example/*' => Http::response(['message_id' => '9122821270554876574', 'message' => 'Successfully Sent'])]);

    app(RequestSignInCode::class)('0803 666 0006');

    Http::assertSentCount(1);
    Http::assertSent(function (SentRequest $request): bool {
        return $request->url() === 'https://termii.example/api/sms/send'
            && $request['api_key'] === 'tl_test_key'
            && $request['to'] === '2348036660006'
            && $request['from'] === 'GeoVerify'
            && $request['channel'] === 'dnd'
            && $request['type'] === 'plain'
            && preg_match('/^GeoVerify: \d{6} is your sign-in code\./', $request['sms']) === 1;
    });
});

it('tells the person when Termii refuses, and logs the reason without the code', function () {
    Http::fake(['termii.example/*' => Http::response(['message' => 'Insufficient balance'], 400)]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $entry) use (&$logged): void {
        $logged[] = $entry;
    });

    expect(fn () => app(SmsGateway::class)->send('+2348036660006', 'GeoVerify: 482913 is your sign-in code.'))
        ->toThrow(SmsUndelivered::class, 'We cannot send a code right now. Please try again shortly.');

    expect($logged)->toHaveCount(1)
        ->and($logged[0]->level)->toBe('error')
        ->and($logged[0]->context)->toBe(['phone' => '...0006', 'status' => '400', 'reason' => 'Insufficient balance'])
        ->and($logged[0]->message.json_encode($logged[0]->context))->not->toContain('482913');
});

it('treats a reply without a message id as not sent', function () {
    Http::fake(['termii.example/*' => Http::response(['message' => 'Sender ID not approved'])]);

    expect(fn () => app(SmsGateway::class)->send('+2348036660006', 'GeoVerify: 482913 is your sign-in code.'))
        ->toThrow(SmsUndelivered::class);
});

it('shows the sign-in screen an error, not a config message, when the gateway is down', function () {
    Http::fake(['termii.example/*' => Http::response(['message' => 'Service unavailable'], 503)]);

    $this->from('/portal/sign-in')
        ->post('/portal/sign-in', ['phone' => '08036660006'])
        ->assertRedirect('/portal/sign-in')
        ->assertSessionHasErrors(['phone' => 'We cannot send a code right now. Please try again shortly.']);
});

it('refuses to start without its keys, saying why only to the log', function () {
    config()->set('services.termii.api_key', '');
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $entry) use (&$logged): void {
        $logged[] = $entry;
    });

    expect(fn () => app(SmsGateway::class))->toThrow(SmsUndelivered::class, 'We cannot send a code right now.');

    expect($logged)->toHaveCount(1)
        ->and($logged[0]->level)->toBe('critical')
        ->and($logged[0]->message)->toBe('TERMII_API_KEY and TERMII_SENDER_ID must both be set to use the Termii gateway.');
});

it('only writes codes to the log on a developer machine', function () {
    config()->set('services.sms.driver', 'log');

    expect(app(SmsGateway::class))->toBeInstanceOf(LogSms::class)
        ->and(fn () => new LogSms(false))->toThrow(SmsUndelivered::class);

    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(SmsGateway::class))->toThrow(SmsUndelivered::class);
});

it('is the Termii driver when configured so', function () {
    expect(app(SmsGateway::class))->toBeInstanceOf(TermiiSms::class);
});

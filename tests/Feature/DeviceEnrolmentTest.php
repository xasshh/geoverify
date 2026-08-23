<?php

declare(strict_types=1);

use App\Domain\Field\Actions\RegisterDevice;
use App\Domain\Field\Models\Device;
use App\Enums\Role;
use App\Models\User;

it('enrols a handset and issues it a scoped token', function () {
    $officer = person(Role::Officer);

    $result = app(RegisterDevice::class)->register(
        $officer,
        'handset-a1b2c3',
        model: 'Tecno Spark 10',
        osVersion: 'Android 14',
        appVersion: '1.0.0',
        dualFrequencyGnss: true,
    );

    expect($result['device']->device_id)->toBe('handset-a1b2c3')
        ->and($result['device']->user_id)->toBe($officer->id)
        ->and($result['device']->gnss_dual_frequency)->toBeTrue()
        // Unverified until a real attestation exists. A progressive web app cannot
        // produce one, and recording that honestly is the point.
        ->and($result['device']->integrity_verdict)->toBe(Device::INTEGRITY_UNVERIFIED)
        ->and($result['token']->accessToken->abilities)->toBe([RegisterDevice::ABILITY_FIELD]);
});

it('replaces the previous token when a handset re-enrols, leaving no stale credential', function () {
    $officer = person(Role::Officer);
    $register = app(RegisterDevice::class);

    $register->register($officer, 'handset-a1b2c3');
    $register->register($officer, 'handset-a1b2c3');

    expect($officer->tokens()->count())->toBe(1)
        ->and(Device::query()->count())->toBe(1);
});

it('refuses a handset already enrolled to another officer', function () {
    $first = person(Role::Officer, 'A. Bello');
    $second = person(Role::Officer, 'C. Okafor');
    $register = app(RegisterDevice::class);

    $register->register($first, 'shared-handset');

    // A shared phone or a cloned identifier. Both need a person to look at them.
    expect(fn () => $register->register($second, 'shared-handset'))
        ->toThrow(RuntimeException::class, 'already enrolled to another officer');
});

it('refuses to enrol a device to anyone but an active field officer', function () {
    $register = app(RegisterDevice::class);

    expect(fn () => $register->register(person(Role::Supervisor), 'handset-x'))
        ->toThrow(RuntimeException::class);

    expect(fn () => $register->register(
        person(Role::Officer, 'Suspended', User::STATUS_SUSPENDED),
        'handset-y',
    ))->toThrow(RuntimeException::class);
});

it('revokes a lost handset without touching the officer account', function () {
    $officer = person(Role::Officer);
    $supervisor = person(Role::Supervisor);
    $register = app(RegisterDevice::class);

    $device = $register->register($officer, 'lost-handset')['device'];
    $revoked = $register->revoke($device, $supervisor, 'Reported lost at Wuse market');

    expect($revoked->status)->toBe(Device::STATUS_REVOKED)
        ->and($revoked->revoked_reason)->toBe('Reported lost at Wuse market')
        ->and($revoked->isUsable())->toBeFalse()
        // The token is gone, so the handset is cut off.
        ->and($officer->tokens()->count())->toBe(0)
        // The person is untouched and still able to work another handset.
        ->and($officer->fresh()?->isActive())->toBeTrue()
        // The device row survives: it is part of the record of what captured what.
        ->and(Device::query()->count())->toBe(1);
});

it('refuses to let an officer revoke their own device', function () {
    $officer = person(Role::Officer);
    $register = app(RegisterDevice::class);
    $device = $register->register($officer, 'handset-z')['device'];

    expect(fn () => $register->revoke($device, $officer, 'I would rather not be tracked'))
        ->toThrow(RuntimeException::class, 'Only a supervisor can revoke a device.');
});

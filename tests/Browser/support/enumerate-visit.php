<?php

declare(strict_types=1);

/*
 * An Enumerate site visit given to suleiman@geoverify.test, for the offline spec.
 * Not bello: the capture and pack specs sign bello in several times a minute,
 * and the login limit is per email, so one more pushed the next spec to a 429.
 * Run through tinker against the development database; prints the visit id
 * as JSON on its last line. A fresh requester each run, so runs never share.
 *
 * The wallet is credited through RecordWalletFunding directly because there is
 * no payment provider in development. Application code reaches it only from
 * the signed webhook; this is a fixture.
 */

use App\Domain\Enumerate\Actions\DecideDeskCheck;
use App\Domain\Enumerate\Actions\ManageEnumerateVisits;
use App\Domain\Enumerate\Actions\ManageRequesterWallet;
use App\Domain\Enumerate\Actions\PlaceEnumerateRequest;
use App\Domain\Enumerate\Actions\RecordWalletFunding;
use App\Domain\Enumerate\Actions\RunRegistryChecks;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\WalletFunding;
use App\Domain\Party\Actions\ManagePortalCredentials;
use App\Domain\Party\Actions\NormalisePhone;
use App\Models\User;

$stamp = substr((string) hrtime(true), -7);
$account = app(ManagePortalCredentials::class)->registerBuyer((new NormalisePhone)('0809'.$stamp), 'Browser Requester', null);
$wallet = app(ManageRequesterWallet::class)->walletFor($account);

$funding = WalletFunding::query()->create([
    'reference' => 'FND-'.now()->format('Ymd').'-B'.substr($stamp, -5),
    'wallet_id' => $wallet->id,
    'portal_account_id' => $account->id,
    'amount_minor' => 5_000_00,
    'channel' => 'card',
]);
app(RecordWalletFunding::class)($funding, $funding->reference, 5_000_00);

$supervisor = User::query()->where('email', 'supervisor@geoverify.test')->firstOrFail();
$officer = User::query()->where('email', 'suleiman@geoverify.test')->firstOrFail();

$request = app(PlaceEnumerateRequest::class)($account, ['name' => 'SAHEL SOLAR SYSTEMS LIMITED', 'rcNumber' => '1739021', 'companyType' => 'COMPANY'], Tier::Location);
app(RunRegistryChecks::class)($request);
app(DecideDeskCheck::class)($request->refresh(), $supervisor, true, null);
$visit = app(ManageEnumerateVisits::class)->assign($request->refresh(), $officer, $supervisor, 9.0421, 7.4912);

echo json_encode(['visitId' => $visit->id, 'reference' => $request->reference]).PHP_EOL;

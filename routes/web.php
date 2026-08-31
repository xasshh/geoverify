<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\CheckSpatialStack;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CampaignBuildController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\EscalationController;
use App\Http\Controllers\Admin\MandateController;
use App\Http\Controllers\Admin\PeopleController;
use App\Http\Controllers\Client\CampaignController as ClientCampaignController;
use App\Http\Controllers\Client\SignInController as ClientSignInController;
use App\Http\Controllers\Console\AssignmentController;
use App\Http\Controllers\Console\ClaimReviewController;
use App\Http\Controllers\Console\CorrectionReviewController;
use App\Http\Controllers\Console\CoverageController;
use App\Http\Controllers\Console\ExportController;
use App\Http\Controllers\Console\LiveOperationsController;
use App\Http\Controllers\Console\ReviewController;
use App\Http\Controllers\Field\AssignmentBoardController;
use App\Http\Controllers\Field\CaptureController;
use App\Http\Controllers\Field\CaptureScreenController;
use App\Http\Controllers\Field\MapPackController;
use App\Http\Controllers\Field\SyncController;
use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\Portal\ClaimController;
use App\Http\Controllers\Portal\CorrectionController as PortalCorrectionController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\ListingController;
use App\Http\Controllers\Portal\RegisterBusinessController;
use App\Http\Controllers\Portal\SignInController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn (CheckSpatialStack $check) => Inertia::render('Health', $check()))
    ->name('health');

// The design system gallery. Never routed in production: it exists so the
// primitives can be reviewed in every state before any feature screen uses them.
if (app()->isLocal()) {
    Route::get('/design', fn () => Inertia::render('Design'))->name('design');
}

/*
| The supervisor console. Assigning ground, and seeing who holds what.
*/
Route::middleware(['auth', 'supervises'])->prefix('console')->name('console.')->group(function (): void {
    Route::get('coverage', [CoverageController::class, 'index'])->name('coverage.index');
    Route::get('coverage/{coverageArea}', [CoverageController::class, 'show'])->name('coverage');
    Route::get('coverage/{coverageArea}/cells.geojson', [CoverageController::class, 'cells'])->name('coverage.cells');
    Route::get('coverage/{coverageArea}/boundary.geojson', [CoverageController::class, 'boundary'])->name('coverage.boundary');
    Route::get('coverage/{coverageArea}/roads.json', [CoverageController::class, 'roads'])->name('coverage.roads');

    Route::get('coverage/{coverageArea}/assignments', [AssignmentController::class, 'index'])->name('assignments');
    Route::post('coverage/{coverageArea}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.release');

    // Review. The queue is ordered worst first, so this is where a supervisor
    // starts their morning rather than somewhere they end up.
    Route::get('review', [ReviewController::class, 'index'])->name('review.index');
    Route::get('review/{observation}', [ReviewController::class, 'show'])->name('review.show');
    Route::post('review/{observation}', [ReviewController::class, 'decide'])->name('review.decide');

    // Claims. A separate queue from observation review because the question is
    // different: not "is this capture sound" but "is this person who they say".
    Route::get('claims', [ClaimReviewController::class, 'index'])->name('claims');
    Route::post('claims/{claim}', [ClaimReviewController::class, 'decide'])->name('claims.decide');
    Route::post('disputes/{dispute}', [ClaimReviewController::class, 'resolve'])->name('disputes.resolve');

    // Corrections. A third queue, because it asks a third question: not "is
    // this capture sound" and not "is this person who they say", but "is this
    // business right about itself".
    Route::get('corrections', [CorrectionReviewController::class, 'index'])->name('corrections');
    Route::post('corrections/{proposal}', [CorrectionReviewController::class, 'decide'])->name('corrections.decide');

    // Live operations. Who is out, where they are, and what is going wrong now
    // rather than at the end of the week.
    Route::get('live', [LiveOperationsController::class, 'index'])->name('live');
    Route::get('live/feed.json', [LiveOperationsController::class, 'feed'])->name('live.feed');

    // Evidence output. Every download appends to verification_events.
    Route::get('exports', [ExportController::class, 'index'])->name('exports');
    Route::get('exports/structures.geojson', [ExportController::class, 'structures'])->name('exports.structures');
    Route::get('exports/enterprises.csv', [ExportController::class, 'enterprises'])->name('exports.enterprises');
    Route::get('exports/cells/{cell}/pack.pdf', [ExportController::class, 'pack'])->name('exports.pack');
});

/*
| The in-house views. Admins only, and refused rather than redirected: a
| supervisor who lands here went looking.
|
| Separate from the console group on purpose. `supervises` is true for admins
| too, so folding these in would have put the audit log and the escalation queue
| in front of every supervisor, and escalation exists precisely so that the
| person who raised a concern is not the person who rules on it.
*/
Route::middleware(['auth', 'administers'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('escalations', [EscalationController::class, 'index'])->name('escalations');
    Route::get('escalations/{observation}', [EscalationController::class, 'show'])->name('escalations.show');
    Route::post('escalations/{observation}', [EscalationController::class, 'decide'])->name('escalations.decide');

    Route::get('audit', [AuditController::class, 'index'])->name('audit');

    Route::get('people', [PeopleController::class, 'index'])->name('people');
    Route::post('people', [PeopleController::class, 'store'])->name('people.store');
    Route::post('people/{person}/status', [PeopleController::class, 'status'])->name('people.status');
    Route::post('devices/{device}/revoke', [PeopleController::class, 'revokeDevice'])->name('devices.revoke');

    Route::get('mandates', [MandateController::class, 'index'])->name('mandates');
    Route::post('mandates', [MandateController::class, 'store'])->name('mandates.store');

    /*
    | Campaigns, from the inside. The only place commercials are reachable.
    */
    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns');
    Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::post('campaigns/{campaign}/transition', [CampaignController::class, 'transition'])->name('campaigns.transition');
    Route::post('campaigns/{campaign}/commercials', [CampaignController::class, 'commercials'])->name('campaigns.commercials');

    Route::post('campaigns/{campaign}/areas', [CampaignBuildController::class, 'saveArea'])->name('campaigns.areas.save');
    Route::delete('campaigns/{campaign}/areas/{coverageArea}', [CampaignBuildController::class, 'removeArea'])->name('campaigns.areas.remove');
    Route::post('campaigns/{campaign}/fields', [CampaignBuildController::class, 'saveField'])->name('campaigns.fields.save');
    Route::delete('campaigns/{campaign}/fields/{field}', [CampaignBuildController::class, 'removeField'])->name('campaigns.fields.remove');
    Route::post('campaigns/{campaign}/stakeholders', [CampaignBuildController::class, 'saveStakeholder'])->name('campaigns.stakeholders.save');
    Route::delete('campaigns/{campaign}/stakeholders/{stakeholder}', [CampaignBuildController::class, 'removeStakeholder'])->name('campaigns.stakeholders.remove');
    Route::post('campaigns/{campaign}/deploy', [CampaignBuildController::class, 'deploy'])->name('campaigns.deploy');
    Route::post('campaigns/{campaign}/deployments/{deployment}/stand-down', [CampaignBuildController::class, 'standDown'])->name('campaigns.stand-down');
});

/*
| The commissioning client's own surface. Their campaigns, and nothing else:
| scope, timeline, deployment, progress, stakeholders and the dossier.
|
| Never commercials. AssembleCampaignDossier, which builds every payload here,
| has no code path to campaign_commercials at all.
|
| Signing in sits outside the guarded group, like the portal's, because a person
| proving who they are does not have a session yet.
*/
Route::prefix('client')->name('client.')->group(function (): void {
    Route::get('sign-in', [ClientSignInController::class, 'show'])->name('sign-in');
    Route::post('sign-in', [ClientSignInController::class, 'signIn'])
        ->middleware('throttle:10,1')->name('sign-in.submit');
    Route::post('sign-out', [ClientSignInController::class, 'signOut'])->name('sign-out');

    Route::middleware('client')->group(function (): void {
        Route::get('/', [ClientCampaignController::class, 'dashboard'])->name('dashboard');
        Route::get('campaigns', [ClientCampaignController::class, 'index'])->name('campaigns');
        Route::get('campaigns/{campaign}', [ClientCampaignController::class, 'show'])->name('campaigns.show');
        Route::post('campaigns/{campaign}/acknowledge', [ClientCampaignController::class, 'acknowledge'])->name('campaigns.acknowledge');
        Route::get('campaigns/{campaign}/brief.pdf', [ClientCampaignController::class, 'brief'])->name('campaigns.brief');
        Route::get('campaigns/{campaign}/roads.json', [ClientCampaignController::class, 'roads'])->name('campaigns.roads');
    });
});

/*
| A media file from the local disk, behind a signature.
|
| The local equivalent of a presigned S3 link, registered in AppServiceProvider
| and never routed when object storage is configured. No session check: the
| signature is the authorisation, the same way it is for the object storage this
| stands in for, and it expires in minutes.
*/
Route::get('media/file/{path}', MediaFileController::class)
    ->where('path', '.*')
    ->name('media.file');

/*
| The campaign brief as HTML, for the browser that prints it. Outside the client
| group for the same reason the evidence pack is outside the console one: the
| browser doing the printing has no session. Signed, short lived, and refused
| off the loopback interface.
*/
Route::get('client/campaigns/{campaign}/brief.html', [ClientCampaignController::class, 'briefHtml'])
    ->name('client.campaigns.brief.render');

/*
| The evidence pack as HTML, for the browser that prints it.
|
| Outside the console group on purpose: the browser doing the printing has no
| session. The route is signed, short lived, and refused off the loopback
| interface.
*/
Route::get('exports/cells/{cell}/pack.html', [ExportController::class, 'packHtml'])
    ->name('console.exports.pack.render');

/*
| The portal. Parties, on their own guard.
|
| Signing in is deliberately outside the guarded group: a person proving a
| phone number does not have a session yet, and the same path serves both a
| returning owner and somebody who has never been here.
*/
Route::prefix('portal')->name('portal.')->group(function (): void {
    Route::get('sign-in', [SignInController::class, 'show'])->name('sign-in');
    Route::post('sign-in', [SignInController::class, 'requestCode'])
        ->middleware('throttle:12,1')->name('request-code');

    Route::get('verify', [SignInController::class, 'verifyForm'])->name('verify');
    Route::post('verify', [SignInController::class, 'verify'])
        ->middleware('throttle:20,1')->name('verify.submit');

    Route::get('register', [SignInController::class, 'registerForm'])->name('register');
    Route::post('register', [SignInController::class, 'register'])->name('register.submit');

    Route::post('sign-out', [SignInController::class, 'signOut'])->name('sign-out');

    Route::middleware('portal')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

        // Claiming. Search is the only place a field-captured record is visible
        // to somebody who has proved nothing, so its projection is thin by
        // construction: see SearchRegister.
        Route::get('claim', [ClaimController::class, 'search'])->name('claim.search');
        Route::post('claim', [ClaimController::class, 'store'])->name('claim.store');
        Route::get('claim/{claim}', [ClaimController::class, 'show'])->name('claim.show');
        Route::post('claim/{claim}/code', [ClaimController::class, 'sendCode'])
            ->middleware('throttle:10,1')->name('claim.code');
        Route::post('claim/{claim}/confirm', [ClaimController::class, 'confirmCode'])
            ->middleware('throttle:20,1')->name('claim.confirm');

        Route::get('businesses/{enterprise}', [ListingController::class, 'show'])->name('listing');

        // Correcting the register, and deciding whether to be in public. Both
        // are proposals about a listing rather than edits to it: there is no
        // update route here and there is not going to be one.
        Route::post('businesses/{enterprise}/corrections', [PortalCorrectionController::class, 'store'])
            ->name('corrections.store');
        Route::post('corrections/{proposal}/withdraw', [PortalCorrectionController::class, 'withdraw'])
            ->name('corrections.withdraw');
        Route::post('businesses/{enterprise}/publication', [PortalCorrectionController::class, 'publication'])
            ->name('publication');

        // Self-registration. The other way onto the register, for a business no
        // officer has reached. Every step is an ordinary form post so the whole
        // thing survives a connection that comes and goes.
        Route::get('register-business', [RegisterBusinessController::class, 'show'])->name('register-business');
        Route::post('register-business/name', [RegisterBusinessController::class, 'saveName'])->name('register-business.name');
        Route::post('register-business/place', [RegisterBusinessController::class, 'savePlace'])->name('register-business.place');
        Route::post('register-business/back', [RegisterBusinessController::class, 'back'])->name('register-business.back');
        Route::post('register-business', [RegisterBusinessController::class, 'submit'])->name('register-business.submit');

    });
});

/*
| The field client. An officer sees their own work and nothing else.
*/
Route::middleware(['auth', 'field'])->prefix('field')->name('field.')->group(function (): void {
    Route::get('/', [AssignmentBoardController::class, 'index'])->name('index');
    Route::get('assignments/{assignment}/capture', [CaptureScreenController::class, 'show'])->name('capture');
});

/*
| The field client's capture endpoints. Session authenticated for the online
| flow; the offline client authenticates by device token at M5.
*/
Route::middleware(['auth', 'field'])->prefix('api/field')->name('api.field.')->group(function (): void {
    Route::post('sessions', [CaptureController::class, 'startSession'])->name('sessions.start');
    Route::post('sessions/{session}/fixes', [CaptureController::class, 'appendFixes'])->name('sessions.fixes');
    Route::post('sessions/{session}/end', [CaptureController::class, 'endSession'])->name('sessions.end');

    Route::post('structures', [CaptureController::class, 'storeStructure'])->name('structures.store');
    Route::post('enterprises', [CaptureController::class, 'storeEnterprise'])->name('enterprises.store');
    Route::post('photographs', [CaptureController::class, 'storePhotograph'])->name('photographs.store');
    Route::get('photographs/{media}', [CaptureController::class, 'showPhotograph'])->name('photographs.show');

    Route::get('sectors', [CaptureController::class, 'searchSectors'])->name('sectors');

    // The offline map pack: what is available, and the bytes.
    Route::get('packs', [MapPackController::class, 'index'])->name('packs.index');
    Route::get('packs/{pack}', [MapPackController::class, 'show'])->name('packs.show');

    // Where a handset that has been offline tells the server what happened.
    Route::post('sync', SyncController::class)->name('sync');
});

<?php

declare(strict_types=1);

use App\Domain\Coverage\Actions\CheckSpatialStack;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CampaignBuildController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\DisputeController;
use App\Http\Controllers\Admin\EnumerateOrganisationController as AdminEnumerateOrganisationController;
use App\Http\Controllers\Admin\EscalationController;
use App\Http\Controllers\Admin\InvestorController as AdminInvestorController;
use App\Http\Controllers\Admin\MandateController;
use App\Http\Controllers\Admin\PeopleController;
use App\Http\Controllers\Admin\ReviewModerationController;
use App\Http\Controllers\Client\CampaignController as ClientCampaignController;
use App\Http\Controllers\Client\SignInController as ClientSignInController;
use App\Http\Controllers\ConsentReceiptController;
use App\Http\Controllers\Console\AssignmentController;
use App\Http\Controllers\Console\ClaimReviewController;
use App\Http\Controllers\Console\CorrectionReviewController;
use App\Http\Controllers\Console\CoverageController;
use App\Http\Controllers\Console\DeskCheckController;
use App\Http\Controllers\Console\EnumerateVisitController;
use App\Http\Controllers\Console\ExportController;
use App\Http\Controllers\Console\InspectionController as ConsoleInspectionController;
use App\Http\Controllers\Console\LiveOperationsController;
use App\Http\Controllers\Console\MessageController as ConsoleMessageController;
use App\Http\Controllers\Console\ReviewController;
use App\Http\Controllers\Console\SupportController as ConsoleSupportController;
use App\Http\Controllers\Console\TeamTodayController;
use App\Http\Controllers\Console\VerificationOrderController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\Enumerate\EnumerateController;
use App\Http\Controllers\Enumerate\OrganisationController as EnumerateOrganisationController;
use App\Http\Controllers\Enumerate\ReportController as EnumerateReportController;
use App\Http\Controllers\Enumerate\RequestController as EnumerateRequestController;
use App\Http\Controllers\Enumerate\SupportController as EnumerateSupportController;
use App\Http\Controllers\Enumerate\WalletController as EnumerateWalletController;
use App\Http\Controllers\Field\AssignmentBoardController;
use App\Http\Controllers\Field\CaptureController;
use App\Http\Controllers\Field\CaptureScreenController;
use App\Http\Controllers\Field\FieldHomeController;
use App\Http\Controllers\Field\FieldJobController;
use App\Http\Controllers\Field\FieldMessageController;
use App\Http\Controllers\Field\FieldVisitController;
use App\Http\Controllers\Field\MapPackController;
use App\Http\Controllers\Field\SyncController;
use App\Http\Controllers\Invest\CommissionController as InvestCommissionController;
use App\Http\Controllers\Invest\InvestorController as InvestorPortalController;
use App\Http\Controllers\Invest\SignInController as InvestSignInController;
use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\Payments\PaystackWebhookController;
use App\Http\Controllers\Portal\AccountSettingsController;
use App\Http\Controllers\Portal\BusinessProfileController;
use App\Http\Controllers\Portal\CatalogueController;
use App\Http\Controllers\Portal\CertificateController;
use App\Http\Controllers\Portal\CheckoutController;
use App\Http\Controllers\Portal\ClaimController;
use App\Http\Controllers\Portal\CorrectionController as PortalCorrectionController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\InvestorProfileController;
use App\Http\Controllers\Portal\ListingController;
use App\Http\Controllers\Portal\OrderController;
use App\Http\Controllers\Portal\OrdersIndexController;
use App\Http\Controllers\Portal\PurchaseController;
use App\Http\Controllers\Portal\RegisterBusinessController;
use App\Http\Controllers\Portal\ReviewController as PortalReviewController;
use App\Http\Controllers\Portal\SaleController;
use App\Http\Controllers\Portal\SavedListingController;
use App\Http\Controllers\Portal\SignInController;
use App\Http\Controllers\Portal\StorefrontPhotoController;
use App\Http\Controllers\Portal\TeamController;
use App\Http\Controllers\Portal\VerificationController as PortalVerificationController;
use App\Http\Controllers\Portal\WalletController;
use App\Http\Controllers\PublicVerificationController;
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
    // Team today, the supervisor's home, and the field inbox from this side.
    Route::get('/', TeamTodayController::class)->name('team');
    Route::get('brief', [TeamTodayController::class, 'brief'])->name('brief');
    // Inspections and site visits buyers paid for (Phase 4 M3).
    Route::get('inspections', [ConsoleInspectionController::class, 'index'])->name('inspections');
    Route::post('inspections/{inspection}/assign', [ConsoleInspectionController::class, 'assign'])->name('inspections.assign');
    Route::get('messages/{officer?}', [ConsoleMessageController::class, 'index'])->name('messages');
    Route::post('messages/{officer}', [ConsoleMessageController::class, 'send'])
        ->whereNumber('officer')->middleware('throttle:60,1')->name('messages.send');
    Route::post('broadcasts', [ConsoleMessageController::class, 'broadcast'])
        ->middleware('throttle:20,1')->name('broadcasts');
    Route::post('field-messages/{message}/pin', [ConsoleMessageController::class, 'pin'])->name('messages.pin');
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

    /*
    | Paid verifications. The step before a visit becomes an ordinary
    | assignment, and the acceptance that turns held money into income.
    */
    Route::get('orders', [VerificationOrderController::class, 'index'])->name('orders');
    Route::post('orders/{order}/assign', [VerificationOrderController::class, 'assign'])->name('orders.assign');
    Route::post('orders/{order}/complete', [VerificationOrderController::class, 'complete'])->name('orders.complete');
    Route::post('orders/{order}/refund', [VerificationOrderController::class, 'refund'])->name('orders.refund');

    // Enumerate's desk checks: what the registers said about a business
    // somebody paid to have checked, and the supervisor's reading of it.
    Route::get('desk-checks', [DeskCheckController::class, 'index'])->name('desk-checks');
    Route::post('desk-checks/{reference}', [DeskCheckController::class, 'decide'])->name('desk-checks.decide');
    Route::post('desk-checks/{reference}/rerun', [DeskCheckController::class, 'rerun'])
        ->middleware('throttle:20,1')->name('desk-checks.rerun');

    // Enumerate's site visits: pin the premises, send an officer, read the report.
    Route::get('enumerate-visits', [EnumerateVisitController::class, 'index'])->name('enumerate-visits');
    Route::post('enumerate-visits/requests/{reference}/assign', [EnumerateVisitController::class, 'assign'])
        ->name('enumerate-visits.assign');
    Route::post('enumerate-visits/{visit}/decide', [EnumerateVisitController::class, 'decide'])
        ->whereNumber('visit')->name('enumerate-visits.decide');
    Route::post('enumerate-visits/requests/{reference}/monitoring-officer', [EnumerateVisitController::class, 'monitoringOfficer'])
        ->name('enumerate-visits.monitoring-officer');
    Route::post('enumerate-visits/requests/{reference}/close', [EnumerateVisitController::class, 'closeMonitoring'])
        ->name('enumerate-visits.close');

    // Enumerate support: complaints and questions. Refunds are checked for an
    // administrator inside the action, not by the route.
    Route::get('support', [ConsoleSupportController::class, 'index'])->name('support');
    Route::post('support/{reference}/answer', [ConsoleSupportController::class, 'answer'])->name('support.answer');
    Route::post('support/{reference}/refund', [ConsoleSupportController::class, 'refund'])->name('support.refund');

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

    // Enumerate organisations: approval, account managers, and their projects.
    Route::get('enumerate-organisations', [AdminEnumerateOrganisationController::class, 'index'])->name('enumerate-organisations');
    Route::post('enumerate-organisations/{organisation}/decide', [AdminEnumerateOrganisationController::class, 'decide'])->name('enumerate-organisations.decide');
    Route::post('enumerate-organisations/{organisation}/manager', [AdminEnumerateOrganisationController::class, 'manager'])->name('enumerate-organisations.manager');
    Route::post('enumerate-projects/{project}', [AdminEnumerateOrganisationController::class, 'project'])->name('enumerate-projects.move');

    Route::get('investors', [AdminInvestorController::class, 'index'])->name('investors');
    Route::post('investors/{organisation}/decide', [AdminInvestorController::class, 'decide'])->name('investors.decide');

    // A buyer's issue with an order, and the ruling that moves the money.
    Route::get('disputes', [DisputeController::class, 'index'])->name('disputes');
    Route::post('disputes/{order}', [DisputeController::class, 'rule'])->name('disputes.rule');

    // Reviews buyers wrote, reported ones first. Hidden or restored, never deleted.
    Route::get('reviews', [ReviewModerationController::class, 'index'])->name('reviews');
    Route::post('reviews/{review}', [ReviewModerationController::class, 'moderate'])->name('reviews.moderate');

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
| The public directory. The first surface here that anybody may read.
|
| Open, unauthenticated and rate limited, and every projection on it is decided
| in SearchDirectory rather than in the controller. Three depths: a reduced row
| for a business that put its name on the street and never claimed the listing,
| the owner's own account of itself once claimed and opted in, and the tier and
| date once an officer has actually been.
|
| Removal takes no account and no proof. Publishing requires proving control;
| being left alone does not, and a business with a reason to be invisible should
| not have to argue with a form about it.
*/
Route::prefix('directory')->name('directory.')->group(function (): void {
    Route::get('/', [DirectoryController::class, 'index'])
        ->middleware('throttle:60,1')->name('index');

    // Before the numeric listing route, or "sectors" is read as an id.
    Route::get('roads.json', [DirectoryController::class, 'roads'])
        ->middleware('throttle:120,1')->name('roads');
    Route::get('how-verification-works', [DirectoryController::class, 'howItWorks'])->name('how');
    Route::get('sectors', [DirectoryController::class, 'sectors'])
        ->middleware('throttle:60,1')->name('sectors');

    Route::get('sectors/{code}', [DirectoryController::class, 'sector'])
        ->where('code', '[0-9]{2,6}')->middleware('throttle:60,1')->name('sector');

    Route::get('{enterprise}', [DirectoryController::class, 'show'])
        ->whereNumber('enterprise')->middleware('throttle:60,1')->name('show');

    Route::post('{enterprise}/remove', [DirectoryController::class, 'remove'])
        ->whereNumber('enterprise')->middleware('throttle:6,1')->name('remove');
});

/*
| Checking a certificate. Open to anybody holding one, which is the point.
|
| Outside every guard: the value of a printed certificate is that its holder can
| check it without an account. The token is the whole authorisation, so it is 40
| characters of random rather than anything derivable from the reference printed
| beside it, and what the page may disclose is decided in the action rather than
| in the controller.
*/
Route::get('verify/{token}', PublicVerificationController::class)
    ->where('token', '[a-z0-9]{16,64}')
    ->middleware('throttle:30,1')
    ->name('verify.show');

/*
| The certificate as HTML, for the browser that prints it. Outside the portal
| group for the same reason the evidence pack is outside the console one: the
| browser doing the printing has no session. Signed, short lived, and refused
| off the loopback interface.
*/
Route::get('portal/orders/{order}/certificate.html', [CertificateController::class, 'render'])
    ->name('portal.certificate.render');

/*
| A person's copy of what they agreed to. Open on its token, like the
| certificate check and for a neighbouring reason: the Act gives the person a
| right to this document, and somebody who was asked at their door may never
| have had an account here to sign into.
|
| The token is longer than the certificate's because this one is handed over
| rather than scanned off a page the holder already has: 48 characters, minted
| in ConsentReceipt, and the only thing standing between one person's receipt
| and everybody's.
*/
Route::prefix('receipts')->name('receipts.')->where(['token' => '[a-z0-9]{16,64}'])->group(function (): void {
    Route::get('{token}', [ConsentReceiptController::class, 'show'])
        ->middleware('throttle:30,1')->name('show');

    Route::get('{token}/copy.pdf', [ConsentReceiptController::class, 'download'])
        ->middleware('throttle:10,1')->name('download');

    // The HTML the printing browser fetches, signed and loopback only.
    Route::get('{token}/copy.html', [ConsentReceiptController::class, 'render'])
        ->name('render');
});

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

    // The second way in, and getting it back. A password is only ever set from
    // a session that proved the phone, so none of these opens an account.
    Route::post('sign-in/password', [SignInController::class, 'signInWithPassword'])
        ->middleware('throttle:10,1')->name('sign-in.password');
    Route::post('verify/resend', [SignInController::class, 'resend'])
        ->middleware('throttle:3,1')->name('verify.resend');
    Route::get('forgot-password', [SignInController::class, 'forgotForm'])->name('forgot-password');
    Route::get('reset-password', [SignInController::class, 'resetForm'])->name('reset-password');
    Route::post('reset-password', [SignInController::class, 'reset'])
        ->middleware('throttle:10,1')->name('reset-password.submit');

    Route::middleware('portal')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('settings', [AccountSettingsController::class, 'show'])->name('settings');
        Route::post('settings/email', [AccountSettingsController::class, 'email'])->name('settings.email');
        Route::post('settings/password', [AccountSettingsController::class, 'password'])
            ->middleware('throttle:10,1')->name('settings.password');

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

        // Photographs a business shows of itself. The only portal route that
        // puts something new in front of strangers on the party's own
        // authority, which is why control is checked before the file is read.
        Route::post('businesses/{enterprise}/photos', [StorefrontPhotoController::class, 'store'])
            ->middleware('throttle:20,1')->name('photos.store');

        Route::post('businesses/{enterprise}/photos/{media}/withdraw', [StorefrontPhotoController::class, 'withdraw'])
            ->name('photos.withdraw');

        // The merchant hub. Listings is the business's own catalogue; Team is
        // who may act for it; Verification and Orders read what exists.
        Route::get('businesses/{enterprise}/listings', [CatalogueController::class, 'index'])->name('listings');
        Route::post('businesses/{enterprise}/listings', [CatalogueController::class, 'store'])->name('listings.store');
        Route::post('businesses/{enterprise}/listings/{product}', [CatalogueController::class, 'update'])->name('listings.update');
        Route::post('businesses/{enterprise}/listings/{product}/withdraw', [CatalogueController::class, 'withdraw'])->name('listings.withdraw');
        Route::post('businesses/{enterprise}/listings/{product}/photos', [CatalogueController::class, 'addPhoto'])
            ->middleware('throttle:30,1')->name('listings.photos.store');
        Route::post('businesses/{enterprise}/listings/{product}/photos/{media}/withdraw', [CatalogueController::class, 'withdrawPhoto'])
            ->name('listings.photos.withdraw');
        Route::get('businesses/{enterprise}/verification', [PortalVerificationController::class, 'show'])->name('verification');
        Route::get('orders', OrdersIndexController::class)->name('orders.index');
        Route::get('inspections', [SaleController::class, 'inspections'])->name('inspections');
        Route::get('sales/{order}', [SaleController::class, 'show'])->name('sales.show');
        Route::post('sales/{order}/dispatch', [SaleController::class, 'dispatch'])->name('sales.dispatch');

        // The wallet. Balances are read from the ledger; a withdrawal is only
        // reserved here and is settled by the provider's transfer webhook.
        Route::get('wallet', [WalletController::class, 'show'])->name('wallet');
        Route::post('wallet/account', [WalletController::class, 'saveAccount'])
            ->middleware('throttle:6,1')->name('wallet.account');
        Route::post('wallet/withdraw', [WalletController::class, 'withdraw'])
            ->middleware('throttle:6,1')->name('wallet.withdraw');
        Route::get('team', [TeamController::class, 'index'])->name('team');
        Route::post('team', [TeamController::class, 'invite'])->middleware('throttle:20,1')->name('team.invite');
        Route::post('team/{member}/role', [TeamController::class, 'role'])->name('team.role');
        Route::post('team/{member}/revoke', [TeamController::class, 'revoke'])->name('team.revoke');
        Route::post('team/{member}/accept', [TeamController::class, 'accept'])->name('team.accept');

        // What a business tells investors, and who may read its data room.
        // Nothing reaches the investor portal except through these.
        Route::get('businesses/{enterprise}/investors', [InvestorProfileController::class, 'show'])->name('investors');
        Route::post('businesses/{enterprise}/investors', [InvestorProfileController::class, 'save'])->name('investors.save');
        Route::post('businesses/{enterprise}/investors/withdraw', [InvestorProfileController::class, 'withdraw'])->name('investors.withdraw');
        Route::post('businesses/{enterprise}/investors/documents', [InvestorProfileController::class, 'upload'])
            ->middleware('throttle:20,1')->name('investors.documents.store');
        Route::post('businesses/{enterprise}/investors/documents/{document}/withdraw', [InvestorProfileController::class, 'withdrawDocument'])
            ->name('investors.documents.withdraw');
        Route::post('businesses/{enterprise}/investors/requests/{grant}', [InvestorProfileController::class, 'decide'])
            ->name('investors.requests.decide');

        // Self-registration. The other way onto the register, for a business no
        // officer has reached. Every step is an ordinary form post so the whole
        // thing survives a connection that comes and goes.
        Route::get('register-business', [RegisterBusinessController::class, 'show'])->name('register-business');
        Route::post('register-business/name', [RegisterBusinessController::class, 'saveName'])->name('register-business.name');
        Route::post('register-business/place', [RegisterBusinessController::class, 'savePlace'])->name('register-business.place');
        Route::post('register-business/back', [RegisterBusinessController::class, 'back'])->name('register-business.back');
        Route::post('register-business', [RegisterBusinessController::class, 'submit'])->name('register-business.submit');

        // Buying from a business (M2). The same rule as buying verification
        // below: the order is placed here and marked paid only by the webhook.
        Route::get('checkout/{enterprise}', [CheckoutController::class, 'show'])->name('checkout');
        Route::post('checkout/{enterprise}', [CheckoutController::class, 'store'])
            ->middleware('throttle:20,1')->name('checkout.store');
        Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::get('purchases/{order}', [PurchaseController::class, 'show'])->name('purchases.show');
        Route::post('purchases/{order}/pay', [PurchaseController::class, 'pay'])->name('purchases.pay');
        Route::get('purchases/{order}/return', [PurchaseController::class, 'return'])->name('purchases.return');
        Route::post('purchases/{order}/confirm', [PurchaseController::class, 'confirm'])->name('purchases.confirm');
        Route::post('purchases/{order}/issue', [PurchaseController::class, 'issue'])
            ->middleware('throttle:10,1')->name('purchases.issue');
        Route::post('purchases/{order}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel');
        Route::post('purchases/{order}/inspection', [PurchaseController::class, 'inspection'])->name('purchases.inspection');
        Route::post('purchases/{order}/review', [PortalReviewController::class, 'store'])
            ->middleware('throttle:10,1')->name('purchases.review');
        Route::post('reviews/{review}/report', [PortalReviewController::class, 'report'])
            ->middleware('throttle:10,1')->name('reviews.report');
        Route::get('saved', [SavedListingController::class, 'index'])->name('saved');
        Route::post('saved/{enterprise}', [SavedListingController::class, 'toggle'])
            ->middleware('throttle:60,1')->name('saved.toggle');
        Route::get('businesses/{enterprise}/profile', [BusinessProfileController::class, 'show'])->name('profile');
        Route::post('businesses/{enterprise}/profile', [BusinessProfileController::class, 'save'])->name('profile.save');

        // Buying verification. The order is placed here, but it is only ever
        // paid by the provider's signed webhook: nothing on this guard, and
        // nothing a customer's browser can reach, marks money as received.
        Route::get('businesses/{enterprise}/verify/{tier}', [OrderController::class, 'create'])
            ->name('orders.create');
        Route::post('businesses/{enterprise}/verify', [OrderController::class, 'store'])
            ->name('orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
        Route::get('orders/{order}/return', [OrderController::class, 'return'])->name('orders.return');

        // The certificate, printed on demand rather than stored. A file on disk
        // would keep asserting a finding after the result was overturned.
        Route::get('orders/{order}/certificate.pdf', [CertificateController::class, 'download'])
            ->name('certificate');
    });
});

/*
| Enumerate. Individuals pay from a wallet to have any business checked.
|
| The same portal accounts and guard as the business portal (one person, one
| wallet), behind its own front door. Signing in posts to the portal's
| endpoints; the door only decides where the person lands afterwards.
*/
Route::prefix('enumerate')->name('enumerate.')->group(function (): void {
    Route::get('sign-in', [EnumerateController::class, 'signIn'])->name('sign-in');

    Route::middleware('portal')->group(function (): void {
        Route::get('/', [EnumerateController::class, 'home'])->name('home');

        // The register's candidates for the search box. Each call is a paid
        // lookup at the provider, so it is limited per person.
        Route::get('lookup', [EnumerateRequestController::class, 'lookup'])
            ->middleware('throttle:30,1')->name('lookup');

        Route::get('verify', [EnumerateRequestController::class, 'create'])->name('requests.create');
        Route::post('verify', [EnumerateRequestController::class, 'store'])
            ->middleware('throttle:20,1')->name('requests.store');
        Route::get('verifications', [EnumerateRequestController::class, 'index'])->name('requests.index');
        Route::get('verifications/{reference}', [EnumerateRequestController::class, 'show'])
            ->where('reference', 'VRF-[0-9]{8}-[A-Z0-9]{4,8}')->name('requests.show');
        Route::get('verifications/{reference}/report.pdf', [EnumerateReportController::class, 'download'])
            ->where('reference', 'VRF-[0-9]{8}-[A-Z0-9]{4,8}')->middleware('throttle:10,1')->name('report');

        // The wallet grows only on the provider's signed webhook. These start
        // a top-up and welcome the person back; neither records anything.
        // Acting as oneself or for an organisation (E5).
        Route::post('switch', [EnumerateController::class, 'switch'])->name('switch');
        Route::get('organisations/new', [EnumerateOrganisationController::class, 'create'])->name('organisations.create');
        Route::post('organisations', [EnumerateOrganisationController::class, 'store'])
            ->middleware('throttle:5,1')->name('organisations.store');
        Route::post('invitations/{member}/accept', [EnumerateOrganisationController::class, 'accept'])->name('invitations.accept');

        Route::prefix('organisation')->name('organisation')->group(function (): void {
            Route::get('/', [EnumerateOrganisationController::class, 'overview']);
            Route::get('team', [EnumerateOrganisationController::class, 'teamPage'])->name('.team');
            Route::post('team', [EnumerateOrganisationController::class, 'invite'])->middleware('throttle:20,1')->name('.team.invite');
            Route::post('team/{seat}/role', [EnumerateOrganisationController::class, 'role'])->name('.team.role');
            Route::post('team/{seat}/revoke', [EnumerateOrganisationController::class, 'revoke'])->name('.team.revoke');

            Route::get('bulk/template.csv', [EnumerateOrganisationController::class, 'template'])->name('.bulk.template');
            Route::post('bulk', [EnumerateOrganisationController::class, 'bulk'])->middleware('throttle:10,1')->name('.bulk');
            Route::get('bulk/{reference}', [EnumerateOrganisationController::class, 'batch'])->name('.batch');

            Route::get('projects', [EnumerateOrganisationController::class, 'projectsPage'])->name('.projects');
            Route::post('projects', [EnumerateOrganisationController::class, 'storeProject'])->middleware('throttle:10,1')->name('.projects.store');
            Route::get('projects/{reference}', [EnumerateOrganisationController::class, 'project'])->name('.project');
        });

        // Support and complaints.
        Route::get('support', [EnumerateSupportController::class, 'index'])->name('support');
        Route::post('support', [EnumerateSupportController::class, 'store'])
            ->middleware('throttle:10,1')->name('support.store');
        Route::post('support/{reference}/reply', [EnumerateSupportController::class, 'reply'])
            ->middleware('throttle:30,1')->name('support.reply');

        Route::get('wallet', [EnumerateWalletController::class, 'show'])->name('wallet');
        Route::post('wallet/fund', [EnumerateWalletController::class, 'fund'])
            ->middleware('throttle:10,1')->name('wallet.fund');
        Route::get('wallet/return', [EnumerateWalletController::class, 'return'])->name('wallet.return');
    });
});

/*
| The Enumerate report as HTML, for the browser that prints it. Outside the
| portal group for the certificate's reason: the printing browser has no
| session. Signed, short lived, and refused off the loopback interface.
*/
Route::get('enumerate/reports/{reference}/report.html', [EnumerateReportController::class, 'render'])
    ->where('reference', 'VRF-[0-9]{8}-[A-Z0-9]{4,8}')->name('enumerate.report.render');

/*
| The field client. An officer sees their own work and nothing else.
*/
Route::middleware(['auth', 'field'])->prefix('field')->name('field.')->group(function (): void {
    Route::get('/', [FieldHomeController::class, 'today'])->name('index');
    Route::get('map', [FieldHomeController::class, 'map'])->name('map');
    Route::get('records', [FieldHomeController::class, 'records'])->name('records');
    Route::get('inbox', [FieldHomeController::class, 'inbox'])->name('inbox');
    Route::get('brief', [FieldHomeController::class, 'brief'])->name('brief');
    Route::get('device', [FieldHomeController::class, 'device'])->name('device');
    // The previous board, kept for the cells list it shows.
    Route::get('cells', [AssignmentBoardController::class, 'index'])->name('cells');
    // An inspection or site visit a buyer paid for (Phase 4 M3).
    Route::get('jobs/{inspection}', [FieldJobController::class, 'show'])->name('jobs.show');
    // A site visit somebody paid for through Enumerate (E2).
    Route::get('visits/{visit}', [FieldVisitController::class, 'show'])->name('visits.show');
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

    // The supervisor inbox. A channel of its own beside the sync contract,
    // idempotent on the handset's uuid like everything else sent from here.
    Route::get('messages', [FieldMessageController::class, 'index'])->name('messages.index');
    Route::post('messages', [FieldMessageController::class, 'store'])
        ->middleware('throttle:60,1')->name('messages.store');
    Route::post('messages/read', [FieldMessageController::class, 'read'])->name('messages.read');

    // What the job screen's outbox sends: arrival, photographs, the report.
    Route::post('jobs/{inspection}/arrive', [FieldJobController::class, 'arrive'])->name('jobs.arrive');
    Route::post('jobs/{inspection}/photos', [FieldJobController::class, 'photo'])
        ->middleware('throttle:60,1')->name('jobs.photos');
    Route::post('jobs/{inspection}/report', [FieldJobController::class, 'report'])->name('jobs.report');

    // The same three for an Enumerate site visit.
    Route::post('visits/{visit}/arrive', [FieldVisitController::class, 'arrive'])->name('visits.arrive');
    Route::post('visits/{visit}/photos', [FieldVisitController::class, 'photo'])
        ->middleware('throttle:60,1')->name('visits.photos');
    Route::post('visits/{visit}/report', [FieldVisitController::class, 'report'])->name('visits.report');
});

/*
| The payment provider, calling us.
|
| Outside every guard and outside CSRF, because a server-to-server call has
| neither a session nor a token. Its authenticity is the signature on the body,
| which is checked before a single field is read out of it.
|
| This is the only route in the application that can cause money to be recorded
| as received.
*/
Route::post('webhooks/paystack', PaystackWebhookController::class)
    ->name('webhooks.paystack');

/*
| The Global Investor and Discovery Portal, on its own guard.
|
| The overview and the explore map read aggregates over the directory-visible
| population and are open as soon as somebody has an account. Everything that
| names a business (opportunities, dossiers, data rooms, reports) waits for
| the organisation to pass KYC, which `investor:verified` enforces.
*/
Route::prefix('invest')->name('invest.')->group(function (): void {
    Route::get('sign-in', [InvestSignInController::class, 'show'])->name('sign-in');
    Route::post('sign-in', [InvestSignInController::class, 'signIn'])
        ->middleware('throttle:10,1')->name('sign-in.submit');
    Route::get('request-access', [InvestSignInController::class, 'requestForm'])->name('request-access');
    Route::post('request-access', [InvestSignInController::class, 'request'])
        ->middleware('throttle:5,1')->name('request-access.submit');
    Route::post('sign-out', [InvestSignInController::class, 'signOut'])->name('sign-out');
    Route::get('forgot-password', [InvestSignInController::class, 'forgotForm'])->name('forgot-password');
    Route::post('forgot-password', [InvestSignInController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')->name('forgot-password.submit');
    Route::get('reset-password/{token}', [InvestSignInController::class, 'resetForm'])->name('reset-password');
    Route::post('reset-password', [InvestSignInController::class, 'reset'])
        ->middleware('throttle:10,1')->name('reset-password.submit');
    Route::get('sso', [InvestSignInController::class, 'ssoForm'])->name('sso');
    Route::post('sso', [InvestSignInController::class, 'sso'])->middleware('throttle:10,1')->name('sso.submit');

    Route::middleware('investor')->group(function (): void {
        Route::get('/', [InvestorPortalController::class, 'overview'])->name('overview');
        Route::get('explore', [InvestorPortalController::class, 'explore'])->name('explore');
        Route::get('settings', [InvestorPortalController::class, 'settings'])->name('settings');
        Route::post('settings/profile', [InvestorPortalController::class, 'updateProfile'])->name('settings.profile');
    });

    Route::middleware('investor:verified')->group(function (): void {
        Route::get('opportunities', [InvestorPortalController::class, 'opportunities'])->name('opportunities');
        Route::get('opportunities/{opportunity}', [InvestorPortalController::class, 'show'])->name('opportunities.show');
        Route::post('opportunities/{opportunity}/watch', [InvestorPortalController::class, 'watch'])->name('opportunities.watch');
        Route::post('opportunities/{opportunity}/interest', [InvestorPortalController::class, 'interest'])->name('opportunities.interest');
        Route::post('opportunities/{opportunity}/note', [InvestorPortalController::class, 'note'])->name('opportunities.note');
        Route::post('opportunities/{opportunity}/data-room', [InvestorPortalController::class, 'requestRoom'])->name('opportunities.data-room');
        Route::get('opportunities/{opportunity}/documents/{document}', [InvestorPortalController::class, 'document'])->name('opportunities.document');
        Route::get('opportunities/{opportunity}/certificates/{order}.pdf', [InvestorPortalController::class, 'certificate'])->name('opportunities.certificate');
        Route::get('watchlist', [InvestorPortalController::class, 'watchlist'])->name('watchlist');
        Route::get('data-rooms', [InvestorPortalController::class, 'dataRooms'])->name('data-rooms');
        Route::get('reports', [InvestorPortalController::class, 'reports'])->name('reports');

        // Commissioned visits. Paid only by the provider's signed webhook, like
        // every other order: pay asks for a checkout page, return is inert.
        Route::get('verifications', [InvestCommissionController::class, 'index'])->name('verifications');
        Route::get('opportunities/{opportunity}/commission', [InvestCommissionController::class, 'create'])->name('commission.create');
        Route::post('opportunities/{opportunity}/commission', [InvestCommissionController::class, 'store'])->name('commission.store');
        Route::get('verifications/{order}', [InvestCommissionController::class, 'show'])->name('verifications.show');
        Route::post('verifications/{order}/pay', [InvestCommissionController::class, 'pay'])->name('verifications.pay');
        Route::get('verifications/{order}/return', [InvestCommissionController::class, 'return'])->name('verifications.return');
        Route::get('verifications/{order}/certificate.pdf', [InvestCommissionController::class, 'certificate'])->name('verifications.certificate');
    });
});

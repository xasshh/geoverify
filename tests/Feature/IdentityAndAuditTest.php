<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\HashIdentityReference;
use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

it('has no column anywhere that could hold a raw NIN or BVN', function () {
    // The guarantee is structural. A column that does not exist cannot be filled
    // in later by a well meaning change, and this is the test that keeps it that
    // way when someone adds a "just for verification" field three milestones from
    // now.
    $suspect = DB::select(<<<'SQL'
        SELECT table_name, column_name
          FROM information_schema.columns
         WHERE table_schema = 'public'
           AND (
                column_name ~* '(^|_)(nin|bvn)($|_)'
             OR column_name ILIKE '%national_id%'
             OR column_name ILIKE '%bank_verification%'
           )
    SQL);

    expect($suspect)->toBeEmpty();
});

it('lets no queued job carry more than an identifier', function () {
    // Phase 2 added a rule the field platform never needed: no raw NIN in a
    // queue payload either. The way that rule gets broken is not by somebody
    // typing a number into a job. It is by a job growing a model or an array
    // that happens to hold nothing sensitive this year, and something else
    // entirely after a column is added to it.
    //
    // So the guard is on shape. A queued job takes identifiers and reads what
    // it needs when it runs, which is also the only version that is correct
    // after a retry: a payload serialised on Tuesday describes Tuesday.
    $jobs = array_map(
        static fn (SplFileInfo $file): string => 'App\\Jobs\\'.$file->getFilenameWithoutExtension(),
        File::files(app_path('Jobs')),
    );

    expect($jobs)->not->toBeEmpty();

    foreach ($jobs as $job) {
        $parameters = (new ReflectionClass($job))->getConstructor()?->getParameters() ?? [];

        foreach ($parameters as $parameter) {
            expect((string) $parameter->getType())
                ->toBeIn(['int', 'string', '?int', '?string'], "{$job} takes a {$parameter->getType()}");
        }
    }
});

it('hashes a NIN irreversibly and keeps only the last four digits', function () {
    $hasher = app(HashIdentityReference::class);
    $nin = '12345678901';

    $result = $hasher->hash(IdentityClaim::KIND_NIN, $nin);

    expect($result['last4'])->toBe('8901')
        ->and($result['token'])->toHaveLength(64)
        // The number must not survive anywhere in what is kept.
        ->and($result['token'])->not->toContain($nin)
        ->and($result['token'])->not->toContain('1234567')
        // Same number, same token, so the same person is recognisable across
        // records without ever being readable.
        ->and($hasher->hash(IdentityClaim::KIND_NIN, $nin)['token'])->toBe($result['token'])
        ->and($hasher->matches(IdentityClaim::KIND_NIN, $nin, $result['token']))->toBeTrue()
        ->and($hasher->matches(IdentityClaim::KIND_NIN, '10987654321', $result['token']))->toBeFalse();
});

it('keys the hash, so it cannot be reversed by walking every eleven digit number', function () {
    $hasher = app(HashIdentityReference::class);
    $nin = '12345678901';

    // A bare digest of an eleven digit number is brute forceable on a laptop:
    // there are only a hundred billion of them. The stored token must not equal
    // the unkeyed digest.
    expect($hasher->hash(IdentityClaim::KIND_NIN, $nin)['token'])->not->toBe(hash('sha256', $nin));
});

it('stores a CAC number in full, because it is public record', function () {
    $hasher = app(HashIdentityReference::class);

    $result = $hasher->hash(IdentityClaim::KIND_CAC, 'RC 1234567');

    expect($result['token'])->toBe('RC 1234567')
        ->and($result['last4'])->toBeNull();
});

it('rejects a NIN that is not eleven digits, with a sentence an officer can act on', function () {
    $hasher = app(HashIdentityReference::class);

    expect(fn () => $hasher->hash(IdentityClaim::KIND_NIN, '123'))
        ->toThrow(InvalidArgumentException::class, '11 digits');
});

it('shows a masked reference and never the number', function () {
    $claim = new IdentityClaim([
        'kind' => IdentityClaim::KIND_NIN,
        'reference_token' => str_repeat('a', 64),
        'reference_last4' => '8901',
    ]);

    expect($claim->maskedReference())->toBe('••••8901')
        ->and($claim->maskedReference())->not->toContain(str_repeat('a', 64));
});

it('refuses to update or delete an audit event through the model', function () {
    $officer = person(Role::Officer);
    $event = VerificationEvent::record($officer, 'test.event', $officer, ['note' => 'original']);

    // Stopped early, with a message that says why rather than a driver error.
    expect(fn () => $event->update(['event' => 'tampered']))
        ->toThrow(RuntimeException::class, 'append only');

    expect(fn () => $event->delete())
        ->toThrow(RuntimeException::class, 'append only');

    expect(DB::scalar('select event from verification_events where id = ?', [$event->id]))
        ->toBe('test.event');
});

// The next three run in their own tests because a failed statement poisons the
// surrounding transaction in PostgreSQL, so nothing can be asserted after one.
// Each guarantee is worth its own test anyway: they are enforced separately.

it('refuses a direct UPDATE of an audit event, bypassing the model', function () {
    $officer = person(Role::Officer);
    $event = VerificationEvent::record($officer, 'test.event', $officer);

    expect(fn () => DB::table('verification_events')->where('id', $event->id)->update(['event' => 'tampered']))
        ->toThrow(QueryException::class);
});

it('refuses a direct DELETE of an audit event, bypassing the model', function () {
    $officer = person(Role::Officer);
    $event = VerificationEvent::record($officer, 'test.event', $officer);

    expect(fn () => DB::table('verification_events')->where('id', $event->id)->delete())
        ->toThrow(QueryException::class);
});

it('refuses to truncate the audit log', function () {
    // Row triggers do not fire on TRUNCATE, so without a statement level trigger
    // the whole log could be emptied in one statement while the schema stayed
    // intact.
    expect(fn () => DB::statement('TRUNCATE verification_events'))
        ->toThrow(QueryException::class);
});

it('encrypts the verifier payload at rest', function () {
    $officer = person(Role::Officer);

    $claim = IdentityClaim::query()->create([
        'claimable_type' => $officer->getMorphClass(),
        'claimable_id' => $officer->id,
        'kind' => IdentityClaim::KIND_CAC,
        'reference_token' => 'RC 1234567',
        'status' => IdentityClaim::STATUS_VERIFIED,
        'raw_payload' => ['receipt' => 'abc-123', 'name' => 'Mama Ngozi Provisions Ltd'],
        'client_uuid' => (string) Str::uuid7(),
    ]);

    $stored = (string) DB::scalar('select raw_payload from identity_claims where id = ?', [$claim->id]);

    expect($stored)->not->toContain('abc-123')
        ->and($stored)->not->toContain('Mama Ngozi')
        // And still readable through the model.
        ->and($claim->fresh()?->raw_payload)->toMatchArray(['receipt' => 'abc-123']);
});

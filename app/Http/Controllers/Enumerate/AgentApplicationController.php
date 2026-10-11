<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enumerate;

use App\Domain\Identity\Models\IdentityClaim;
use App\Domain\Staff\Actions\ManageAgentApplication;
use App\Domain\Staff\Actions\PresentAgentApplication;
use App\Domain\Staff\Models\AgentApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Applying to become a field agent, from inside the Enumerate dashboard.
 *
 * Somebody applies with the same portal account they would verify a business
 * with, so there is one sign up and one inbox to prove. The application is
 * always the signed in person's own: no route here takes an application id.
 */
final class AgentApplicationController
{
    public function __construct(
        private readonly ManageAgentApplication $manage,
        private readonly PresentAgentApplication $present,
        private readonly EnumerateController $enumerate,
    ) {}

    public function show(Request $request): Response
    {
        $account = EnumerateController::account($request);
        $application = $this->manage->forAccount($account);

        return Inertia::render('enumerate/AgentApplication', [
            'frame' => $this->enumerate->frame($request, $account),
            'application' => $application === null ? null : $this->present->page($application),
            'choices' => PresentAgentApplication::choices(),
            'adultBefore' => now()->subYears(18)->toDateString(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $this->manage->start(EnumerateController::account($request));

        return redirect()->route('enumerate.agent');
    }

    public function about(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'surname' => ['required', 'string', 'max:80'],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString(), 'after:1940-01-01'],
            'sex' => ['required', Rule::in(['female', 'male'])],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{10,16}$/'],
            'whatsapp' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{10,16}$/'],
            'residential_address' => ['required', 'string', 'max:300'],
            'residence_state_id' => ['required', 'integer'],
            'residence_lga_id' => ['required', 'integer'],
            'origin_state_id' => ['nullable', 'integer'],
            'origin_lga_id' => ['nullable', 'integer'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => ['string', Rule::in(AgentApplication::LANGUAGES)],
        ], [
            'date_of_birth.before_or_equal' => 'You need to be at least 18 years old to apply.',
            'phone.regex' => 'Enter a phone number like 0803 123 4567.',
            'whatsapp.regex' => 'Enter a phone number like 0803 123 4567.',
            'languages.required' => 'Choose at least one language you speak.',
        ]);

        return $this->attempt($request, 'about', function (AgentApplication $application) use ($data): void {
            /** @var array{surname: string, first_name: string, middle_name?: string|null, date_of_birth: string, sex: string, phone: string, whatsapp?: string|null, residential_address: string, residence_state_id: int, residence_lga_id: int, origin_state_id?: int|null, origin_lga_id?: int|null, languages: list<string>} $data */
            $this->manage->saveAboutYou($application, $data);
        });
    }

    public function background(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nin' => ['nullable', 'string', 'regex:/^[0-9 ]{11,14}$/'],
            'bvn' => ['nullable', 'string', 'regex:/^[0-9 ]{11,14}$/'],
            'qualification' => ['required', Rule::in(array_keys(AgentApplication::QUALIFICATIONS))],
            'experience' => ['array'],
            'experience.*' => ['string', Rule::in(array_keys(AgentApplication::EXPERIENCE))],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
            'guarantor.name' => ['required', 'string', 'max:160'],
            'guarantor.phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{10,16}$/'],
            'guarantor.address' => ['required', 'string', 'max:300'],
            'guarantor.occupation' => ['required', 'string', 'max:120'],
            'next_of_kin.name' => ['required', 'string', 'max:160'],
            'next_of_kin.relationship' => ['required', 'string', 'max:60'],
            'next_of_kin.phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{10,16}$/'],
        ], [
            'nin.regex' => 'A NIN has 11 digits.',
            'bvn.regex' => 'A BVN has 11 digits.',
            '*.phone.regex' => 'Enter a phone number like 0803 123 4567.',
        ]);

        return $this->attempt($request, 'background', function (AgentApplication $application) use ($data): void {
            foreach ([IdentityClaim::KIND_NIN, IdentityClaim::KIND_BVN] as $kind) {
                $number = $data[$kind] ?? null;
                if (is_string($number) && trim($number) !== '') {
                    $this->manage->declareIdentity($application, $kind, $number);
                }
            }

            $this->manage->saveBackground($application, [
                'qualification' => $data['qualification'],
                'experience' => $data['experience'] ?? [],
                'experience_years' => $data['experience_years'] ?? null,
                'guarantor' => $data['guarantor'],
                'next_of_kin' => $data['next_of_kin'],
            ]);
        });
    }

    public function file(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(AgentApplication::FILE_KINDS))],
            'label' => ['nullable', 'string', 'max:64'],
            'file' => ['required', 'file', 'max:6144', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
        ], [
            'file.max' => 'That file is too large. Send one under 6 MB: a photo from your phone camera is usually fine.',
            'file.mimes' => 'Send a photo (JPG or PNG) or a PDF.',
        ]);

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return $this->attempt($request, 'file', function (AgentApplication $application) use ($data, $file): void {
            $this->manage->storeFile($application, $data['kind'], $file, $data['label'] ?? null);
        });
    }

    public function device(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ownership' => ['required', Rule::in(['personal', 'company'])],
            'power_bank' => ['required', 'boolean'],
            'user_agent' => ['nullable', 'string', 'max:400'],
            'platform' => ['nullable', 'string', 'max:80'],
            'storage_free_mb' => ['nullable', 'integer', 'min:0'],
            'gps_best_accuracy_m' => ['required', 'numeric', 'min:0', 'max:100000'],
            'gps_samples' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ], [
            'gps_best_accuracy_m.required' => 'Run the location test first. It takes up to two minutes.',
        ]);

        return $this->attempt($request, 'device', function (AgentApplication $application) use ($data): void {
            $this->manage->saveDevice($application, $data + ['gps_tested_at' => now()->toIso8601String()]);
        });
    }

    public function agree(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'agreed' => ['required', 'array'],
            'agreed.*' => ['string', Rule::in(array_keys(AgentApplication::CONSENTS))],
            'signed_name' => ['required', 'string', 'min:3', 'max:160'],
        ], ['signed_name.required' => 'Type your full name to sign.']);

        return $this->attempt($request, 'agreements', function (AgentApplication $application) use ($data): void {
            /** @var list<string> $agreed */
            $agreed = array_values($data['agreed']);
            $this->manage->agree($application, $agreed, $data['signed_name']);
        });
    }

    public function submit(Request $request): RedirectResponse
    {
        return $this->attempt($request, 'submit', function (AgentApplication $application): void {
            $this->manage->submit($application);
        }, 'Your application has been sent. We will email you when we have looked at it.');
    }

    /** Runs a change against the signed in person's own application. */
    private function attempt(Request $request, string $key, callable $change, string $done = 'Saved.'): RedirectResponse
    {
        $application = $this->manage->forAccount(EnumerateController::account($request));

        if ($application === null) {
            return redirect()->route('enumerate.agent');
        }

        try {
            $change($application);
        } catch (RuntimeException|InvalidArgumentException $e) {
            throw ValidationException::withMessages([$key => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }
}

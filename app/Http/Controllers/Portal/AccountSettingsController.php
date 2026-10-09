<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Party\Actions\ManagePortalCredentials;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Actions\SendPortalEmailVerification;
use App\Domain\Party\Models\PortalAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The account's own settings: an email for receipts and a password for the
 * faster way back in. The phone is shown and not editable here, because it is
 * the credential everything else hangs on.
 */
final class AccountSettingsController
{
    public function show(Request $request, NormalisePhone $phones): Response
    {
        $account = $this->account($request);

        return Inertia::render('portal/Settings', [
            'account' => [
                'name' => $account->name,
                'phone' => $account->phone === null ? null : $phones->forDisplay($account->phone),
                'email' => $account->email,
                'emailVerified' => $account->email_verified_at !== null,
                'hasPassword' => $account->password !== null,
                'businessIds' => $account->memberships()
                    ->whereNull('revoked_at')
                    ->whereNotNull('accepted_at')
                    ->with('party:id,code,display_name')
                    ->get()
                    ->map(static fn ($m): array => ['code' => $m->party?->code, 'name' => $m->party?->display_name])
                    ->all(),
            ],
        ]);
    }

    public function email(Request $request, ManagePortalCredentials $credentials, SendPortalEmailVerification $verify): RedirectResponse
    {
        $validated = $request->validate(['email' => ['nullable', 'email', 'max:180']]);
        $account = $this->account($request);
        $before = $account->email;

        try {
            $credentials->setEmail($account, $validated['email'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        if ($account->email !== null && $account->email !== $before) {
            try {
                $verify($account);
            } catch (\Throwable $e) {
                report($e);
            }

            return redirect()->route('portal.email.notice')->with('status', 'Email changed. Follow the link we sent to '.$account->email.' to confirm it.');
        }

        return back()->with('status', 'Email saved.');
    }

    public function password(Request $request, ManagePortalCredentials $credentials): RedirectResponse
    {
        $account = $this->account($request);

        $validated = $request->validate([
            'current_password' => [$account->password === null ? 'nullable' : 'required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        // Changing a password asks for the old one. Setting the first one does
        // not: the session already proved the phone to get here.
        if ($account->password !== null && ! Hash::check((string) ($validated['current_password'] ?? ''), $account->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        $credentials->setPassword($account, $validated['password'], 'settings');

        return back()->with('status', 'Password saved. You can now sign in with your business ID or email.');
    }

    private function account(Request $request): PortalAccount
    {
        $account = $request->user('portal');
        abort_unless($account instanceof PortalAccount, 403);

        return $account;
    }
}

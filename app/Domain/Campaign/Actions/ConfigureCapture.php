<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Actions;

use App\Domain\Campaign\Enums\CaptureMode;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a campaign's officers capture, and the tolerances area capture runs to.
 *
 * Switching area features on for the first time gives the campaign its own copy
 * of the template catalogue, so an officer is never handed the mode with
 * nothing to pick.
 */
final class ConfigureCapture
{
    public function __construct(private readonly ManageFeatureClasses $classes) {}

    /**
     * @param  array{capture_modes: list<string>, min_mapping_unit_ha?: float|string|null, field_max_accuracy_m?: int|null, verification_sample_pct?: int|null, boundary_tolerance_m?: int|null}  $settings
     */
    public function __invoke(Campaign $campaign, array $settings, ?User $actor): Campaign
    {
        $modes = array_values(array_unique(array_map(
            static fn (string $m): string => (CaptureMode::tryFrom($m)
                ?? throw ValidationException::withMessages(['capture_modes' => "Unknown capture mode {$m}."]))->value,
            $settings['capture_modes'],
        )));

        if ($modes === []) {
            throw ValidationException::withMessages(['capture_modes' => 'A campaign captures at least one thing.']);
        }

        // Stored in declaration order, so the same choice always reads the same.
        $modes = array_values(array_filter(
            array_map(static fn (CaptureMode $m): string => $m->value, CaptureMode::cases()),
            static fn (string $m): bool => in_array($m, $modes, true),
        ));

        return DB::transaction(function () use ($campaign, $settings, $modes, $actor): Campaign {
            // Read back first: a campaign created in this request has not seen
            // its database defaults yet, and a setting not sent is left alone.
            $campaign->refresh();

            $before = $campaign->only([
                'capture_modes', 'min_mapping_unit_ha', 'field_max_accuracy_m',
                'verification_sample_pct', 'boundary_tolerance_m',
            ]);

            $campaign->capture_modes = $modes;

            foreach (['min_mapping_unit_ha', 'field_max_accuracy_m', 'verification_sample_pct', 'boundary_tolerance_m'] as $setting) {
                if (array_key_exists($setting, $settings)) {
                    $campaign->{$setting} = $settings[$setting];
                }
            }

            // These two have a meaning when blank (none, and the default), so a
            // blank from a form restores the default rather than writing null.
            $campaign->verification_sample_pct ??= 10;
            $campaign->boundary_tolerance_m ??= 25;

            $dirty = array_keys($campaign->getDirty());
            $campaign->save();

            $copied = 0;

            if ($campaign->captures(CaptureMode::AreaFeatures) && ! $campaign->featureClasses()->exists()) {
                $copied = $this->classes->copyTemplates($campaign, $actor);
            }

            if ($dirty !== []) {
                VerificationEvent::record($campaign, 'campaign.capture_configured', $actor, [
                    'changed' => $dirty,
                    'before' => array_intersect_key($before, array_flip($dirty)),
                    'classes_copied' => $copied,
                ]);
            }

            return $campaign->refresh();
        });
    }
}

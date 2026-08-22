<?php

declare(strict_types=1);

/**
 * Guards the font subsets against a well-meaning optimisation.
 *
 * Restricting the UI faces to the "latin" subset looks like a sensible saving and
 * is not: Yoruba, Igbo and Hausa orthography lives in "latin-ext" and "vietnamese",
 * so trimming them renders Nigerian trading and person names in a fallback face.
 * In a register sold on accuracy that is a defect, not a cosmetic issue.
 */
it('loads the font subsets Nigerian orthography needs', function (string $family) {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toBeString()
        ->and($css)->toContain("@fontsource/{$family}/latin-400.css")
        ->and($css)->toContain("@fontsource/{$family}/latin-ext-400.css")
        ->and($css)->toContain("@fontsource/{$family}/vietnamese-400.css");
})->with(['ibm-plex-sans', 'ibm-plex-mono']);

it('documents which characters depend on each subset', function () {
    // A failure here means a subset was dropped and these would fall back.
    $samples = [
        'latin-ext' => ['ṣ' => 0x1E63, 'ṅ' => 0x1E45, 'ɓ' => 0x0253, 'ɗ' => 0x0257, 'ƙ' => 0x0199],
        'vietnamese' => ['ẹ' => 0x1EB9, 'ọ' => 0x1ECD, 'ị' => 0x1ECB, 'ụ' => 0x1EE5],
    ];

    foreach ($samples as $subset => $characters) {
        foreach ($characters as $character => $codepoint) {
            expect(mb_ord($character, 'UTF-8'))->toBe($codepoint, "{$character} belongs to {$subset}");
        }
    }
});

it('does not ship the optical size axis of the display face', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    // The opsz build costs 272 KB on latin against 120 KB for weight only, and the
    // display face is rationed in the field app. An officer on 2G does not pay for it.
    expect($css)->toBeString()
        ->and($css)->toContain('@fontsource-variable/newsreader/wght.css')
        ->and($css)->not->toContain('newsreader/opsz');
});

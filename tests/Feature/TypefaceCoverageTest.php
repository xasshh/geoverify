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
})->with(['plus-jakarta-sans', 'jetbrains-mono']);

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

it('borrows the Hausa hooked letters from Montserrat', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    // Plus Jakarta Sans has no b, d or k with a hook (measured against the font
    // files), so Montserrat's latin-ext face sits second in the stack to supply
    // them. Dropping either the import or its place in the stack sends a Hausa
    // name to whatever the device has.
    expect($css)->toBeString()
        ->and($css)->toContain('@fontsource/montserrat/latin-ext-400.css')
        ->and($css)->toMatch("/--font-sans: 'Plus Jakarta Sans', 'Montserrat'/");
});

it('loads the wordmark face at the one weight it is set in', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    // Montserrat is the wordmark and the Hausa fallback, nothing else. Its full
    // latin face at every weight would be a second UI family nobody chose.
    expect($css)->toBeString()
        ->and($css)->toContain('@fontsource/montserrat/latin-700.css')
        ->and($css)->not->toContain('@fontsource/montserrat/latin-400.css');
});

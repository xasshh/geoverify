<?php

declare(strict_types=1);

use App\Domain\Party\Actions\PartyCode;

/*
|--------------------------------------------------------------------------
| The party code
|--------------------------------------------------------------------------
|
| The code is the accountability spine: it follows a party across listings,
| orders, payments and disputes. A typo that resolves to a different party is
| therefore not a validation nicety, and the two error classes a check
| character exists to catch are proven here rather than asserted in a comment.
|
| No database. The code is arithmetic.
|
*/

function code(): PartyCode
{
    return new PartyCode;
}

it('issues a code in the shape a person can read down a phone line', function () {
    $issued = code()->issue();

    expect($issued)->toMatch('/^NBD-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]$/')
        ->and(code()->isValid($issued))->toBeTrue();
});

it('never emits a character that can be misread for another', function () {
    // A thousand codes is 8,000 characters: enough that a confusable slipping
    // into the alphabet would show up here rather than on a printed receipt.
    //
    // Split on the separators rather than stripping the prefix by substring.
    // N, B and D are all in the generation alphabet, so roughly one code in
    // eight thousand contains a literal "NBD" in its body: a str_replace of the
    // prefix eats that too, the group alignment slides by three, and check
    // characters start leaking into the assertion. That made this test fail on
    // about one run in nine for reasons that had nothing to do with the code.
    $bodies = '';

    for ($i = 0; $i < 1_000; $i++) {
        [, $first, $second] = explode('-', code()->issue());

        // The check character is deliberately not included. It is drawn from
        // the full 36 character alphabet, because ISO 7064 MOD 37,36 is defined
        // over it, and only the eight body characters are ever read aloud
        // without the code being validated first.
        $bodies .= $first.$second;
    }

    expect($bodies)->toHaveLength(8_000);

    foreach (['O', 'I', 'L', '0', '1'] as $confusable) {
        expect($bodies)->not->toContain($confusable, "the body alphabet contains {$confusable}");
    }
});

it('validates every code it issues', function () {
    // The bug this exists for: the check character used to be drawn from the
    // full 36 character alphabet while normalise() folds O to 0 and I and L to
    // 1. Roughly one code in twelve came back with a check character that had
    // been folded on the way in, disagreed with itself, and was rejected by the
    // system that had just minted it. Two thousand codes puts the odds of that
    // slipping through this test at nothing.
    $issuer = code();

    for ($i = 0; $i < 2_000; $i++) {
        $issued = $issuer->issue();

        expect($issuer->isValid($issued))->toBeTrue("issued code {$issued} did not validate");
    }
});

it('does not eat a body that happens to begin with the prefix', function () {
    // N, B and D are all in the generation alphabet, so about one body in
    // thirty thousand starts with the letters NBD. Stripping the prefix on a
    // match alone took three characters out of those and left normalise()
    // returning something that could never validate.
    $normalised = code()->normalise('NBD-NBDX-Y7K2-9');

    expect($normalised)->toBe('NBDXY7K29')
        // Idempotent, which is what the length check buys: a caller that
        // normalises twice, or normalises an already normalised code, gets the
        // same nine characters both times.
        ->and(code()->normalise($normalised))->toBe($normalised);
});

it('rejects every single character substitution', function () {
    $issued = code()->issue();
    $normalised = code()->normalise($issued);
    $alphabet = str_split('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ');

    $tested = 0;

    foreach (str_split($normalised) as $position => $original) {
        foreach ($alphabet as $replacement) {
            if ($replacement === $original) {
                continue;
            }

            $broken = $normalised;
            $broken[$position] = $replacement;

            // normalise() folds O to 0 and I and L to 1, so a substitution that
            // lands on one of those is the same string as its folded form and
            // is not a distinct test case.
            if (code()->normalise($broken) === $normalised) {
                continue;
            }

            expect(code()->isValid($broken))->toBeFalse(
                "changing position {$position} from {$original} to {$replacement} still validated",
            );

            $tested++;
        }
    }

    // Nine positions against thirty-five replacements, less the folded ones.
    expect($tested)->toBeGreaterThan(280);
});

it('rejects every transposition of two adjacent characters', function () {
    $issued = code()->issue();
    $normalised = code()->normalise($issued);

    $tested = 0;

    for ($i = 0; $i < strlen($normalised) - 1; $i++) {
        if ($normalised[$i] === $normalised[$i + 1]) {
            // Swapping a character with itself is not an error anyone makes.
            continue;
        }

        $swapped = $normalised;
        [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];

        expect(code()->isValid($swapped))->toBeFalse(
            "transposing positions {$i} and ".($i + 1).' still validated',
        );

        $tested++;
    }

    expect($tested)->toBeGreaterThan(4);
});

it('reads a code back however a person happens to type it', function () {
    $issued = code()->issue();

    $spellings = [
        $issued,
        mb_strtolower($issued),
        str_replace('-', '', $issued),
        str_replace('-', ' ', $issued),
        '  '.$issued.'  ',
        str_replace('NBD-', '', $issued),
    ];

    foreach ($spellings as $spelling) {
        expect(code()->isValid($spelling))->toBeTrue("'{$spelling}' was rejected");
    }
});

it('folds the confusable characters back rather than failing on them', function () {
    $issued = code()->issue();
    $normalised = code()->normalise($issued);

    // Nothing we emit contains 0, 1, O, I or L. Someone transcribing by hand
    // writes what they see, and what they see for our 0-less alphabet is
    // sometimes an O. Folding is what makes that recoverable.
    expect(code()->normalise('NBD-'.strtr($normalised, ['0' => 'O', '1' => 'I'])))
        ->toBe($normalised);
});

it('encodes nothing: two codes issued together share no structure', function () {
    $first = code()->normalise(code()->issue());
    $second = code()->normalise(code()->issue());

    expect($first)->not->toBe($second);

    // A sequence would show as a shared prefix across issues. Random will
    // collide on a character or two by chance; a counter collides on seven.
    $shared = 0;

    for ($i = 0; $i < 8; $i++) {
        if ($first[$i] === $second[$i]) {
            $shared++;
        }
    }

    expect($shared)->toBeLessThan(6);
});

it('refuses a code that is the wrong length or the wrong alphabet', function () {
    foreach (['', 'NBD', 'NBD-7K4M-2XP9', 'NBD-7K4M-2XP9-RR', 'NBD-7K4M-2XP!-R'] as $malformed) {
        expect(code()->isValid($malformed))->toBeFalse("'{$malformed}' validated");
    }
});

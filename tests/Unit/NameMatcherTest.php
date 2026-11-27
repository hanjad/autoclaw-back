<?php

use App\Support\NameMatcher;

it('compares distinct meaningful name tokens', function (?string $first, ?string $second, bool $matches) {
    expect(NameMatcher::matches($first, $second))->toBe($matches);
})->with([
    'case, accents, punctuation and order' => ['Adé-Okafor', 'OKAFOR ADE', true],
    'legal and industry suffixes' => ['Eko Sunrise Auto Parts Ltd', 'Sunrise Eko Nigeria Plc', true],
    'extra middle name' => ['Ada Chika Okafor', 'Okafor Ada', true],
    'one distinctive name on a side' => ['Sunrise Ltd', 'Eko Sunrise Plc', true],
    'only one of two meaningful tokens' => ['Eko Sunrise Ltd', 'Eko Sunset Ltd', false],
    'repeated token does not count twice' => ['Ada Ada Nwosu', 'Ada Ada Okafor', false],
    'initials and generic words only' => ['A. Auto Parts Ltd', 'A. Auto Parts Ltd', false],
    'missing name' => [null, 'Ada Okafor', false],
]);

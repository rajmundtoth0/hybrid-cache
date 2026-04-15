<?php

declare(strict_types=1);

use rajmundtoth0\HybridCache\Utils\KeyNormalizer;

enum StringBackedNormalizerKey: string
{
    case Users = 'users:key';
}

enum IntBackedNormalizerKey: int
{
    case Version = 7;
}

enum UnitNormalizerKey
{
    case Posts;
}

it('normalizes strings and enum keys to strings', function (): void {
    expect(KeyNormalizer::normalize('plain-key'))->toBe('plain-key')
        ->and(KeyNormalizer::normalize(StringBackedNormalizerKey::Users))->toBe('users:key')
        ->and(KeyNormalizer::normalize(IntBackedNormalizerKey::Version))->toBe('7')
        ->and(KeyNormalizer::normalize(UnitNormalizerKey::Posts))->toBe('Posts');
});

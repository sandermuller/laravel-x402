<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use X402\Laravel\Support\ConfigReader;

/**
 * @param  array<string, mixed>  $items
 */
function repo(array $items = []): Repository
{
    return new Repository($items);
}

it('reads a string value', function (): void {
    expect(ConfigReader::string(repo(['x' => 'value']), 'x'))->toBe('value');
});

it('returns the default when the key is absent', function (): void {
    expect(ConfigReader::string(repo(), 'missing', 'fallback'))->toBe('fallback');
});

it('coerces scalar non-string config values to string', function (): void {
    expect(ConfigReader::string(repo(['n' => 42]), 'n'))->toBe('42')
        ->and(ConfigReader::string(repo(['f' => 1.5]), 'f'))->toBe('1.5')
        ->and(ConfigReader::string(repo(['b' => true]), 'b'))->toBe('1');
});

it('returns the default when a non-scalar slips through', function (): void {
    expect(ConfigReader::string(repo(['arr' => ['a']]), 'arr', 'fallback'))->toBe('fallback');
});

it('returns null for stringOrNull on missing or non-string keys', function (): void {
    expect(ConfigReader::stringOrNull(repo(), 'x'))->toBeNull()
        ->and(ConfigReader::stringOrNull(repo(['x' => 42]), 'x'))->toBeNull()
        ->and(ConfigReader::stringOrNull(repo(['x' => 'hello']), 'x'))->toBe('hello');
});

it('reads int values directly and coerces numeric strings', function (): void {
    expect(ConfigReader::int(repo(['t' => 60]), 't'))->toBe(60)
        ->and(ConfigReader::int(repo(['t' => '60']), 't'))->toBe(60)
        ->and(ConfigReader::int(repo(['t' => '-1']), 't'))->toBe(-1);
});

it('returns the int default for missing or non-numeric values', function (): void {
    expect(ConfigReader::int(repo(), 't', 99))->toBe(99)
        ->and(ConfigReader::int(repo(['t' => 'sixty']), 't', 99))->toBe(99);
});

it('reads array values', function (): void {
    expect(ConfigReader::array(repo(['x' => ['a' => 1]]), 'x'))->toBe(['a' => 1]);
});

it('returns empty array for missing or non-array values', function (): void {
    expect(ConfigReader::array(repo(), 'x'))
        ->toBeEmpty()
        ->and(ConfigReader::array(repo(['x' => 'not array']), 'x'))
        ->toBeEmpty();
});

it('reads a list of strings, preserving null vs empty distinction', function (): void {
    expect(ConfigReader::stringListOrNull(repo(), 'x'))->toBeNull()
        ->and(ConfigReader::stringListOrNull(repo(['x' => 'scalar']), 'x'))->toBeNull()
        ->and(ConfigReader::stringListOrNull(repo(['x' => []]), 'x'))
        ->toBeEmpty()
        ->and(ConfigReader::stringListOrNull(repo(['x' => ['a', 'b']]), 'x'))->toBe(['a', 'b'])
        ->and(ConfigReader::stringListOrNull(repo(['x' => ['a', 1, null, 'b']]), 'x'))->toBe(['a', 'b']);
});

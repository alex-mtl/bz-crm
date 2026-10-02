<?php

declare(strict_types=1);

namespace App\Domain\Geo;

/**
 * One street — one spelling (ФО §6.11: «без дублей "ул. Ленина" / "улица Ленина"»). The kind of the street is
 * taken out of the typed text whatever way it is written or abbreviated, in Romanian, Russian or English; what
 * is left is compared without case, diacritics and punctuation. Scripts are not mixed: a Cyrillic and a Latin
 * spelling of one street stay two entries until a person merges them.
 */
final class AddressNormalizer
{
    /** @var array<string, list<string>> street type code => the words that mean it (lower case, no dots) */
    private const array TYPE_WORDS = [
        'boulevard' => ['bd', 'bul', 'bulevard', 'bulevardul', 'boulevard', 'blvd', 'б-р', 'бул', 'бульвар', 'пр', 'пр-т', 'просп', 'проспект'],
        'lane' => ['str-la', 'stradela', 'stradelă', 'lane', 'пер', 'переулок'],
        'road' => ['sos', 'șos', 'şos', 'sosea', 'șosea', 'soseaua', 'șoseaua', 'road', 'ш', 'шоссе'],
        'square' => ['p-ta', 'p-ța', 'piata', 'piața', 'piaţa', 'square', 'пл', 'площадь'],
        'alley' => ['al', 'alee', 'aleea', 'alley', 'аллея'],
        'way' => ['cal', 'cale', 'calea', 'way', 'проезд'],
        'street' => ['str', 'strada', 'stradă', 'street', 'st', 'ул', 'улица'],
    ];

    private const array DIACRITICS = [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'ё' => 'е',
    ];

    public const string DEFAULT_TYPE = 'street';

    /**
     * @return array{type: string, name: string, key: string} the kind, the name as typed (without the kind), the key to compare by
     */
    public function street(string $typed): array
    {
        $words = preg_split('/\s+/u', trim($typed), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $type = self::DEFAULT_TYPE;

        // The kind stands first ("str. Ismail") or last ("Ленина ул.").
        foreach ([0, count($words) - 1] as $position) {
            if (count($words) < 2 || ! isset($words[$position])) {
                continue;
            }
            $found = $this->typeOf($words[$position]);
            if ($found !== null) {
                $type = $found;
                unset($words[$position]);
                $words = array_values($words);
                break;
            }
        }

        $name = trim(implode(' ', $words), " \t,.;");

        return ['type' => $type, 'name' => $name, 'key' => $this->key($name)];
    }

    /**
     * "12 a" and "nr. 12A" are one number.
     */
    public function number(string $typed): string
    {
        $number = mb_strtoupper(trim($typed));
        $number = (string) preg_replace('/^(NR|№|N)\.?\s*/u', '', $number);

        return (string) preg_replace('/[\s.]+/u', '', $number);
    }

    public function key(string $name): string
    {
        $key = strtr(mb_strtolower($name), self::DIACRITICS);
        $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key);

        return trim((string) preg_replace('/\s+/u', ' ', $key));
    }

    private function typeOf(string $word): ?string
    {
        $word = rtrim(mb_strtolower($word), '.,');
        foreach (self::TYPE_WORDS as $type => $words) {
            if (in_array($word, $words, true)) {
                return $type;
            }
        }

        return null;
    }
}

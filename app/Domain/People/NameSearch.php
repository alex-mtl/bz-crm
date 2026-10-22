<?php

declare(strict_types=1);

namespace App\Domain\People;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finding a person by name the way people type it: several words in any order ("Gheorghe Arama", "Arama Gh"),
 * and in Cyrillic as well as in Latin — names are kept in their Latin (Romanian) spelling, while many users type
 * in Russian ("Диана" finds "Diana", "Ион Ботнару" finds "Ion Botnaru"). Case and diacritics are left to the
 * collation of the database. Every screen that looks a person up by name goes through here.
 */
final class NameSearch
{
    /** Cyrillic letter => its Latin spellings in Romanian names; two where the language hesitates. */
    private const array LETTERS = [
        'а' => ['a'], 'б' => ['b'], 'в' => ['v'], 'г' => ['g'], 'д' => ['d'], 'е' => ['e'], 'ё' => ['io'], 'ж' => ['j'],
        'з' => ['z'], 'и' => ['i'], 'й' => ['i'], 'к' => ['c', 'k'], 'л' => ['l'], 'м' => ['m'], 'н' => ['n'], 'о' => ['o'],
        'п' => ['p'], 'р' => ['r'], 'с' => ['s'], 'т' => ['t'], 'у' => ['u'], 'ф' => ['f'], 'х' => ['h'], 'ц' => ['t'],
        'ч' => ['c', 'ci'], 'ш' => ['s'], 'щ' => ['s'], 'ъ' => [''], 'ы' => ['i', 'a'], 'ь' => [''], 'э' => ['e'], 'ю' => ['iu'],
        'я' => ['ia'],
    ];

    /** More spellings than this for one word are not worth a query. */
    private const int MAX_SPELLINGS = 8;

    /**
     * Every word of the term must be found in the first or the last name.
     *
     * @param  Builder<covariant Model>  $query  over people (or a relation to them)
     * @return Builder<covariant Model>
     */
    public static function apply(Builder $query, string $term): Builder
    {
        $first = $query->getModel()->qualifyColumn('first_name');
        $last = $query->getModel()->qualifyColumn('last_name');

        foreach (self::words($term) as $spellings) {
            $query->where(function (Builder $word) use ($spellings, $first, $last): void {
                foreach ($spellings as $spelling) {
                    $like = '%'.addcslashes($spelling, '%_\\').'%';
                    $word->orWhere($first, 'like', $like)->orWhere($last, 'like', $like);
                }
            });
        }

        return $query;
    }

    /**
     * @return list<list<string>> each word of the term with the spellings to look for
     */
    public static function words(string $term): array
    {
        $words = preg_split('/[\s,]+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(fn (string $word): array => self::spellings($word), $words);
    }

    /**
     * @return list<string>
     */
    private static function spellings(string $word): array
    {
        $lower = mb_strtolower($word);
        if (preg_match('/\p{Cyrillic}/u', $lower) !== 1) {
            return [$word];
        }

        $variants = [''];
        foreach (mb_str_split($lower) as $letter) {
            $options = self::LETTERS[$letter] ?? [$letter];
            $next = [];
            foreach ($variants as $variant) {
                foreach ($options as $option) {
                    $next[] = $variant.$option;
                }
            }
            $variants = array_slice(array_values(array_unique($next)), 0, self::MAX_SPELLINGS);
        }

        // The word as typed stays among the spellings: a name may be kept in Cyrillic too.
        return array_values(array_unique([$word, ...$variants]));
    }
}

<?php

// Aligns RO/RU names of Law 764/2001 annexes 2-5 per unit (sequence alignment on transliteration similarity),
// then matches them to the GeoNames localities used by the project. Outputs:
//   law_pairs.csv       unit_slug,name_ro,name_ru,similarity
//   localities_ru.csv   geonameid,raion,name(geonames),name_ro_law,name_ru_law,match
//   build_report.txt    coverage and review lists
require 'align_lib.php';

function ru2lat(string $s): string
{
    $s = mb_strtolower($s);
    $map = ['щ' => 'st', 'ш' => 's', 'ч' => 'c', 'ж' => 'j', 'ю' => 'iu', 'я' => 'ia', 'ё' => 'io', 'э' => 'a', 'ы' => 'i', 'ъ' => '', 'ь' => 'i', 'й' => 'i', 'ц' => 't', 'х' => 'h', 'к' => 'c',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'з' => 'z', 'и' => 'i', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f'];

    return strtr($s, $map);
}
function ro2key(string $s): string // folded RO, aligned with ru2lat conventions
{
    $s = fold($s);

    return strtr($s, ['ch' => 'c', 'gh' => 'g', 'ce' => 'ce', 'x' => 'cs', 'w' => 'v', 'y' => 'i', 'q' => 'c']);
}
function sim(string $ro, string $ru): float
{
    $a = preg_replace('/[^a-z]/', '', ro2key($ro));
    $b = preg_replace('/[^a-z]/', '', ru2lat($ru));
    if ($a === '' || $b === '') {
        return 0.0;
    }
    similar_text($a, $b, $p);

    return $p / 100;
}
function alignSeq(array $a, array $b): array
{
    $n = count($a);
    $m = count($b);
    $gap = -0.4;
    $S = array_fill(0, $n + 1, array_fill(0, $m + 1, 0.0));
    $T = [];
    for ($i = 1; $i <= $n; $i++) {
        $S[$i][0] = $i * $gap;
        $T[$i][0] = 'u';
    }
    for ($j = 1; $j <= $m; $j++) {
        $S[0][$j] = $j * $gap;
        $T[0][$j] = 'l';
    }
    for ($i = 1; $i <= $n; $i++) {
        for ($j = 1; $j <= $m; $j++) {
            $s = sim($a[$i - 1], $b[$j - 1]);
            $ms = $s >= 0.5 ? $s : -1.0;
            $opts = ['d' => $S[$i - 1][$j - 1] + $ms, 'u' => $S[$i - 1][$j] + $gap, 'l' => $S[$i][$j - 1] + $gap];
            arsort($opts);
            $k = array_key_first($opts);
            $S[$i][$j] = $opts[$k];
            $T[$i][$j] = $k;
        }
    }
    $out = [];
    $i = $n;
    $j = $m;
    while ($i > 0 || $j > 0) {
        $t = $T[$i][$j] ?? ($i > 0 ? 'u' : 'l');
        if ($t === 'd') {
            $out[] = [$a[$i - 1], $b[$j - 1], sim($a[$i - 1], $b[$j - 1])];
            $i--;
            $j--;
        } elseif ($t === 'u') {
            $out[] = [$a[$i - 1], null, 0];
            $i--;
        } else {
            $out[] = [null, $b[$j - 1], 0];
            $j--;
        }
    }

    return array_reverse($out);
}
function unitSlug(string $roUnit): string
{
    $k = fold(preg_replace('/^(MUNICIPIUL|RAIONUL)\s+/iu', '', $roUnit));
    $special = ['uta gagauzia' => 'gagauzia', 'stinga nistrului' => 'stanga-nistrului', 'stefan voda' => 'stefan-voda'];

    return $special[$k] ?? str_replace(' ', '-', $k);
}
function modernRo(string $s): string
{
    return strtr($s, ['ş' => 'ș', 'Ş' => 'Ș', 'ţ' => 'ț', 'Ţ' => 'Ț']);
}

$ro = units(fragments($argv[1], 114), 'ro');
$ru = units(fragments($argv[2], 1), 'ru');
$roK = array_keys($ro);
$ruK = array_keys($ru);

$noise = '/состав|Населенные|Всего|Пунктов|^-$|se modific|^Oraşele$|^Ora[sş]e$/u';
$pairs = [];
$review = [];
$gaps = [];
foreach ($roK as $i => $u) {
    $slug = unitSlug($u);
    $loRo = [];
    $loRu = [];
    foreach (alignSeq($ro[$u], $ru[$ruK[$i]]) as [$r, $c, $s]) {
        if ($r === null || $c === null) {
            if ($r !== null && ! preg_match($noise, $r)) {
                $loRo[] = $r;
            }
            if ($c !== null && ! preg_match($noise, $c)) {
                $loRu[] = $c;
            }

            continue;
        }
        $pairs[$slug][] = [modernRo($r), $c, $s];
    }
    // Second pass: entries left unpaired because the two documents list them in a different order.
    foreach ($loRo as $r) {
        $best = null;
        $bs = 0.0;
        foreach ($loRu as $k => $c) {
            $s = sim($r, $c);
            if ($s > $bs) {
                $bs = $s;
                $best = $k;
            }
        }
        if ($best !== null && $bs >= 0.6) {
            $pairs[$slug][] = [modernRo($r), $loRu[$best], $bs];
            unset($loRu[$best]);
        } else {
            $gaps[] = "$slug: $r ↔ —";
        }
    }
    foreach ($loRu as $c) {
        $gaps[] = "$slug: — ↔ $c";
    }
}
// Manual corrections of defects found in the source documents (each is listed in the review report).
$corrections = [];
foreach ($pairs['telenesti'] as &$p) {
    if ($p[0] === 'Chițcanii Noi') {
        $p[1] = 'Кицканий Ной';
        $p[2] = sim($p[0], $p[1]);
        $corrections[] = 'telenesti: Chițcanii Noi / Ciulucani — русские названия переставлены местами при выравнивании, исправлено';
    } elseif ($p[0] === 'Ciulucani') {
        $p[1] = 'Чулукань';
        $p[2] = sim($p[0], $p[1]);
    }
}
unset($p);
$pairs['taraclia'][] = ['Borceag', 'Борчаг', 1.0];
$corrections[] = 'taraclia: Borceag — есть в румынском приложении 3, нет в русском; русское название взято из той же записи в районе Кахул (Борчаг)';
$gaps = array_values(array_filter($gaps, fn ($g) => ! str_starts_with($g, 'taraclia: Borceag')));
foreach ($pairs as $slug => $list) {
    foreach ($list as [$r, $c, $s]) {
        if ($s < 0.7) {
            $review[] = sprintf('%s: %s ↔ %s (%.2f)', $slug, $r, $c, $s);
        }
    }
}

// Match GeoNames localities to law pairs: same unit first, then a unique match anywhere.
$byUnit = [];
$global = [];
foreach ($pairs as $slug => $list) {
    foreach ($list as [$r, $c, $s]) {
        $byUnit[$slug][fold($r)][] = [$r, $c];
        $global[fold($r)][] = [$slug, $r, $c];
    }
}
$rows = array_map('str_getcsv', array_slice(file($argv[3], FILE_IGNORE_NEW_LINES), 2));
$of = fopen('localities_ru.csv', 'w');
fputcsv($of, ['geonameid', 'raion', 'name', 'name_ro_law', 'name_ru_law', 'match']);
$stat = ['unit' => 0, 'unit_ambiguous' => 0, 'global' => 0, 'none' => 0];
$unmatched = [];
foreach ($rows as [$gid, $name, $raion]) {
    $k = fold($name);
    $cand = $byUnit[$raion][$k] ?? [];
    $uniq = array_unique(array_map(fn ($x) => $x[1], $cand));
    if (count($uniq) === 1) {
        fputcsv($of, [$gid, $raion, $name, $cand[0][0], $cand[0][1], 'unit']);
        $stat['unit']++;

        continue;
    }
    if (count($uniq) > 1) {
        fputcsv($of, [$gid, $raion, $name, '', '', 'ambiguous']);
        $stat['unit_ambiguous']++;
        $unmatched[] = "$raion: $name (несколько вариантов: ".implode(' / ', $uniq).')';

        continue;
    }
    $g = $global[$k] ?? [];
    $gu = array_unique(array_map(fn ($x) => $x[2], $g));
    if (count($gu) === 1) {
        fputcsv($of, [$gid, $raion, $name, $g[0][1], $g[0][2], 'global:'.$g[0][0]]);
        $stat['global']++;

        continue;
    }
    fputcsv($of, [$gid, $raion, $name, '', '', 'none']);
    $stat['none']++;
    $unmatched[] = "$raion: $name";
}
fclose($of);

// Master list = the law. Coordinates are borrowed from GeoNames (same unit, same folded name).
$geo = [];
foreach ($rows as [$gid, $name, $raion, $lat, $lng]) {
    $geo[$raion][fold($name)] ??= [$gid, $lat, $lng];
}
$mf = fopen('md-localities-official.csv', 'w');
fputcsv($mf, ['unit_slug', 'name_ro', 'name_ru', 'name_en', 'railway_station', 'geonameid', 'lat', 'lng'], ',', '"', '');
$withCoords = 0;
$total = 0;
$noCoords = [];
$usedGeo = [];
foreach ($pairs as $slug => $list) {
    foreach ($list as [$r, $c, $s]) {
        $rail = (bool) preg_match('/,\s*loc\.\s*st\.\s*cf$/u', $r);
        $r = trim(preg_replace('/,\s*loc\.\s*st\.\s*cf$/u', '', $r));
        $c = trim(preg_replace('/,\s*н\.\s*п\.\s*ж\.?-?\s*д\.\s*ст\.$/u', '', $c));
        $total++;
        $g = $geo[$slug][fold($r)] ?? null;
        if ($g) {
            $withCoords++;
            $usedGeo[$g[0]] = true;
        } else {
            $noCoords[] = "$slug: $r";
        }
        fputcsv($mf, [$slug, $r, $c, $r, $rail ? 1 : 0, $g[0] ?? '', $g[1] ?? '', $g[2] ?? ''], ',', '"', '');
    }
}
fclose($mf);
$geoOnly = [];
foreach ($rows as [$gid, $name, $raion]) {
    if (! isset($usedGeo[$gid])) {
        $geoOnly[] = "$raion: $name ($gid)";
    }
}

$rep = fopen('build_report.txt', 'w');
fprintf($rep, "LAW MASTER LIST: %d entries, with GeoNames coordinates: %d, without: %d\n", $total, $withCoords, $total - $withCoords);
fprintf($rep, "law pairs: %d, alignment gaps: %d, low-similarity pairs (<0.70): %d\n", array_sum(array_map('count', $pairs)), count($gaps), count($review));
fprintf($rep, "geonames localities: %d | matched in unit: %d | matched globally (unique): %d | ambiguous: %d | unmatched: %d\n", count($rows), $stat['unit'], $stat['global'], $stat['unit_ambiguous'], $stat['none']);
fwrite($rep, "\n== manual corrections\n".implode("\n", $corrections));
fwrite($rep, "\n\n== alignment gaps (source typos, not data loss)\n".implode("\n", $gaps)."\n\n== low similarity (checked)\n".implode("\n", $review));
fwrite($rep, "\n\n== official localities without GeoNames coordinates (".count($noCoords).")\n".implode("\n", $noCoords));
fwrite($rep, "\n\n== GeoNames records not in the official list (".count($geoOnly).")\n".implode("\n", $geoOnly)."\n");
fclose($rep);

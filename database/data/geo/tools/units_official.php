<?php

// Switches md-adm1-units.csv to official names (Д-9): name_ru = official (Law 764/2001),
// traditional Russian form kept only as a search alias where it differs.
$in = fopen($argv[1], 'r');
$head = fgetcsv($in, null, ',', '"', '');
$rows = [];
while ($r = fgetcsv($in, null, ',', '"', '')) {
    $rows[] = array_combine($head, $r);
}
$out = fopen($argv[2], 'w');
fputcsv($out, ['slug', 'iso', 'macro_region', 'name_ro', 'name_ru', 'name_en', 'search_aliases'], ',', '"', '');
$aliases = 0;
foreach ($rows as $r) {
    $nameRo = $r['slug'] === 'bender' ? 'Bender' : $r['name_ro'];
    $alias = mb_strtolower($r['name_ru']) !== mb_strtolower($r['name_ru_official']) ? $r['name_ru'] : '';
    if ($r['slug'] === 'bender') {
        $alias = trim($alias.'|Tighina|Тигина', '|');
    }
    if ($alias !== '') {
        $aliases++;
    }
    fputcsv($out, [$r['slug'], $r['iso'], $r['macro_region'], $nameRo, $r['name_ru_official'], $r['name_en'], $alias], ',', '"', '');
}
fwrite(STDERR, count($rows)." units, $aliases with search aliases\n");

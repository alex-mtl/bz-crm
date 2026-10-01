<?php

// Rebuilds md-adm1-units.csv: adds macro_region (Law 438/2006 annex), official Russian name (Law 764/2001),
// and English names in official Romanian orthography.
require 'align_lib.php';
$macro = [
    'nord' => ['balti', 'briceni', 'donduseni', 'drochia', 'edinet', 'falesti', 'floresti', 'glodeni', 'ocnita', 'riscani', 'singerei', 'soroca'],
    'centru' => ['anenii-noi', 'calarasi', 'criuleni', 'dubasari', 'hincesti', 'ialoveni', 'nisporeni', 'orhei', 'rezina', 'straseni', 'soldanesti', 'telenesti', 'ungheni'],
    'sud' => ['basarabeasca', 'cahul', 'cantemir', 'causeni', 'cimislia', 'leova', 'stefan-voda', 'taraclia'],
    'chisinau' => ['chisinau'],
    'gagauzia' => ['gagauzia'],
    'stanga-nistrului' => ['stanga-nistrului', 'bender'],
];
$unitMacro = [];
foreach ($macro as $m => $list) {
    foreach ($list as $u) {
        $unitMacro[$u] = $m;
    }
}

$ro = array_keys(units(fragments('ro_all.txt', 114), 'ro'));
$ru = array_keys(units(fragments('ru_all.txt', 1), 'ru'));
$special = ['uta gagauzia' => 'gagauzia', 'stinga nistrului' => 'stanga-nistrului', 'stefan voda' => 'stefan-voda'];
$ruOfficial = [];
foreach ($ro as $i => $u) {
    $k = fold(preg_replace('/^(MUNICIPIUL|RAIONUL)\s+/iu', '', $u));
    $slug = $special[$k] ?? str_replace(' ', '-', $k);
    $ruOfficial[$slug] = mb_convert_case(preg_replace('/^(МУНИЦИПИЙ|РАЙОН)\s+/u', '', $ru[$i]), MB_CASE_TITLE);
}
$ruOfficial['gagauzia'] = 'АТО Гагаузия';
$ruOfficial['stanga-nistrului'] = 'Левобережье Днестра';

$enOverride = ['gagauzia' => 'ATU Gagauzia', 'stanga-nistrului' => 'Left Bank of the Dniester', 'bender' => 'Bender'];
$in = fopen('site_units.csv', 'r');
fgetcsv($in, null, ',', '"', '');
$out = fopen('md-adm1-units.csv', 'w');
fputcsv($out, ['slug', 'iso', 'macro_region', 'name_ro', 'name_ru', 'name_ru_official', 'name_en'], ',', '"', '');
$n = 0;
while ($r = fgetcsv($in, null, ',', '"', '')) {
    [$slug, $iso, $nameRo, $nameRu] = $r;
    if (! isset($unitMacro[$slug], $ruOfficial[$slug])) {
        fwrite(STDERR, "missing mapping for $slug\n");
        exit(1);
    }
    fputcsv($out, [$slug, $iso, $unitMacro[$slug], $nameRo, $nameRu, $ruOfficial[$slug], $enOverride[$slug] ?? $nameRo], ',', '"', '');
    $n++;
}
fwrite(STDERR, "units written: $n; macro-region members: ".count($unitMacro)."\n");

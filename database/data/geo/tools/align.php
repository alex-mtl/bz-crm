<?php

// Builds per-unit ordered name lists from the RO (html->txt) and RU (docx->txt) versions of Law 764/2001
// annexes 2-5, aligns them positionally, and prints a report + CSV (unit_ro,unit_ru,idx,name_ro,name_ru).

function fold(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['ş' => 's', 'ș' => 's', 'ţ' => 't', 'ț' => 't', 'ă' => 'a', 'â' => 'i', 'î' => 'i', 'ё' => 'е']);

    return trim(preg_replace('/\s+/u', ' ', $s));
}

function fragments(string $file, int $fromLine): array
{
    $out = [];
    foreach (file($file) as $n => $line) {
        if ($n + 1 < $fromLine) {
            continue;
        }
        $line = rtrim($line, "\r\n");
        [$kind, $rest] = array_pad(explode("\t", $line, 2), 2, '');
        foreach (explode("\t", $rest) as $cell) {
            // Headings glued to the next run in the source docx: "Район XВсего населенных пунктов 39"
            $cell = preg_replace('/(?<=\S)\s*(Всего населенных пунктов|Total localit)/u', ' // $1', $cell);
            foreach (explode(' // ', $cell) as $frag) {
                $frag = trim($frag);
                if ($frag !== '') {
                    $out[] = $frag;
                }
            }
        }
    }

    return $out;
}

function units(array $frags, string $lang): array
{
    $unitRe = $lang === 'ro'
        ? '/^(MUNICIPIUL|RAIONUL)\s+(.+)$/iu'
        : '/^(Муниципий|Район)\s+(.+)$/u';
    $annexRe = $lang === 'ro' ? '/^Anexa nr\.?\s*([45])\b/iu' : '/^Приложение\s+([45])\b/u';
    $skipRo = '/^(total localitati.*|inclusiv.*|orase|municipii|sectoare ale municipiului|localitati din componenta.*|localitatile din componenta.*|sate \(comune\)|sectoarele municipiului.*|nota:.*|anexa nr.*|raioanele|municipiile|si unitatile administrativ.*|unitatile administrativ.*|din componenta .*|din stinga nistrului.*|carora .*|autonome gagauzia|modificat.*|abrogat.*|in redactia.*|\d+)$/u';
    $skipRu = '/^(всего населенных пунктов.*|в том числе.*|городов.*|муниципиев.*|секторов.*|населенных пунктов.*|входящих.*|в состав.*|сел \(коммун\).*|города|муниципии|секторы муниципия.*|села \(коммуны\)|населенные пункты, входящие в их состав|приложение \d+|примечание.*|районы и.*|муниципии и.*|единицы, входящие.*|административно-территориальные.*|входящие в состав.*|образования гагаузия|левобережья днестра.*|предоставлены.*|автономии|изменен.*|ед иницы.*|\d+)$/u';
    $skip = $lang === 'ro' ? $skipRo : $skipRu;

    $units = [];
    $cur = null;
    foreach ($frags as $f) {
        if (preg_match($annexRe, $f, $m)) {
            $cur = $m[1] === '4' ? ($lang === 'ro' ? 'UTA GĂGĂUZIA' : 'АТО ГАГАУЗИЯ') : ($lang === 'ro' ? 'STÎNGA NISTRULUI' : 'ЛЕВОБЕРЕЖЬЕ ДНЕСТРА');
            $units[$cur] ??= [];

            continue;
        }
        if (preg_match($unitRe, $f, $m) && ! in_array(fold($f), ['municipiul chisinau', 'муниципий кишинэу'], true) === false || preg_match($unitRe, $f, $m)) {
            // inside annexes 4/5 municipalities (Comrat, Tiraspol) stay part of the annex unit
            if ($cur !== null && in_array($cur, ['UTA GĂGĂUZIA', 'АТО ГАГАУЗИЯ', 'STÎNGA NISTRULUI', 'ЛЕВОБЕРЕЖЬЕ ДНЕСТРА'], true)) {
                $units[$cur][] = $f;

                continue;
            }
            $cur = mb_strtoupper(trim($m[0]));
            $units[$cur] ??= [];

            continue;
        }
        if ($cur === null) {
            continue;
        }
        if (preg_match($skip, fold($f))) {
            continue;
        }
        $list = &$units[$cur];
        if (! $list || end($list) !== $f) {
            $list[] = $f;
        }
        unset($list);
    }

    return $units;
}

[$ro, $ru] = [units(fragments($argv[1], (int) $argv[2]), 'ro'), units(fragments($argv[3], 1), 'ru')];
$roKeys = array_keys($ro);
$ruKeys = array_keys($ru);
fwrite(STDERR, sprintf("units: ro=%d ru=%d\n", count($roKeys), count($ruKeys)));
$csv = fopen('php://stdout', 'w');
fputcsv($csv, ['unit_ro', 'unit_ru', 'idx', 'name_ro', 'name_ru']);
$bad = 0;
$pairs = 0;
foreach ($roKeys as $i => $uRo) {
    $uRu = $ruKeys[$i] ?? '?';
    $a = $ro[$uRo];
    $b = $ru[$uRu] ?? [];
    $ok = count($a) === count($b);
    if (! $ok) {
        $bad++;
    }
    fwrite(STDERR, sprintf("%s %-28s %-28s ro=%3d ru=%3d\n", $ok ? 'OK ' : 'BAD', $uRo, $uRu, count($a), count($b)));
    if ($ok) {
        foreach ($a as $k => $name) {
            fputcsv($csv, [$uRo, $uRu, $k, $name, $b[$k]]);
            $pairs++;
        }
    }
}
fwrite(STDERR, "mismatched units: $bad, aligned pairs: $pairs\n");

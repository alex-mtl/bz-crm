<?php

// Usage: php docx2txt.php file.docx  -> prints paragraphs; table rows as cells joined by " | "
$zip = new ZipArchive;
if ($zip->open($argv[1]) !== true) {
    fwrite(STDERR, "cannot open\n");
    exit(1);
}
$xml = $zip->getFromName('word/document.xml');
$dom = new DOMDocument;
$dom->loadXML($xml);
$xp = new DOMXPath($dom);
$xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
$text = fn (DOMNode $n) => trim(preg_replace('/\s+/u', ' ', implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($xp->query('.//w:t', $n))))));
foreach ($xp->query('/w:document/w:body/*') as $node) {
    if ($node->localName === 'p') {
        $t = $text($node);
        if ($t !== '') {
            echo "P\t$t\n";
        }
    } elseif ($node->localName === 'tbl') {
        foreach ($xp->query('./w:tr', $node) as $tr) {
            $cells = [];
            foreach ($xp->query('./w:tc', $tr) as $tc) {
                $paras = [];
                foreach ($xp->query('./w:p', $tc) as $p) {
                    $t = $text($p);
                    if ($t !== '') {
                        $paras[] = $t;
                    }
                }
                $cells[] = implode(' // ', $paras);
            }
            echo "R\t".implode("\t", $cells)."\n";
        }
    }
}

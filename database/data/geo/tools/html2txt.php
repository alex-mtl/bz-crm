<?php

// Usage: php html2txt.php file.html -> same format as docx2txt.php (P / R lines), in document order
libxml_use_internal_errors(true);
$html = file_get_contents($argv[1]);
$dom = new DOMDocument;
$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
$clean = fn (string $s) => trim(preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $s));
$walk = function (DOMNode $n) use (&$walk, $clean) {
    foreach ($n->childNodes as $c) {
        if (! $c instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($c->tagName);
        if ($tag === 'table') {
            foreach ((new DOMXPath($c->ownerDocument))->query('.//tr', $c) as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                        $paras = [];
                        foreach ((new DOMXPath($td->ownerDocument))->query('.//p', $td) as $p) {
                            $t = $clean($p->textContent);
                            if ($t !== '') {
                                $paras[] = $t;
                            }
                        }
                        if (! $paras) {
                            $t = $clean($td->textContent);
                            if ($t !== '') {
                                $paras[] = $t;
                            }
                        }
                        $cells[] = implode(' // ', $paras);
                    }
                }
                echo "R\t".implode("\t", $cells)."\n";
            }
        } elseif ($tag === 'p' || preg_match('/^h[1-6]$/', $tag)) {
            $t = $clean($c->textContent);
            if ($t !== '') {
                echo "P\t$t\n";
            }
        } else {
            $walk($c);
        }
    }
};
$walk($dom->documentElement);

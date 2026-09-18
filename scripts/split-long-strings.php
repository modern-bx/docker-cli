<?php

declare(strict_types=1);

$paths = array_merge(
    glob("src/**/*.php"),
    glob("src/*/*.php"),
    glob("src/*/*/*.php"),
    glob("src/*/*/*/*.php"),
    glob("scripts/*.php"),
    glob("tests/*/*/*.php"),
);
$paths = array_unique($paths);
foreach ($paths as $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, sprintf("Не удалось прочитать файл: %s\n", $path));
        exit(1);
    }

    $tokens = token_get_all($source);
    $offset = 0;
    $replacements = [];
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (
            is_array($token) &&
            $token[0] === T_CONSTANT_ENCAPSED_STRING &&
            strlen($text) > 100 &&
            !str_contains($text, "\\n")
        ) {
            $quote = $text[0];
            $body = substr($text, 1, -1);
            $words = preg_split("/(?<= )/", $body, -1, PREG_SPLIT_NO_EMPTY);
            if (count($words) > 1) {
                $parts = [];
                $part = "";
                foreach ($words as $word) {
                    if ($part !== "" && strlen($part . $word) > 80) {
                        $parts[] = $part;
                        $part = "";
                    }
                    $part .= $word;
                }
                if ($part !== "") {
                    $parts[] = $part;
                }
                $lineStart = strrpos(substr($source, 0, $offset), "\n");
                $prefix = substr(
                    $source,
                    $lineStart === false ? 0 : $lineStart + 1,
                    $offset - ($lineStart === false ? 0 : $lineStart + 1),
                );
                $indent = preg_match("/^\s*/", $prefix, $m) ? $m[0] . "    " : "    ";
                $replacement = implode(
                    " .\n" . $indent,
                    array_map(static fn(string $part): string => $quote . $part . $quote, $parts),
                );
                $replacements[] = [$offset, strlen($text), $replacement];
            }
        }
        $offset += strlen($text);
    }
    foreach (array_reverse($replacements) as [$start, $length, $replacement]) {
        $source = substr_replace($source, $replacement, $start, $length);
    }
    file_put_contents($path, $source);
}

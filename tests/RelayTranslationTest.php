<?php

declare(strict_types=1);

function translationFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function relaySourceStrings(string $source): array
{
    $tokens = token_get_all($source);
    $strings = [];
    $tokenCount = count($tokens);

    for ($i = 0; $i < $tokenCount; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_OBJECT_OPERATOR) continue;

        $cursor = $i + 1;
        while ($cursor < $tokenCount && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) $cursor++;
        if ($cursor >= $tokenCount || !is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_STRING) continue;

        $method = $tokens[$cursor][1];
        if ($method !== '_' && $method !== '_n') continue;
        while ($cursor < $tokenCount && $tokens[$cursor] !== '(') $cursor++;
        if ($cursor >= $tokenCount) continue;

        $depth = 0;
        $literals = [];
        for (; $cursor < $tokenCount; $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                if ($depth === 0) break;
                continue;
            }
            if ($depth !== 1 || !is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) continue;

            $quote = $token[1][0];
            $literal = substr($token[1], 1, -1);
            $literal = $quote === "'"
                ? str_replace(["\\\\", "\\'"], ["\\", "'"], $literal)
                : stripcslashes($literal);
            $literals[] = $literal;
            if ($method === '_' || count($literals) === 2) break;
        }

        foreach ($literals as $literal) $strings[$literal] = true;
    }

    ksort($strings, SORT_NATURAL | SORT_FLAG_CASE);
    return array_keys($strings);
}

function placeholders(string $value): array
{
    preg_match_all('/%(?:\d+\$)?[sd]|\{[a-z_]+\}/i', $value, $matches);
    sort($matches[0]);
    return $matches[0];
}

$root = dirname(__DIR__);
$moduleFile = $root . '/Relay.module.php';
$expectedFile = 'site/modules/Relay/Relay.module.php';
$traitFiles = glob($root . '/src/Traits/*.php') ?: [];
sort($traitFiles);
$compositionSource = (string)file_get_contents($moduleFile);
$traitSources = [];
foreach ($traitFiles as $traitFile) $traitSources[basename($traitFile)] = (string)file_get_contents($traitFile);
$source = $compositionSource
    . "\n"
    . implode("\n", $traitSources);
$coverageSource = $compositionSource . "\n" . implode("\n", array_diff_key($traitSources, [
    'RelaySchedulingRulesTrait.php' => true,
    'RelayCalendarFeedTrait.php' => true,
]));
$coverageSourceStrings = relaySourceStrings($coverageSource);
$sourceStrings = relaySourceStrings($source);
$expectedLanguages = [
    'German' => 'de',
    'Albanian' => 'sq', 'Armenian' => 'hy', 'Azerbaijani' => 'az', 'Basque' => 'eu',
    'Belarusian' => 'be', 'Bosnian' => 'bs', 'Bulgarian' => 'bg', 'Catalan' => 'ca',
    'Corsican' => 'co', 'Croatian' => 'hr', 'Czech' => 'cs', 'Danish' => 'da',
    'Dutch' => 'nl', 'Estonian' => 'et', 'Faroese' => 'fo', 'Finnish' => 'fi',
    'French' => 'fr', 'Frisian' => 'fy', 'Galician' => 'gl', 'Georgian' => 'ka',
    'Greek' => 'el', 'Hungarian' => 'hu', 'Icelandic' => 'is', 'Irish' => 'ga',
    'Italian' => 'it', 'Latin' => 'la', 'Latvian' => 'lv', 'Lithuanian' => 'lt',
    'Luxembourgish' => 'lb', 'Macedonian' => 'mk', 'Maltese' => 'mt', 'Norwegian' => 'no',
    'Polish' => 'pl', 'Portuguese' => 'pt', 'Romanian' => 'ro', 'Romansh' => 'rm',
    'Russian' => 'ru',
    'ScottishGaelic' => 'gd', 'Serbian' => 'sr', 'Slovak' => 'sk', 'Slovenian' => 'sl',
    'Spanish' => 'es', 'Swedish' => 'sv', 'Turkish' => 'tr', 'Ukrainian' => 'uk',
    'Welsh' => 'cy', 'Yiddish' => 'yi',
];
$technicalTokens = [
    '--execute', '/relay-api/v1/', 'relay-view', 'relay-run-as', 'relay-manage',
    'relay-api', 'bin/relay-interface', 'bin/relay.php', '@BotFather',
    'LazyCron::everyMinute', 'SHA-256', 'CORS', 'CSRF', 'REST', 'JSON', 'API', 'CLI',
    'PHP', 'UTC', 'WireMail', 'TeleWire', 'Squad', 'Telegram', 'ProcessWire',
];

$actualFiles = glob($root . '/languages/*.csv') ?: [];
$actualNames = array_map(static fn(string $file): string => basename($file, '.csv'), $actualFiles);
sort($actualNames);
$expectedNames = array_keys($expectedLanguages);
sort($expectedNames);
if ($actualNames !== $expectedNames) translationFail('The bundled European language file set is incomplete or contains an unexpected file.');

$totalTranslations = 0;
foreach ($expectedLanguages as $languageName => $languageCode) {
    $languageFile = $root . '/languages/' . $languageName . '.csv';
    $handle = fopen($languageFile, 'rb');
    if ($handle === false) translationFail("{$languageName}.csv is missing.");
    $header = fgetcsv($handle, 0, ',', '"', '');
    if ($header !== ['en', $languageCode, 'description', 'file', 'hash']) translationFail("{$languageName}.csv has an invalid header.");

    $translations = [];
    $changed = 0;
    $line = 1;
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $line++;
        if (count($row) !== 5) translationFail("{$languageName}.csv line {$line} must contain five columns.");
        [$english, $translation, , $file, $hash] = $row;
        if ($english === '' || $translation === '') translationFail("{$languageName}.csv line {$line} contains an empty source or translation.");
        if (isset($translations[$english])) translationFail("{$languageName}.csv contains duplicate source text: {$english}");
        if ($file !== $expectedFile) translationFail("{$languageName}.csv line {$line} points to the wrong source file.");
        if ($hash !== md5($english)) translationFail("{$languageName}.csv line {$line} has an invalid ProcessWire hash.");
        if (preg_match('//u', $translation) !== 1) translationFail("{$languageName}.csv line {$line} is not valid UTF-8.");
        if (preg_match('/(.)\\1{9,}/u', $translation) === 1) translationFail("{$languageName}.csv line {$line} contains repeated-character translation noise.");
        if (placeholders($english) !== placeholders($translation)) translationFail("{$languageName} changes placeholders for: {$english}");
        foreach ($technicalTokens as $token) {
            if (str_contains($english, $token) && !str_contains($translation, $token)) {
                translationFail("{$languageName} drops technical token {$token} for: {$english}");
            }
        }
        if ($english !== $translation) $changed++;
        $translations[$english] = $translation;
    }
    fclose($handle);

    $missing = array_values(array_diff($sourceStrings, array_keys($translations)));
    $unexpected = array_values(array_diff(array_keys($translations), $sourceStrings));
    if ($missing !== []) translationFail("{$languageName}.csv is missing: " . implode(' | ', $missing));
    if ($unexpected !== []) translationFail("{$languageName}.csv contains stale strings: " . implode(' | ', $unexpected));
    if ($changed < (int) floor(count($coverageSourceStrings) * 0.8)) translationFail("{$languageName}.csv leaves too much source text untranslated.");
    $totalTranslations += count($translations);
}

echo 'Relay European translation test passed (' . count($expectedLanguages) . ' languages, ' . $totalTranslations . " strings).\n";

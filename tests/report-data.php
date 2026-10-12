<?php
// Shared synthetic responses for report tests and UI screenshots.
declare(strict_types=1);
require_once __DIR__ . '/../public/lib.php';

function report_test_data(array $survey): array
{
    $rows = [];
    $samples = [];
    for ($i = 0; $i < 24; $i++) {
        $answers = $notes = [];
        foreach (questions($survey) as $j => $q) {
            $id = $q['id'];
            if (in_array($id, $survey['reportFilters'], true)) {
                // 10 / 10 / 4: two reportable groups and one deliberately suppressed group.
                $answers[$id] = $q['options'][$i < 10 ? 0 : ($i < 20 ? 1 : 2)];
                continue;
            }
            if (!is_visible($q, $answers) || ($i + $j) % 13 === 0) continue;
            $sample = $samples[$id] ?? 0;
            $samples[$id] = $sample + 1;
            switch ($q['type']) {
                case 'scale':
                    $answers[$id] = $sample % 6 === 5 ? NA : $sample % 6 + 1;
                    break;
                case 'single':
                    $answers[$id] = $q['options'][$sample % count($q['options'])];
                    break;
                case 'multi':
                    $options = $q['options'];
                    $index = $sample % count($options);
                    $answers[$id] = [$options[$index]];
                    if ($options[$index] !== 'Keine' && $options[($index + 1) % count($options)] !== 'Keine') {
                        $answers[$id][] = $options[($index + 1) % count($options)];
                    }
                    break;
                case 'text':
                    $answers[$id] = "Mehr gemeinsame Ausbildung und klare Absprachen ($id, Beispiel $i).";
                    break;
            }
            if ($q['type'] !== 'text' && $i % 3 === 0) $notes[$id] = "Testkommentar zu $id: Termine besser abstimmen (Beispiel $i).";
        }
        $rows[] = ['answers' => $answers, 'notes' => $notes];
    }
    return $rows;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $survey = json_decode(file_get_contents(__DIR__ . '/../public/surveys/dahlbruch-2026.json'), true, 512, JSON_THROW_ON_ERROR);
    echo '[' . implode(',', array_map('state_json', report_test_data($survey))) . ']';
}

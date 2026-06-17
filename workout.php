<?php
// workout.php - Дневник тренировок на PHP (CLI + веб)
// CLI: php workout.php add --exercise="Приседания" --sets=3 --reps=10 --weight=50

$dataFile = 'workouts.json';

function loadData() {
    global $dataFile;
    if (!file_exists($dataFile)) {
        return ['workouts' => [], 'next_id' => 1];
    }
    $json = file_get_contents($dataFile);
    $data = json_decode($json, true);
    if (!$data) $data = ['workouts' => [], 'next_id' => 1];
    return $data;
}

function saveData($data) {
    global $dataFile;
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function addWorkout(&$data, $exercise, $sets, $reps, $weight, $date, $notes) {
    if (!$date) $date = date('Y-m-d');
    $id = $data['next_id']++;
    $w = [
        'id' => $id,
        'exercise' => $exercise,
        'sets' => $sets,
        'reps' => $reps,
        'weight' => $weight,
        'date' => $date,
        'notes' => $notes
    ];
    $data['workouts'][] = $w;
    saveData($data);
    return $w;
}

function deleteWorkout(&$data, $id) {
    $filtered = array_filter($data['workouts'], function($w) use ($id) { return $w['id'] != $id; });
    if (count($filtered) < count($data['workouts'])) {
        $data['workouts'] = array_values($filtered);
        saveData($data);
        return true;
    }
    return false;
}

function getWorkouts($data, $exercise = null, $dateFrom = null, $dateTo = null,
                     $minWeight = null, $maxWeight = null) {
    $result = $data['workouts'];
    if ($exercise) {
        $result = array_filter($result, function($w) use ($exercise) {
            return strcasecmp($w['exercise'], $exercise) == 0;
        });
    }
    if ($dateFrom) $result = array_filter($result, fn($w) => $w['date'] >= $dateFrom);
    if ($dateTo) $result = array_filter($result, fn($w) => $w['date'] <= $dateTo);
    if ($minWeight !== null) $result = array_filter($result, fn($w) => $w['weight'] >= $minWeight);
    if ($maxWeight !== null) $result = array_filter($result, fn($w) => $w['weight'] <= $maxWeight);
    usort($result, fn($a, $b) => strcmp($a['date'], $b['date']));
    return array_values($result);
}

function getStatistics($data, $exercise = null) {
    $workouts = getWorkouts($data, $exercise);
    $total = count($workouts);
    $totalVolume = array_sum(array_map(fn($w) => $w['sets'] * $w['reps'] * $w['weight'], $workouts));
    $bestByExercise = [];
    foreach ($workouts as $w) {
        $vol = $w['sets'] * $w['reps'] * $w['weight'];
        if (!isset($bestByExercise[$w['exercise']]) || $vol > $bestByExercise[$w['exercise']]['volume']) {
            $bestByExercise[$w['exercise']] = ['workout' => $w, 'volume' => $vol];
        }
    }
    $progress = [];
    if ($exercise) {
        $monthMap = [];
        foreach ($workouts as $w) {
            $month = substr($w['date'], 0, 7);
            if (!isset($monthMap[$month])) $monthMap[$month] = ['total' => 0, 'count' => 0];
            $monthMap[$month]['total'] += $w['weight'];
            $monthMap[$month]['count']++;
        }
        ksort($monthMap);
        foreach ($monthMap as $month => $data) {
            $progress[$month] = $data['total'] / $data['count'];
        }
    }
    return ['totalWorkouts' => $total, 'totalVolume' => $totalVolume,
            'bestByExercise' => $bestByExercise, 'progress' => $progress];
}

function getPersonalRecords($data) {
    $best = [];
    foreach ($data['workouts'] as $w) {
        $vol = $w['sets'] * $w['reps'] * $w['weight'];
        if (!isset($best[$w['exercise']]) || $vol > $best[$w['exercise']]['sets'] * $best[$w['exercise']]['reps'] * $best[$w['exercise']]['weight']) {
            $best[$w['exercise']] = $w;
        }
    }
    return $best;
}

function exportCSV($data, $file) {
    $f = fopen($file, 'w');
    fputcsv($f, ['ID', 'Exercise', 'Sets', 'Reps', 'Weight', 'Date', 'Notes']);
    foreach ($data['workouts'] as $w) {
        fputcsv($f, [$w['id'], $w['exercise'], $w['sets'], $w['reps'], $w['weight'], $w['date'], $w['notes']]);
    }
    fclose($f);
}

// ========== CLI ==========
if (php_sapi_name() === 'cli') {
    $options = getopt("", ["cmd:", "exercise:", "sets:", "reps:", "weight:", "date:", "notes:", "id:", "from:", "to:", "min-weight:", "max-weight:", "output:"]);
    $cmd = $options['cmd'] ?? null;
    $data = loadData();
    switch ($cmd) {
        case 'add':
            $exercise = $options['exercise'] ?? '';
            $sets = isset($options['sets']) ? (int)$options['sets'] : 0;
            $reps = isset($options['reps']) ? (int)$options['reps'] : 0;
            $weight = isset($options['weight']) ? (float)$options['weight'] : 0;
            $date = $options['date'] ?? null;
            $notes = $options['notes'] ?? '';
            if ($exercise && $sets > 0 && $reps > 0 && $weight > 0) {
                $w = addWorkout($data, $exercise, $sets, $reps, $weight, $date, $notes);
                echo "✅ Тренировка #{$w['id']} добавлена: {$w['exercise']} {$w['sets']}×{$w['reps']}×{$w['weight']}кг\n";
            } else {
                echo "Укажите --exercise, --sets, --reps, --weight\n";
            }
            break;
        case 'list':
            $exercise = $options['exercise'] ?? null;
            $from = $options['from'] ?? null;
            $to = $options['to'] ?? null;
            $minW = isset($options['min-weight']) ? (float)$options['min-weight'] : null;
            $maxW = isset($options['max-weight']) ? (float)$options['max-weight'] : null;
            $list = getWorkouts($data, $exercise, $from, $to, $minW, $maxW);
            if (empty($list)) {
                echo "Нет записей.\n";
            } else {
                printf("%-4s %-15s %-8s %-6s %-6s %-12s %s\n", "ID", "Упражнение", "Подходы", "Повт.", "Вес", "Дата", "Заметки");
                foreach ($list as $w) {
                    printf("%-4d %-15s %-8d %-6d %-6.1f %-12s %s\n", $w['id'], $w['exercise'], $w['sets'], $w['reps'], $w['weight'], $w['date'], $w['notes']);
                }
            }
            break;
        case 'stats':
            $exercise = $options['exercise'] ?? null;
            $stats = getStatistics($data, $exercise);
            echo "📊 Статистика" . ($exercise ? " по упражнению $exercise" : "") . "\n";
            echo "Всего тренировок: {$stats['totalWorkouts']}\n";
            echo "Общий объём: " . number_format($stats['totalVolume'], 1) . "\n";
            if (!empty($stats['bestByExercise'])) {
                echo "Лучшие подходы по объёму:\n";
                foreach ($stats['bestByExercise'] as $ex => $info) {
                    $w = $info['workout'];
                    $vol = $info['volume'];
                    echo "  $ex: {$w['sets']}×{$w['reps']}×{$w['weight']}кг = " . number_format($vol, 1) . " (id {$w['id']})\n";
                }
            }
            if (!empty($stats['progress'])) {
                echo "Прогресс среднего веса по месяцам:\n";
                foreach ($stats['progress'] as $month => $avg) {
                    echo "  $month: " . number_format($avg, 1) . " кг\n";
                }
            }
            break;
        case 'pr':
            $records = getPersonalRecords($data);
            if (empty($records)) {
                echo "Нет рекордов.\n";
            } else {
                echo "🏆 Личные рекорды (максимальный объём):\n";
                foreach ($records as $ex => $w) {
                    $vol = $w['sets'] * $w['reps'] * $w['weight'];

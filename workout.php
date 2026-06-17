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
                    echo "$ex: {$w['sets']}×{$w['reps']}×{$w['weight']}кг = " . number_format($vol, 1) . " (id {$w['id']})\n";
                }
            }
            break;
        case 'delete':
            $id = isset($options['id']) ? (int)$options['id'] : 0;
            if ($id && deleteWorkout($data, $id)) {
                echo "✅ Тренировка #$id удалена\n";
            } else {
                echo "❌ Тренировка #$id не найдена\n";
            }
            break;
        case 'export':
            $output = $options['output'] ?? null;
            if ($output) {
                exportCSV($data, $output);
                echo "Экспортировано в $output\n";
            } else {
                echo "Укажите --output\n";
            }
            break;
        default:
            interactiveMode($data);
            break;
    }
    exit;
}

// ========== ИНТЕРАКТИВНЫЙ РЕЖИМ ==========
function interactiveMode(&$data) {
    while (true) {
        echo "\n🏋️ Дневник тренировок (интерактивный)\n";
        echo "1. Добавить тренировку\n";
        echo "2. Список тренировок\n";
        echo "3. Статистика\n";
        echo "4. Личные рекорды\n";
        echo "5. Удалить\n";
        echo "6. Экспорт CSV\n";
        echo "0. Выход\n";
        echo "Выберите действие: ";
        $choice = trim(fgets(STDIN));
        switch ($choice) {
            case '0': return;
            case '1':
                echo "Упражнение: ";
                $ex = trim(fgets(STDIN));
                if (!$ex) { echo "Упражнение обязательно\n"; break; }
                echo "Подходы: ";
                $sets = (int)trim(fgets(STDIN));
                echo "Повторений: ";
                $reps = (int)trim(fgets(STDIN));
                echo "Вес (кг): ";
                $weight = (float)trim(fgets(STDIN));
                echo "Дата (ГГГГ-ММ-ДД, Enter сегодня): ";
                $date = trim(fgets(STDIN));
                if (!$date) $date = date('Y-m-d');
                echo "Заметки: ";
                $notes = trim(fgets(STDIN));
                $w = addWorkout($data, $ex, $sets, $reps, $weight, $date, $notes);
                echo "✅ Добавлена тренировка #{$w['id']}\n";
                break;
            case '2':
                echo "Упражнение (Enter пропустить): ";
                $ex = trim(fgets(STDIN));
                if ($ex === '') $ex = null;
                echo "Дата от (Enter пропустить): ";
                $from = trim(fgets(STDIN));
                if ($from === '') $from = null;
                echo "Дата до (Enter пропустить): ";
                $to = trim(fgets(STDIN));
                if ($to === '') $to = null;
                $list = getWorkouts($data, $ex, $from, $to);
                if (empty($list)) {
                    echo "Нет записей.\n";
                } else {
                    printf("%-4s %-15s %-8s %-6s %-6s %-12s %s\n", "ID", "Упражнение", "Подходы", "Повт.", "Вес", "Дата", "Заметки");
                    foreach ($list as $w) {
                        printf("%-4d %-15s %-8d %-6d %-6.1f %-12s %s\n", $w['id'], $w['exercise'], $w['sets'], $w['reps'], $w['weight'], $w['date'], $w['notes']);
                    }
                }
                break;
            case '3':
                echo "Упражнение (Enter все): ";
                $ex = trim(fgets(STDIN));
                if ($ex === '') $ex = null;
                $stats = getStatistics($data, $ex);
                echo "📊 Статистика" . ($ex ? " по упражнению $ex" : "") . "\n";
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
            case '4':
                $records = getPersonalRecords($data);
                if (empty($records)) {
                    echo "Нет рекордов.\n";
                } else {
                    echo "🏆 Личные рекорды (максимальный объём):\n";
                    foreach ($records as $ex => $w) {
                        $vol = $w['sets'] * $w['reps'] * $w['weight'];
                        echo "$ex: {$w['sets']}×{$w['reps']}×{$w['weight']}кг = " . number_format($vol, 1) . " (id {$w['id']})\n";
                    }
                }
                break;
            case '5':
                echo "ID для удаления: ";
                $id = (int)trim(fgets(STDIN));
                if (deleteWorkout($data, $id)) {
                    echo "✅ Удалено\n";
                } else {
                    echo "❌ Не найдено\n";
                }
                break;
            case '6':
                echo "Имя файла (CSV): ";
                $file = trim(fgets(STDIN));
                if (!$file) $file = 'workouts.csv';
                exportCSV($data, $file);
                echo "Экспортировано в $file\n";
                break;
            default:
                echo "Неверный выбор\n";
        }
    }
}

// ========== ВЕБ-ИНТЕРФЕЙС ==========
if (php_sapi_name() !== 'cli') {
    $data = loadData();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>🏋️ Дневник тренировок (PHP)</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #f4f7fb; margin: 20px; }
            .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background: #2c3e50; color: white; }
            .form-row { margin: 10px 0; }
            .form-row label { display: inline-block; width: 100px; }
            input, select, button { padding: 6px; margin: 2px; }
            button { background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer; }
            .stats { margin-top: 20px; }
        </style>
    </head>
    <body>
    <div class="container">
        <h1>🏋️ Дневник тренировок</h1>
        <h3>Добавить тренировку</h3>
        <form method="GET">
            <div class="form-row"><label>Упражнение:</label><input type="text" name="exercise" required></div>
            <div class="form-row"><label>Подходы:</label><input type="number" name="sets" required></div>
            <div class="form-row"><label>Повторений:</label><input type="number" name="reps" required></div>
            <div class="form-row"><label>Вес (кг):</label><input type="number" step="any" name="weight" required></div>
            <div class="form-row"><label>Дата:</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-row"><label>Заметки:</label><input type="text" name="notes"></div>
            <button type="submit" name="action" value="add">➕ Добавить</button>
        </form>

        <h3>Список тренировок</h3>
        <table>
            <tr><th>ID</th><th>Упражнение</th><th>Подходы</th><th>Повт.</th><th>Вес</th><th>Дата</th><th>Заметки</th></tr>
            <?php foreach ($data['workouts'] as $w): ?>
                <tr>
                    <td><?= $w['id'] ?></td>
                    <td><?= htmlspecialchars($w['exercise']) ?></td>
                    <td><?= $w['sets'] ?></td>
                    <td><?= $w['reps'] ?></td>
                    <td><?= $w['weight'] ?></td>
                    <td><?= $w['date'] ?></td>
                    <td><?= htmlspecialchars($w['notes']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php
        if (isset($_GET['action']) && $_GET['action'] == 'add' && isset($_GET['exercise'])) {
            $exercise = $_GET['exercise'];
            $sets = (int)$_GET['sets'];
            $reps = (int)$_GET['reps'];
            $weight = (float)$_GET['weight'];
            $date = $_GET['date'] ?? date('Y-m-d');
            $notes = $_GET['notes'] ?? '';
            addWorkout($data, $exercise, $sets, $reps, $weight, $date, $notes);
            echo "<div style='background:#d5f5e3; padding:10px; margin-top:10px;'>✅ Добавлено</div>";
            // redirect to avoid re-add
            header("Location: ?");
            exit;
        }

        // Статистика
        $stats = getStatistics($data, null);
        echo "<div class='stats'><h3>📊 Статистика</h3>";
        echo "<p>Всего тренировок: {$stats['totalWorkouts']}</p>";
        echo "<p>Общий объём: " . number_format($stats['totalVolume'], 1) . "</p>";
        if (!empty($stats['bestByExercise'])) {
            echo "<p>Лучшие подходы по объёму:</p><ul>";
            foreach ($stats['bestByExercise'] as $ex => $info) {
                $w = $info['workout'];
                $vol = $info['volume'];
                echo "<li>$ex: {$w['sets']}×{$w['reps']}×{$w['weight']}кг = " . number_format($vol, 1) . " (id {$w['id']})</li>";
            }
            echo "</ul>";
        }
        echo "</div>";

        // Личные рекорды
        $records = getPersonalRecords($data);
        if (!empty($records)) {
            echo "<div class='stats'><h3>🏆 Личные рекорды</h3><ul>";
            foreach ($records as $ex => $w) {
                $vol = $w['sets'] * $w['reps'] * $w['weight'];
                echo "<li>$ex: {$w['sets']}×{$w['reps']}×{$w['weight']}кг = " . number_format($vol, 1) . " (id {$w['id']})</li>";
            }
            echo "</ul></div>";
        }

        // Экспорт
        if (isset($_GET['export'])) {
            exportCSV($data, 'workouts.csv');
            echo "<p>✅ Экспортировано в workouts.csv</p>";
        }
        ?>
        <p><a href="?export=1">📤 Экспорт CSV</a></p>
    </div>
    </body>
    </html>
    <?php
}

#!/usr/bin/env node
/**
 * workout.js - Дневник тренировок на JavaScript (Node.js CLI)
 */
const fs = require('fs');
const path = require('path');
const { program } = require('commander');
const { v4: uuidv4 } = require('uuid');

const DATA_FILE = path.join(__dirname, 'workouts.json');

class Workout {
    constructor(exercise, sets, reps, weight, date, notes = '') {
        this.id = uuidv4();
        this.exercise = exercise;
        this.sets = sets;
        this.reps = reps;
        this.weight = weight;
        this.date = date || new Date().toISOString().slice(0, 10);
        this.notes = notes;
    }
}

class WorkoutTracker {
    constructor() {
        this.workouts = [];
        this.load();
    }

    load() {
        if (fs.existsSync(DATA_FILE)) {
            try {
                this.workouts = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
            } catch {}
        }
    }

    save() {
        fs.writeFileSync(DATA_FILE, JSON.stringify(this.workouts, null, 2));
    }

    addWorkout(exercise, sets, reps, weight, date, notes) {
        const w = new Workout(exercise, sets, reps, weight, date, notes);
        this.workouts.push(w);
        this.save();
        return w;
    }

    deleteWorkout(id) {
        const idx = this.workouts.findIndex(w => w.id === id);
        if (idx === -1) return false;
        this.workouts.splice(idx, 1);
        this.save();
        return true;
    }

    getWorkouts(filter = {}) {
        let result = this.workouts;
        if (filter.exercise) {
            result = result.filter(w => w.exercise.toLowerCase() === filter.exercise.toLowerCase());
        }
        if (filter.dateFrom) {
            result = result.filter(w => w.date >= filter.dateFrom);
        }
        if (filter.dateTo) {
            result = result.filter(w => w.date <= filter.dateTo);
        }
        if (filter.minWeight !== undefined) {
            result = result.filter(w => w.weight >= filter.minWeight);
        }
        if (filter.maxWeight !== undefined) {
            result = result.filter(w => w.weight <= filter.maxWeight);
        }
        return result.sort((a, b) => a.date.localeCompare(b.date));
    }

    getStatistics(exercise) {
        const workouts = exercise ? this.getWorkouts({ exercise }) : this.workouts;
        const totalWorkouts = workouts.length;
        const totalVolume = workouts.reduce((sum, w) => sum + w.sets * w.reps * w.weight, 0);
        const bestByExercise = {};
        workouts.forEach(w => {
            const volume = w.sets * w.reps * w.weight;
            const key = w.exercise;
            if (!bestByExercise[key] || volume > bestByExercise[key].volume) {
                bestByExercise[key] = { workout: w, volume };
            }
        });
        const progress = {};
        if (exercise) {
            const monthMap = {};
            workouts.forEach(w => {
                const month = w.date.slice(0, 7);
                if (!monthMap[month]) monthMap[month] = { total: 0, count: 0 };
                monthMap[month].total += w.weight;
                monthMap[month].count++;
            });
            for (const m of Object.keys(monthMap).sort()) {
                progress[m] = monthMap[m].total / monthMap[m].count;
            }
        }
        return { totalWorkouts, totalVolume, bestByExercise, progress };
    }

    getPersonalRecords() {
        const best = {};
        this.workouts.forEach(w => {
            const volume = w.sets * w.reps * w.weight;
            const key = w.exercise;
            if (!best[key] || volume > best[key].sets * best[key].reps * best[key].weight) {
                best[key] = w;
            }
        });
        return best;
    }

    exportCSV(filepath) {
        const lines = ['ID,Exercise,Sets,Reps,Weight,Date,Notes'];
        this.workouts.forEach(w => {
            lines.push(`${w.id},${w.exercise},${w.sets},${w.reps},${w.weight},${w.date},${w.notes}`);
        });
        fs.writeFileSync(filepath, lines.join('\n'));
    }
}

program
    .command('add')
    .requiredOption('-e, --exercise <exercise>', 'Упражнение')
    .requiredOption('-s, --sets <sets>', 'Подходы', parseInt)
    .requiredOption('-r, --reps <reps>', 'Повторения', parseInt)
    .requiredOption('-w, --weight <weight>', 'Вес (кг)', parseFloat)
    .option('-d, --date <date>', 'Дата (ГГГГ-ММ-ДД)')
    .option('-n, --notes <notes>', 'Заметки')
    .action((options) => {
        const tracker = new WorkoutTracker();
        const w = tracker.addWorkout(options.exercise, options.sets, options.reps, options.weight, options.date, options.notes);
        console.log(`✅ Тренировка ${w.id} добавлена: ${w.exercise} ${w.sets}×${w.reps}×${w.weight}кг`);
    });

program
    .command('list')
    .option('-e, --exercise <exercise>', 'Фильтр по упражнению')
    .option('--from <dateFrom>', 'Дата от')
    .option('--to <dateTo>', 'Дата до')
    .option('--min-weight <minWeight>', 'Мин. вес', parseFloat)
    .option('--max-weight <maxWeight>', 'Макс. вес', parseFloat)
    .action((options) => {
        const tracker = new WorkoutTracker();
        const workouts = tracker.getWorkouts(options);
        if (!workouts.length) {
            console.log('Нет записей.');
            return;
        }
        console.log('ID'.padEnd(36) + 'Упражнение'.padEnd(15) + 'Подходы'.padEnd(8) + 'Повт.'.padEnd(6) + 'Вес'.padEnd(6) + 'Дата'.padEnd(12) + 'Заметки');
        workouts.forEach(w => {
            console.log(`${w.id.padEnd(36)} ${w.exercise.padEnd(15)} ${w.sets.toString().padEnd(8)} ${w.reps.toString().padEnd(6)} ${w.weight.toFixed(1).padEnd(6)} ${w.date.padEnd(12)} ${w.notes}`);
        });
    });

program
    .command('stats')
    .option('-e, --exercise <exercise>', 'Фильтр по упражнению')
    .action((options) => {
        const tracker = new WorkoutTracker();
        const stats = tracker.getStatistics(options.exercise);
        console.log(`📊 Статистика ${options.exercise ? 'по упражнению ' + options.exercise : ''}`);
        console.log(`Всего тренировок: ${stats.totalWorkouts}`);
        console.log(`Общий объём: ${stats.totalVolume.toFixed(1)}`);
        if (Object.keys(stats.bestByExercise).length) {
            console.log('Лучшие подходы по объёму:');
            for (const ex in stats.bestByExercise) {
                const data = stats.bestByExercise[ex];
                const w = data.workout;
                console.log(`  ${ex}: ${w.sets}×${w.reps}×${w.weight}кг = ${data.volume} (id ${w.id})`);
            }
        }
        if (Object.keys(stats.progress).length) {
            console.log('Прогресс среднего веса по месяцам:');
            for (const m in stats.progress) {
                console.log(`  ${m}: ${stats.progress[m].toFixed(1)} кг`);
            }
        }
    });

program
    .command('pr')
    .action(() => {
        const tracker = new WorkoutTracker();
        const records = tracker.getPersonalRecords();
        if (!Object.keys(records).length) {
            console.log('Нет рекордов.');
            return;
        }
        console.log('🏆 Личные рекорды (максимальный объём):');
        for (const ex in records) {
            const w = records[ex];
            console.log(`${ex}: ${w.sets}×${w.reps}×${w.weight}кг = ${w.sets*w.reps*w.weight} (id ${w.id})`);
        }
    });

program
    .command('delete')
    .requiredOption('--id <id>', 'ID записи')
    .action((options) => {
        const tracker = new WorkoutTracker();
        if (tracker.deleteWorkout(options.id)) {
            console.log(`✅ Тренировка ${options.id} удалена`);
        } else {
            console.log(`❌ Тренировка ${options.id} не найдена`);
        }
    });

program
    .command('export')
    .requiredOption('-o, --output <file>', 'Имя CSV файла')
    .action((options) => {
        const tracker = new WorkoutTracker();
        tracker.exportCSV(options.output);
        console.log(`Экспортировано в ${options.output}`);
    });

program
    .command('interactive')
    .description('Интерактивный режим')
    .action(() => {
        const tracker = new WorkoutTracker();
        const readline = require('readline');
        const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
        const prompt = (q) => new Promise(resolve => rl.question(q, resolve));

        (async () => {
            while (true) {
                console.log('\n🏋️ Дневник тренировок (интерактивный)');
                console.log('1. Добавить тренировку');
                console.log('2. Список тренировок');
                console.log('3. Статистика');
                console.log('4. Личные рекорды');
                console.log('5. Удалить');
                console.log('6. Экспорт CSV');
                console.log('0. Выход');
                const choice = await prompt('Выберите действие: ');
                switch (choice.trim()) {
                    case '0': rl.close(); return;
                    case '1': {
                        const exercise = await prompt('Упражнение: ');
                        if (!exercise) { console.log('Упражнение обязательно'); break; }
                        const sets = parseInt(await prompt('Подходы: '));
                        const reps = parseInt(await prompt('Повторений: '));
                        const weight = parseFloat(await prompt('Вес (кг): '));
                        let date = await prompt('Дата (ГГГГ-ММ-ДД, Enter сегодня): ');
                        if (!date) date = new Date().toISOString().slice(0,10);
                        const notes = await prompt('Заметки: ');
                        const w = tracker.addWorkout(exercise, sets, reps, weight, date, notes);
                        console.log(`✅ Добавлена тренировка ${w.id}`);
                        break;
                    }
                    case '2': {
                        const exercise = await prompt('Упражнение (Enter пропустить): ') || undefined;
                        const from = await prompt('Дата от (Enter пропустить): ') || undefined;
                        const to = await prompt('Дата до (Enter пропустить): ') || undefined;
                        const workouts = tracker.getWorkouts({ exercise, dateFrom: from, dateTo: to });
                        if (!workouts.length) { console.log('Нет записей.'); break; }
                        console.log('ID'.padEnd(36) + 'Упражнение'.padEnd(15) + 'Подходы'.padEnd(8) + 'Повт.'.padEnd(6) + 'Вес'.padEnd(6) + 'Дата'.padEnd(12) + 'Заметки');
                        workouts.forEach(w => {
                            console.log(`${w.id.padEnd(36)} ${w.exercise.padEnd(15)} ${w.sets.toString().padEnd(8)} ${w.reps.toString().padEnd(6)} ${w.weight.toFixed(1).padEnd(6)} ${w.date.padEnd(12)} ${w.notes}`);
                        });
                        break;
                    }
                    case '3': {
                        const exercise = await prompt('Упражнение (Enter все): ') || undefined;
                        const stats = tracker.getStatistics(exercise);
                        console.log(`📊 Статистика ${exercise ? 'по упражнению ' + exercise : ''}`);
                        console.log(`Всего тренировок: ${stats.totalWorkouts}`);
                        console.log(`Общий объём: ${stats.totalVolume.toFixed(1)}`);
                        if (Object.keys(stats.bestByExercise).length) {
                            console.log('Лучшие подходы по объёму:');
                            for (const ex in stats.bestByExercise) {
                                const data = stats.bestByExercise[ex];
                                const w = data.workout;
                                console.log(`  ${ex}: ${w.sets}×${w.reps}×${w.weight}кг = ${data.volume} (id ${w.id})`);
                            }
                        }
                        if (Object.keys(stats.progress).length) {
                            console.log('Прогресс среднего веса по месяцам:');
                            for (const m in stats.progress) {
                                console.log(`  ${m}: ${stats.progress[m].toFixed(1)} кг`);
                            }
                        }
                        break;
                    }
                    case '4': {
                        const records = tracker.getPersonalRecords();
                        if (!Object.keys(records).length) { console.log('Нет рекордов.'); break; }
                        console.log('🏆 Личные рекорды (максимальный объём):');
                        for (const ex in records) {
                            const w = records[ex];
                            console.log(`${ex}: ${w.sets}×${w.reps}×${w.weight}кг = ${w.sets*w.reps*w.weight} (id ${w.id})`);
                        }
                        break;
                    }
                    case '5': {
                        const id = await prompt('ID для удаления: ');
                        if (!id) break;
                        if (tracker.deleteWorkout(id)) {
                            console.log('✅ Удалено');
                        } else {
                            console.log('❌ Не найдено');
                        }
                        break;
                    }
                    case '6': {
                        const file = await prompt('Имя файла (CSV): ') || 'workouts.csv';
                        tracker.exportCSV(file);
                        console.log(`Экспортировано в ${file}`);
                        break;
                    }
                    default: console.log('Неверный выбор');
                }
            }
        })();
    });

if (process.argv.length <= 2) {
    process.argv.push('interactive');
}
program.parse(process.argv);

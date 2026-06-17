// workout.rs - Дневник тренировок на Rust (CLI)
use serde::{Serialize, Deserialize};
use std::collections::HashMap;
use std::fs;
use std::io::{self, Write, BufRead};
use std::path::Path;
use std::str::FromStr;
use chrono::Local;

#[derive(Serialize, Deserialize, Clone)]
struct Workout {
    id: u32,
    exercise: String,
    sets: u32,
    reps: u32,
    weight: f64,
    date: String,
    notes: String,
}

#[derive(Serialize, Deserialize)]
struct Tracker {
    workouts: Vec<Workout>,
    next_id: u32,
}

impl Tracker {
    fn load() -> Self {
        let path = "workouts.json";
        if Path::new(path).exists() {
            if let Ok(data) = fs::read_to_string(path) {
                if let Ok(t) = serde_json::from_str(&data) {
                    return t;
                }
            }
        }
        Tracker { workouts: vec![], next_id: 1 }
    }

    fn save(&self) {
        let data = serde_json::to_string_pretty(self).unwrap();
        fs::write("workouts.json", data).unwrap();
    }
}

fn add_workout(tracker: &mut Tracker, exercise: &str, sets: u32, reps: u32, weight: f64,
               date: &str, notes: &str) -> Workout {
    let date = if date.is_empty() { Local::now().format("%Y-%m-%d").to_string() } else { date.to_string() };
    let w = Workout {
        id: tracker.next_id,
        exercise: exercise.to_string(),
        sets,
        reps,
        weight,
        date,
        notes: notes.to_string(),
    };
    tracker.workouts.push(w.clone());
    tracker.next_id += 1;
    tracker.save();
    w
}

fn delete_workout(tracker: &mut Tracker, id: u32) -> bool {
    let len = tracker.workouts.len();
    tracker.workouts.retain(|w| w.id != id);
    if tracker.workouts.len() < len {
        tracker.save();
        return true;
    }
    false
}

fn get_workouts(tracker: &Tracker, exercise: Option<&str>, date_from: Option<&str>, date_to: Option<&str>,
                min_weight: Option<f64>, max_weight: Option<f64>) -> Vec<Workout> {
    let mut res = tracker.workouts.clone();
    if let Some(ex) = exercise {
        res.retain(|w| w.exercise.to_lowercase() == ex.to_lowercase());
    }
    if let Some(d) = date_from {
        res.retain(|w| w.date >= d);
    }
    if let Some(d) = date_to {
        res.retain(|w| w.date <= d);
    }
    if let Some(m) = min_weight {
        res.retain(|w| w.weight >= m);
    }
    if let Some(m) = max_weight {
        res.retain(|w| w.weight <= m);
    }
    res.sort_by(|a, b| a.date.cmp(&b.date));
    res
}

fn get_statistics(tracker: &Tracker, exercise: Option<&str>) -> (u32, f64, HashMap<String, (Workout, f64)>, HashMap<String, f64>) {
    let workouts = get_workouts(tracker, exercise, None, None, None, None);
    let total_workouts = workouts.len() as u32;
    let total_volume = workouts.iter().map(|w| w.sets as f64 * w.reps as f64 * w.weight).sum();
    let mut best_by_exercise = HashMap::new();
    for w in &workouts {
        let vol = w.sets as f64 * w.reps as f64 * w.weight;
        let entry = best_by_exercise.entry(w.exercise.clone()).or_insert((w.clone(), 0.0));
        if vol > entry.1 {
            *entry = (w.clone(), vol);
        }
    }
    let mut progress = HashMap::new();
    if exercise.is_some() {
        let mut month_map = HashMap::new();
        for w in &workouts {
            let month = w.date[..7].to_string();
            let entry = month_map.entry(month).or_insert((0.0, 0));
            entry.0 += w.weight;
            entry.1 += 1;
        }
        let mut months: Vec<_> = month_map.keys().collect();
        months.sort();
        for m in months {
            let (total, count) = month_map[m];
            progress.insert(m.clone(), total / count as f64);
        }
    }
    (total_workouts, total_volume, best_by_exercise, progress)
}

fn get_personal_records(tracker: &Tracker) -> HashMap<String, Workout> {
    let mut best = HashMap::new();
    for w in &tracker.workouts {
        let vol = w.sets as f64 * w.reps as f64 * w.weight;
        let entry = best.entry(w.exercise.clone()).or_insert(w.clone());
        if vol > (entry.sets as f64 * entry.reps as f64 * entry.weight) {
            *entry = w.clone();
        }
    }
    best
}

fn export_csv(tracker: &Tracker, filepath: &str) -> Result<(), Box<dyn std::error::Error>> {
    let mut writer = csv::Writer::from_path(filepath)?;
    writer.write_record(&["ID", "Exercise", "Sets", "Reps", "Weight", "Date", "Notes"])?;
    for w in &tracker.workouts {
        writer.serialize((w.id, &w.exercise, w.sets, w.reps, w.weight, &w.date, &w.notes))?;
    }
    writer.flush()?;
    Ok(())
}

fn read_line(prompt: &str) -> String {
    print!("{}", prompt);
    io::stdout().flush().unwrap();
    let mut input = String::new();
    io::stdin().read_line(&mut input).unwrap();
    input.trim().to_string()
}

fn main() {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 2 {
        interactive_mode();
        return;
    }
    let mut tracker = Tracker::load();
    match args[1].as_str() {
        "add" => {
            let mut exercise = String::new();
            let mut sets = 0;
            let mut reps = 0;
            let mut weight = 0.0;
            let mut date = String::new();
            let mut notes = String::new();
            let mut i = 2;
            while i < args.len() {
                match args[i].as_str() {
                    "--exercise" => { exercise = args[i+1].clone(); i += 2; }
                    "--sets" => { sets = args[i+1].parse().unwrap_or(0); i += 2; }
                    "--reps" => { reps = args[i+1].parse().unwrap_or(0); i += 2; }
                    "--weight" => { weight = args[i+1].parse().unwrap_or(0.0); i += 2; }
                    "--date" => { date = args[i+1].clone(); i += 2; }
                    "--notes" => { notes = args[i+1].clone(); i += 2; }
                    _ => { i += 1; }
                }
            }
            if exercise.is_empty() || sets == 0 || reps == 0 || weight == 0.0 {
                println!("Укажите --exercise, --sets, --reps, --weight");
                return;
            }
            let w = add_workout(&mut tracker, &exercise, sets, reps, weight, &date, &notes);
            println!("✅ Тренировка #{} добавлена: {} {}×{}×{:.1}кг", w.id, w.exercise, w.sets, w.reps, w.weight);
        }
        "list" => {
            let mut exercise = None;
            let mut date_from = None;
            let mut date_to = None;
            let mut i = 2;
            while i < args.len() {
                match args[i].as_str() {
                    "--exercise" => { exercise = Some(args[i+1].clone()); i += 2; }
                    "--from" => { date_from = Some(args[i+1].clone()); i += 2; }
                    "--to" => { date_to = Some(args[i+1].clone()); i += 2; }
                    _ => { i += 1; }
                }
            }
            let workouts = get_workouts(&tracker, exercise.as_deref(), date_from.as_deref(), date_to.as_deref(), None, None);
            if workouts.is_empty() {
                println!("Нет записей.");
            } else {
                println!("{:<4} {:<15} {:<8} {:<6} {:<6} {:<12} {}", "ID", "Упражнение", "Подходы", "Повт.", "Вес", "Дата", "Заметки");
                for w in workouts {
                    println!("{:<4} {:<15} {:<8} {:<6} {:<6.1} {:<12} {}", w.id, w.exercise, w.sets, w.reps, w.weight, w.date, w.notes);
                }
            }
        }
        "stats" => {
            let mut exercise = None;
            let mut i = 2;
            while i < args.len() {
                if args[i] == "--exercise" {
                    exercise = Some(args[i+1].clone());
                    break;
                }
                i += 1;
            }
            let (total, volume, best, progress) = get_statistics(&tracker, exercise.as_deref());
            println!("📊 Статистика{}", if exercise.is_some() { format!(" по упражнению {}", exercise.unwrap()) } else { String::new() });
            println!("Всего тренировок: {}", total);
            println!("Общий объём: {:.1}", volume);
            if !best.is_empty() {
                println!("Лучшие подходы по объёму:");
                for (ex, (w, vol)) in best {
                    println!("  {}: {}×{}×{:.1}кг = {:.1} (id {})", ex, w.sets, w.reps, w.weight, vol, w.id);
                }
            }
            if !progress.is_empty() {
                println!("Прогресс среднего веса по месяцам:");
                let mut months: Vec<_> = progress.keys().collect();
                months.sort();
                for m in months {
                    println!("  {}: {:.1} кг", m, progress[m]);
                }
            }
        }
        "pr" => {
            let records = get_personal_records(&tracker);
            if records.is_empty() {
                println!("Нет рекордов.");
            } else {
                println!("🏆 Личные рекорды (максимальный объём):");
                for (ex, w) in records {
                    let vol = w.sets as f64 * w.reps as f64 * w.weight;
                    println!("{}: {}×{}×{:.1}кг = {:.1} (id {})", ex, w.sets, w.reps, w.weight, vol, w.id);
                }
            }
        }
        "delete" => {
            let mut id = 0;
            let mut i = 2;
            while i < args.len() {
                if args[i] == "--id" {
                    id = args[i+1].parse().unwrap_or(0);
                    break;
                }
                i += 1;
            }
            if id == 0 {
                println!("Укажите --id");
                return;
            }
            if delete_workout(&mut tracker, id) {
                println!("✅ Тренировка #{} удалена", id);
            } else {
                println!("❌ Тренировка #{} не найдена", id);
            }
        }
        "export" => {
            let mut output = String::new();
            let mut i = 2;
            while i < args.len() {
                if args[i] == "--output" {
                    output = args[i+1].clone();
                    break;
                }
                i += 1;
            }
            if output.is_empty() {
                println!("Укажите --output");
                return;
            }
            if let Err(e) = export_csv(&tracker, &output) {
                println!("Ошибка экспорта: {}", e);
            } else {
                println!("Экспортировано в {}", output);
            }
        }
        _ => interactive_mode(),
    }
}

fn interactive_mode() {
    let mut tracker = Tracker::load();
    let stdin = io::stdin();
    let mut stdout = io::stdout();
    loop {
        println!("\n🏋️ Дневник тренировок (интерактивный)");
        println!("1. Добавить тренировку");
        println!("2. Список тренировок");
        println!("3. Статистика");
        println!("4. Личные рекорды");
        println!("5. Удалить");
        println!("6. Экспорт CSV");
        println!("0. Выход");
        print!("Выберите действие: ");
        stdout.flush().unwrap();
        let mut choice = String::new();
        stdin.read_line(&mut choice).unwrap();
        match choice.trim() {
            "0" => break,
            "1" => {
                print!("Упражнение: ");
                stdout.flush().unwrap();
                let mut ex = String::new();
                stdin.read_line(&mut ex).unwrap();
                let ex = ex.trim();
                if ex.is_empty() {
                    println!("Упражнение обязательно");
                    continue;
                }
                print!("Подходы: ");
                stdout.flush().unwrap();
                let mut sets_str = String::new();
                stdin.read_line(&mut sets_str).unwrap();
                let sets = sets_str.trim().parse::<u32>().unwrap_or(0);
                print!("Повторений: ");
                stdout.flush().unwrap();
                let mut reps_str = String::new();
                stdin.read_line(&mut reps_str).unwrap();
                let reps = reps_str.trim().parse::<u32>().unwrap_or(0);
                print!("Вес (кг): ");
                stdout.flush().unwrap();
                let mut weight_str = String::new();
                stdin.read_line(&mut weight_str).unwrap();
                let weight = weight_str.trim().parse::<f64>().unwrap_or(0.0);
                print!("Дата (ГГГГ-ММ-ДД, Enter сегодня): ");
                stdout.flush().unwrap();
                let mut date = String::new();
                stdin.read_line(&mut date).unwrap();
                let date = date.trim();
                print!("Заметки: ");
                stdout.flush().unwrap();
                let mut notes = String::new();
                stdin.read_line(&mut notes).unwrap();
                let w = add_workout(&mut tracker, ex, sets, reps, weight, date, notes.trim());
                println!("✅ Добавлена тренировка #{}", w.id);
            }
            "2" => {
                print!("Упражнение (Enter пропустить): ");
                stdout.flush().unwrap();
                let mut ex = String::new();
                stdin.read_line(&mut ex).unwrap();
                let ex = if ex.trim().is_empty() { None } else { Some(ex.trim()) };
                print!("Дата от (Enter пропустить): ");
                stdout.flush().unwrap();
                let mut from = String::new();
                stdin.read_line(&mut from).unwrap();
                let from = if from.trim().is_empty() { None } else { Some(from.trim()) };
                print!("Дата до (Enter пропустить): ");
                stdout.flush().unwrap();
                let mut to = String::new();
                stdin.read_line(&mut to).unwrap();
                let to = if to.trim().is_empty() { None } else { Some(to.trim()) };
                let workouts = get_workouts(&tracker, ex, from, to, None, None);
                if workouts.is_empty() {
                    println!("Нет записей.");
                } else {
                    println!("{:<4} {:<15} {:<8} {:<6} {:<6} {:<12} {}", "ID", "Упражнение", "Подходы", "Повт.", "Вес", "Дата", "Заметки");
                    for w in workouts {
                        println!("{:<4} {:<15} {:<8} {:<6} {:<6.1} {:<12} {}", w.id, w.exercise, w.sets, w.reps, w.weight, w.date, w.notes);
                    }
                }
            }
            "3" => {
                print!("Упражнение (Enter все): ");
                stdout.flush().unwrap();
                let mut ex = String::new();
                stdin.read_line(&mut ex).unwrap();
                let ex = if ex.trim().is_empty() { None } else { Some(ex.trim()) };
                let (total, volume, best, progress) = get_statistics(&tracker, ex);
                println!("📊 Статистика{}", if ex.is_some() { format!(" по упражнению {}", ex.unwrap()) } else { String::new() });
                println!("Всего тренировок: {}", total);
                println!("Общий объём: {:.1}", volume);
                if !best.is_empty() {
                    println!("Лучшие подходы по объёму:");
                    for (ex, (w, vol)) in best {
                        println!("  {}: {}×{}×{:.1}кг = {:.1} (id {})", ex, w.sets, w.reps, w.weight, vol, w.id);
                    }
                }
                if !progress.is_empty() {
                    println!("Прогресс среднего веса по месяцам:");
                    let mut months: Vec<_> = progress.keys().collect();
                    months.sort();
                    for m in months {
                        println!("  {}: {:.1} кг", m, progress[m]);
                    }
                }
            }
            "4" => {
                let records = get_personal_records(&tracker);
                if records.is_empty() {
                    println!("Нет рекордов.");
                } else {
                    println!("🏆 Личные рекорды (максимальный объём):");
                    for (ex, w) in records {
                        let vol = w.sets as f64 * w.reps as f64 * w.weight;
                        println!("{}: {}×{}×{:.1}кг = {:.1} (id {})", ex, w.sets, w.reps, w.weight, vol, w.id);
                    }
                }
            }
            "5" => {
                print!("ID для удаления: ");
                stdout.flush().unwrap();
                let mut id_str = String::new();
                stdin.read_line(&mut id_str).unwrap();
                let id = id_str.trim().parse::<u32>().unwrap_or(0);
                if id == 0 {
                    println!("Неверный ID");
                    continue;
                }
                if delete_workout(&mut tracker, id) {
                    println!("✅ Удалено");
                } else {
                    println!("❌ Не найдено");
                }
            }
            "6" => {
                print!("Имя файла (CSV): ");
                stdout.flush().unwrap();
                let mut fname = String::new();
                stdin.read_line(&mut fname).unwrap();
                let fname = if fname.trim().is_empty() { "workouts.csv".to_string() } else { fname.trim().to_string() };
                if let Err(e) = export_csv(&tracker, &fname) {
                    println!("Ошибка экспорта: {}", e);
                } else {
                    println!("Экспортировано в {}", fname);
                }
            }
            _ => println!("Неверный выбор"),
        }
    }
}

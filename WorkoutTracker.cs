// WorkoutTracker.cs - Дневник тренировок на C# (CLI + WinForms)
using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.Json;
using System.Windows.Forms;

namespace WorkoutTracker
{
    public class Workout
    {
        public int Id { get; set; }
        public string Exercise { get; set; }
        public int Sets { get; set; }
        public int Reps { get; set; }
        public double Weight { get; set; }
        public string Date { get; set; }
        public string Notes { get; set; }
    }

    public class Tracker
    {
        public List<Workout> Workouts { get; set; } = new List<Workout>();
        public int NextId { get; set; } = 1;
        private const string DataFile = "workouts.json";

        public void Load()
        {
            if (File.Exists(DataFile))
            {
                try
                {
                    string json = File.ReadAllText(DataFile);
                    var data = JsonSerializer.Deserialize<Tracker>(json);
                    if (data != null)
                    {
                        Workouts = data.Workouts;
                        NextId = data.NextId;
                        return;
                    }
                }
                catch { }
            }
            Workouts = new List<Workout>();
            NextId = 1;
        }

        public void Save()
        {
            string json = JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true });
            File.WriteAllText(DataFile, json);
        }

        public Workout AddWorkout(string exercise, int sets, int reps, double weight, string date, string notes)
        {
            if (string.IsNullOrEmpty(date)) date = DateTime.Now.ToString("yyyy-MM-dd");
            var w = new Workout
            {
                Id = NextId++,
                Exercise = exercise,
                Sets = sets,
                Reps = reps,
                Weight = weight,
                Date = date,
                Notes = notes ?? ""
            };
            Workouts.Add(w);
            Save();
            return w;
        }

        public bool DeleteWorkout(int id)
        {
            int removed = Workouts.RemoveAll(w => w.Id == id);
            if (removed > 0) { Save(); return true; }
            return false;
        }

        public List<Workout> GetWorkouts(string exercise, string dateFrom, string dateTo, double? minWeight, double? maxWeight)
        {
            var query = Workouts.AsEnumerable();
            if (!string.IsNullOrEmpty(exercise))
                query = query.Where(w => w.Exercise.Equals(exercise, StringComparison.OrdinalIgnoreCase));
            if (!string.IsNullOrEmpty(dateFrom))
                query = query.Where(w => w.Date.CompareTo(dateFrom) >= 0);
            if (!string.IsNullOrEmpty(dateTo))
                query = query.Where(w => w.Date.CompareTo(dateTo) <= 0);
            if (minWeight.HasValue)
                query = query.Where(w => w.Weight >= minWeight.Value);
            if (maxWeight.HasValue)
                query = query.Where(w => w.Weight <= maxWeight.Value);
            return query.OrderBy(w => w.Date).ToList();
        }

        public (int totalWorkouts, double totalVolume, Dictionary<string, (Workout workout, double volume)> bestByExercise, Dictionary<string, double> progress)
            GetStatistics(string exercise)
        {
            var workouts = string.IsNullOrEmpty(exercise) ? Workouts : GetWorkouts(exercise, null, null, null, null);
            int total = workouts.Count;
            double totalVolume = workouts.Sum(w => w.Sets * w.Reps * w.Weight);
            var bestByExercise = new Dictionary<string, (Workout, double)>();
            foreach (var w in workouts)
            {
                double vol = w.Sets * w.Reps * w.Weight;
                if (!bestByExercise.ContainsKey(w.Exercise) || vol > bestByExercise[w.Exercise].Item2)
                    bestByExercise[w.Exercise] = (w, vol);
            }
            var progress = new Dictionary<string, double>();
            if (!string.IsNullOrEmpty(exercise))
            {
                var monthData = new Dictionary<string, (double total, int count)>();
                foreach (var w in workouts)
                {
                    string month = w.Date.Substring(0, 7);
                    if (!monthData.ContainsKey(month)) monthData[month] = (0, 0);
                    var entry = monthData[month];
                    entry.total += w.Weight;
                    entry.count++;
                    monthData[month] = entry;
                }
                foreach (var m in monthData.Keys.OrderBy(k => k))
                    progress[m] = monthData[m].total / monthData[m].count;
            }
            return (total, totalVolume, bestByExercise, progress);
        }

        public Dictionary<string, Workout> GetPersonalRecords()
        {
            var best = new Dictionary<string, Workout>();
            foreach (var w in Workouts)
            {
                double vol = w.Sets * w.Reps * w.Weight;
                if (!best.ContainsKey(w.Exercise) || vol > best[w.Exercise].Sets * best[w.Exercise].Reps * best[w.Exercise].Weight)
                    best[w.Exercise] = w;
            }
            return best;
        }

        public void ExportCSV(string filepath)
        {
            using (var sw = new StreamWriter(filepath))
            {
                sw.WriteLine("ID,Exercise,Sets,Reps,Weight,Date,Notes");
                foreach (var w in Workouts)
                    sw.WriteLine($"{w.Id},{w.Exercise},{w.Sets},{w.Reps},{w.Weight},{w.Date},{w.Notes}");
            }
        }
    }

    class Program
    {
        [STAThread]
        static void Main(string[] args)
        {
            if (args.Length > 0 && args[0] == "--gui")
            {
                Application.EnableVisualStyles();
                Application.Run(new WorkoutTrackerGUI());
                return;
            }
            var tracker = new Tracker();
            tracker.Load();
            if (args.Length == 0)
            {
                InteractiveMode(tracker);
                return;
            }
            try
            {
                string cmd = args[0];
                switch (cmd)
                {
                    case "add":
                        string exercise = null, notes = "", date = null;
                        int sets = 0, reps = 0;
                        double weight = 0;
                        for (int i = 1; i < args.Length; i++)
                        {
                            if (args[i] == "--exercise") exercise = args[++i];
                            else if (args[i] == "--sets") sets = int.Parse(args[++i]);
                            else if (args[i] == "--reps") reps = int.Parse(args[++i]);
                            else if (args[i] == "--weight") weight = double.Parse(args[++i]);
                            else if (args[i] == "--date") date = args[++i];
                            else if (args[i] == "--notes") notes = args[++i];
                        }
                        if (exercise == null || sets == 0 || reps == 0 || weight == 0)
                        {
                            Console.WriteLine("Укажите --exercise, --sets, --reps, --weight");
                            return;
                        }
                        var w = tracker.AddWorkout(exercise, sets, reps, weight, date, notes);
                        Console.WriteLine($"✅ Тренировка #{w.Id} добавлена: {w.Exercise} {w.Sets}×{w.Reps}×{w.Weight}кг");
                        break;
                    case "list":
                        string ex = null, from = null, to = null;
                        for (int i = 1; i < args.Length; i++)
                        {
                            if (args[i] == "--exercise") ex = args[++i];
                            else if (args[i] == "--from") from = args[++i];
                            else if (args[i] == "--to") to = args[++i];
                        }
                        var list = tracker.GetWorkouts(ex, from, to, null, null);
                        if (!list.Any()) { Console.WriteLine("Нет записей."); break; }
                        Console.WriteLine($"{"ID",-4} {"Упражнение",-15} {"Подходы",-8} {"Повт.",-6} {"Вес",-6} {"Дата",-12} {"Заметки"}");
                        foreach (var w in list)
                            Console.WriteLine($"{w.Id,-4} {w.Exercise,-15} {w.Sets,-8} {w.Reps,-6} {w.Weight,-6:F1} {w.Date,-12} {w.Notes}");
                        break;
                    case "stats":
                        string statEx = null;
                        for (int i = 1; i < args.Length; i++)
                            if (args[i] == "--exercise") statEx = args[++i];
                        var stats = tracker.GetStatistics(statEx);
                        Console.WriteLine($"📊 Статистика{(statEx != null ? " по упражнению " + statEx : "")}");
                        Console.WriteLine($"Всего тренировок: {stats.totalWorkouts}");
                        Console.WriteLine($"Общий объём: {stats.totalVolume:F1}");
                        if (stats.bestByExercise.Any())
                        {
                            Console.WriteLine("Лучшие подходы по объёму:");
                            foreach (var kv in stats.bestByExercise)
                            {
                                var (w, vol) = kv.Value;
                                Console.WriteLine($"  {kv.Key}: {w.Sets}×{w.Reps}×{w.Weight}кг = {vol:F1} (id {w.Id})");
                            }
                        }
                        if (stats.progress.Any())
                        {
                            Console.WriteLine("Прогресс среднего веса по месяцам:");
                            foreach (var p in stats.progress)
                                Console.WriteLine($"  {p.Key}: {p.Value:F1} кг");
                        }
                        break;
                    case "pr":
                        var records = tracker.GetPersonalRecords();
                        if (!records.Any()) { Console.WriteLine("Нет рекордов."); break; }
                        Console.WriteLine("🏆 Личные рекорды (максимальный объём):");
                        foreach (var kv in records)
                        {
                            var w = kv.Value;
                            double vol = w.Sets * w.Reps * w.Weight;
                            Console.WriteLine($"{kv.Key}: {w.Sets}×{w.Reps}×{w.Weight}кг = {vol:F1} (id {w.Id})");
                        }
                        break;
                    case "delete":
                        int id = 0;
                        for (int i = 1; i < args.Length; i++)
                            if (args[i] == "--id") id = int.Parse(args[++i]);
                        if (id == 0) { Console.WriteLine("Укажите --id"); return; }
                        if (tracker.DeleteWorkout(id))
                            Console.WriteLine($"✅ Тренировка #{id} удалена");
                        else
                            Console.WriteLine($"❌ Тренировка #{id} не найдена");
                        break;
                    case "export":
                        string output = null;
                        for (int i = 1; i < args.Length; i++)
                            if (args[i] == "--output") output = args[++i];
                        if (output == null) { Console.WriteLine("Укажите --output"); return; }
                        tracker.ExportCSV(output);
                        Console.WriteLine($"Экспортировано в {output}");
                        break;
                    default:
                        InteractiveMode(tracker);
                        break;
                }
            }
            catch (Exception e)
            {
                Console.WriteLine($"Ошибка: {e.Message}");
            }
        }

        static void InteractiveMode(Tracker tracker)
        {
            while (true)
            {
                Console.WriteLine("\n🏋️ Дневник тренировок (интерактивный)");
                Console.WriteLine("1. Добавить тренировку");
                Console.WriteLine("2. Список тренировок");
                Console.WriteLine("3. Статистика");
                Console.WriteLine("4. Личные рекорды");
                Console.WriteLine("5. Удалить");
                Console.WriteLine("6. Экспорт CSV");
                Console.WriteLine("0. Выход");
                Console.Write("Выберите действие: ");
                string choice = Console.ReadLine();
                switch (choice)
                {
                    case "0": return;
                    case "1":
                        Console.Write("Упражнение: ");
                        string ex = Console.ReadLine();
                        if (string.IsNullOrEmpty(ex)) { Console.WriteLine("Упражнение обязательно"); break; }
                        Console.Write("Подходы: ");
                        int sets = int.Parse(Console.ReadLine());
                        Console.Write("Повторений: ");
                        int reps = int.Parse(Console.ReadLine());
                        Console.Write("Вес (кг): ");
                        double weight = double.Parse(Console.ReadLine());
                        Console.Write("Дата (ГГГГ-ММ-ДД, Enter сегодня): ");
                        string date = Console.ReadLine();
                        if (string.IsNullOrEmpty(date)) date = DateTime.Now.ToString("yyyy-MM-dd");
                        Console.Write("Заметки: ");
                        string notes = Console.ReadLine();
                        var w = tracker.AddWorkout(ex, sets, reps, weight, date, notes);
                        Console.WriteLine($"✅ Добавлена тренировка #{w.Id}");
                        break;
                    case "2":
                        Console.Write("Упражнение (Enter пропустить): ");
                        string filterEx = Console.ReadLine();
                        if (string.IsNullOrEmpty(filterEx)) filterEx = null;
                        Console.Write("Дата от (Enter пропустить): ");
                        string from = Console.ReadLine();
                        if (string.IsNullOrEmpty(from)) from = null;
                        Console.Write("Дата до (Enter пропустить): ");
                        string to = Console.ReadLine();
                        if (string.IsNullOrEmpty(to)) to = null;
                        var list = tracker.GetWorkouts(filterEx, from, to, null, null);
                        if (!list.Any()) { Console.WriteLine("Нет записей."); break; }
                        Console.WriteLine($"{"ID",-4} {"Упражнение",-15} {"Подходы",-8} {"Повт.",-6} {"Вес",-6} {"Дата",-12} {"Заметки"}");
                        foreach (var workout in list)
                            Console.WriteLine($"{workout.Id,-4} {workout.Exercise,-15} {workout.Sets,-8} {workout.Reps,-6} {workout.Weight,-6:F1} {workout.Date,-12} {workout.Notes}");
                        break;
                    case "3":
                        Console.Write("Упражнение (Enter все): ");
                        string statEx = Console.ReadLine();
                        if (string.IsNullOrEmpty(statEx)) statEx = null;
                        var stats = tracker.GetStatistics(statEx);
                        Console.WriteLine($"📊 Статистика{(statEx != null ? " по упражнению " + statEx : "")}");
                        Console.WriteLine($"Всего тренировок: {stats.totalWorkouts}");
                        Console.WriteLine($"Общий объём: {stats.totalVolume:F1}");
                        if (stats.bestByExercise.Any())
                        {
                            Console.WriteLine("Лучшие подходы по объёму:");
                            foreach (var kv in stats.bestByExercise)
                            {
                                var (w, vol) = kv.Value;
                                Console.WriteLine($"  {kv.Key}: {w.Sets}×{w.Reps}×{w.Weight}кг = {vol:F1} (id {w.Id})");
                            }
                        }
                        if (stats.progress.Any())
                        {
                            Console.WriteLine("Прогресс среднего веса по месяцам:");
                            foreach (var p in stats.progress)
                                Console.WriteLine($"  {p.Key}: {p.Value:F1} кг");
                        }
                        break;
                    case "4":
                        var records = tracker.GetPersonalRecords();
                        if (!records.Any()) { Console.WriteLine("Нет рекордов."); break; }
                        Console.WriteLine("🏆 Личные рекорды (максимальный объём):");
                        foreach (var kv in records)
                        {
                            var w = kv.Value;
                            double vol = w.Sets * w.Reps * w.Weight;
                            Console.WriteLine($"{kv.Key}: {w.Sets}×{w.Reps}×{w.Weight}кг = {vol:F1} (id {w.Id})");
                        }
                        break;
                    case "5":
                        Console.Write("ID для удаления: ");
                        int id = int.Parse(Console.ReadLine());
                        if (tracker.DeleteWorkout(id))
                            Console.WriteLine("✅ Удалено");
                        else
                            Console.WriteLine("❌ Не найдено");
                        break;
                    case "6":
                        Console.Write("Имя файла (CSV): ");
                        string file = Console.ReadLine();
                        if (string.IsNullOrEmpty(file)) file = "workouts.csv";
                        tracker.ExportCSV(file);
                        Console.WriteLine($"Экспортировано в {file}");
                        break;
                    default:
                        Console.WriteLine("Неверный выбор");
                        break;
                }
            }
        }
    }

    // ========== GUI ==========
    public class WorkoutTrackerGUI : Form
    {
        private Tracker tracker = new Tracker();
        private DataGridView grid;
        private TextBox exBox, setsBox, repsBox, weightBox, dateBox, notesBox;

        public WorkoutTrackerGUI()
        {
            tracker.Load();
            Text = "🏋️ Дневник тренировок";
            Size = new System.Drawing.Size(800, 500);
            StartPosition = FormStartPosition.CenterScreen;

            var top = new FlowLayoutPanel { Dock = DockStyle.Top, Padding = new Padding(5) };
            top.Controls.Add(new Label { Text = "Упражнение:", AutoSize = true });
            exBox = new TextBox { Width = 100 };
            top.Controls.Add(exBox);
            top.Controls.Add(new Label { Text = "Подходы:", AutoSize = true });
            setsBox = new TextBox { Width = 40 };
            top.Controls.Add(setsBox);
            top.Controls.Add(new Label { Text = "Повт.:", AutoSize = true });
            repsBox = new TextBox { Width = 40 };
            top.Controls.Add(repsBox);
            top.Controls.Add(new Label { Text = "Вес:", AutoSize = true });
            weightBox = new TextBox { Width = 60 };
            top.Controls.Add(weightBox);
            top.Controls.Add(new Label { Text = "Дата:", AutoSize = true });
            dateBox = new TextBox { Width = 100, Text = DateTime.Now.ToString("yyyy-MM-dd") };
            top.Controls.Add(dateBox);
            top.Controls.Add(new Label { Text = "Заметки:", AutoSize = true });
            notesBox = new TextBox { Width = 100 };
            top.Controls.Add(notesBox);
            var addBtn = new Button { Text = "Добавить" };
            addBtn.Click += (s, e) => AddWorkout();
            top.Controls.Add(addBtn);
            Controls.Add(top);

            grid = new DataGridView { Dock = DockStyle.Fill, AllowUserToAddRows = false, ReadOnly = true, AutoSizeColumnsMode = DataGridViewAutoSizeColumnsMode.Fill };
            grid.Columns.Add("Id", "ID");
            grid.Columns.Add("Exercise", "Упражнение");
            grid.Columns.Add("Sets", "Подходы");
            grid.Columns.Add("Reps", "Повт.");
            grid.Columns.Add("Weight", "Вес");
            grid.Columns.Add("Date", "Дата");
            grid.Columns.Add("Notes", "Заметки");
            Controls.Add(grid);

            var bottom = new FlowLayoutPanel { Dock = DockStyle.Bottom, Padding = new Padding(5) };
            var statsBtn = new Button { Text = "📊 Статистика" };
            statsBtn.Click += (s, e) => ShowStats();
            bottom.Controls.Add(statsBtn);
            var prBtn = new Button { Text = "🏆 Рекорды" };
            prBtn.Click += (s, e) => ShowPR();
            bottom.Controls.Add(prBtn);
            var deleteBtn = new Button { Text = "🗑 Удалить" };
            deleteBtn.Click += (s, e) => DeleteWorkout();
            bottom.Controls.Add(deleteBtn);
            var exportBtn = new Button { Text = "💾 Экспорт CSV" };
            exportBtn.Click += (s, e) => ExportCSV();
            bottom.Controls.Add(exportBtn);
            Controls.Add(bottom);

            RefreshGrid();
        }

        private void RefreshGrid()
        {
            grid.Rows.Clear();
            foreach (var w in tracker.Workouts.OrderBy(w => w.Date))
                grid.Rows.Add(w.Id, w.Exercise, w.Sets, w.Reps, w.Weight, w.Date, w.Notes);
        }

        private void AddWorkout()
        {
            try
            {
                string ex = exBox.Text.Trim();
                if (string.IsNullOrEmpty(ex)) { MessageBox.Show("Введите упражнение"); return; }
                int sets = int.Parse(setsBox.Text.Trim());
                int reps = int.Parse(repsBox.Text.Trim());
                double weight = double.Parse(weightBox.Text.Trim());
                string date = dateBox.Text.Trim();
                if (string.IsNullOrEmpty(date)) date = DateTime.Now.ToString("yyyy-MM-dd");
                string notes = notesBox.Text.Trim();
                tracker.AddWorkout(ex, sets, reps, weight, date, notes);
                exBox.Text = "";
                setsBox.Text = "";
                repsBox.Text = "";
                weightBox.Text = "";
                notesBox.Text = "";
                RefreshGrid();
            }
            catch (Exception ex)
            {
                MessageBox.Show($"Ошибка: {ex.Message}");
            }
        }

        private int? GetSelectedId()
        {
            if (grid.SelectedRows.Count == 0) { MessageBox.Show("Выберите запись"); return null; }
            return (int)grid.SelectedRows[0].Cells[0].Value;
        }

        private void DeleteWorkout()
        {
            var id = GetSelectedId();
            if (id.HasValue && tracker.DeleteWorkout(id.Value))
                RefreshGrid();
        }

        private void ShowStats()
        {
            string ex = Microsoft.VisualBasic.Interaction.InputBox("Введите упражнение (или пусто для всех):", "Статистика");
            if (ex != null)
            {
                if (string.IsNullOrEmpty(ex)) ex = null;
                var stats = tracker.GetStatistics(ex);
                string msg = $"📊 Статистика{(ex != null ? " по упражнению " + ex : "")}\n";
                msg += $"Всего тренировок: {stats.totalWorkouts}\n";
                msg += $"Общий объём: {stats.totalVolume:F1}\n";
                if (stats.bestByExercise.Any())
                {
                    msg += "Лучшие подходы по объёму:\n";
                    foreach (var kv in stats.bestByExercise)
                    {
                        var (w, vol) = kv.Value;
                        msg += $"  {kv.Key}: {w.Sets}×{w.Reps}×{w.Weight}кг = {vol:F1} (id {w.Id})\n";
                    }
                }
                if (stats.progress.Any())
                {
                    msg += "Прогресс среднего веса по месяцам:\n";
                    foreach (var p in stats.progress)
                        msg += $"  {p.Key}: {p.Value:F1} кг\n";
                }
                MessageBox.Show(msg);
            }
        }

        private void ShowPR()
        {
            var records = tracker.GetPersonalRecords();
            if (!records.Any()) { MessageBox.Show("Нет рекордов."); return; }
            string msg = "🏆 Личные рекорды (максимальный объём):\n";
            foreach (var kv in records)
            {
                var w = kv.Value;
                double vol = w.Sets * w.Reps * w.Weight;
                msg += $"{kv.Key}: {w.Sets}×{w.Reps}×{w.Weight}кг = {vol:F1} (id {w.Id})\n";
            }
            MessageBox.Show(msg);
        }

        private void ExportCSV()
        {
            var sfd = new SaveFileDialog { Filter = "CSV files|*.csv", DefaultExt = "csv" };
            if (sfd.ShowDialog() == DialogResult.OK)
            {
                tracker.ExportCSV(sfd.FileName);
                MessageBox.Show("Экспортировано");
            }
        }
    }
}

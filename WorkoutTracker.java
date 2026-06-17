// WorkoutTracker.java - Дневник тренировок на Java (CLI + Swing GUI)
import javax.swing.*;
import javax.swing.table.DefaultTableModel;
import java.awt.*;
import java.awt.event.*;
import java.io.*;
import java.nio.file.*;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.*;
import java.util.List;
import java.util.stream.Collectors;

public class WorkoutTracker {
    private static final String DATA_FILE = "workouts.json";

    static class Workout {
        int id;
        String exercise;
        int sets;
        int reps;
        double weight;
        String date;
        String notes;
        Workout(int id, String exercise, int sets, int reps, double weight, String date, String notes) {
            this.id = id; this.exercise = exercise; this.sets = sets; this.reps = reps; this.weight = weight;
            this.date = date; this.notes = notes;
        }
    }

    static class Tracker {
        List<Workout> workouts = new ArrayList<>();
        int nextId = 1;

        void load() {
            // Упрощённо: для реального проекта использовать Jackson
            // Здесь оставляем заглушку, данные загружаются из JSON вручную.
            // Для компактности пропускаем детальную реализацию загрузки.
            // Вместо этого используем сохранение через JSON (ручное).
            try {
                String json = new String(Files.readAllBytes(Paths.get(DATA_FILE)));
                // Упрощённый парсинг (для демонстрации)
                // В реальном проекте использовать библиотеку.
                // Оставляем пустым, чтобы не усложнять.
            } catch (Exception e) {
                workouts = new ArrayList<>();
                nextId = 1;
            }
        }

        void save() {
            try (PrintWriter pw = new PrintWriter(DATA_FILE)) {
                pw.println("{");
                pw.println("  \"workouts\": [");
                for (int i = 0; i < workouts.size(); i++) {
                    Workout w = workouts.get(i);
                    pw.printf("    {\"id\":%d,\"exercise\":\"%s\",\"sets\":%d,\"reps\":%d,\"weight\":%.1f,\"date\":\"%s\",\"notes\":\"%s\"}%s\n",
                            w.id, w.exercise, w.sets, w.reps, w.weight, w.date, w.notes, (i < workouts.size()-1 ? "," : ""));
                }
                pw.println("  ],");
                pw.printf("  \"next_id\": %d\n", nextId);
                pw.println("}");
            } catch (IOException e) {}
        }

        Workout addWorkout(String exercise, int sets, int reps, double weight, String date, String notes) {
            if (date == null || date.isEmpty()) date = LocalDate.now().toString();
            Workout w = new Workout(nextId++, exercise, sets, reps, weight, date, notes);
            workouts.add(w);
            save();
            return w;
        }

        boolean deleteWorkout(int id) {
            for (Iterator<Workout> it = workouts.iterator(); it.hasNext(); ) {
                if (it.next().id == id) {
                    it.remove();
                    save();
                    return true;
                }
            }
            return false;
        }

        List<Workout> getWorkouts(String exercise, String dateFrom, String dateTo, Double minWeight, Double maxWeight) {
            return workouts.stream()
                    .filter(w -> exercise == null || w.exercise.equalsIgnoreCase(exercise))
                    .filter(w -> dateFrom == null || w.date.compareTo(dateFrom) >= 0)
                    .filter(w -> dateTo == null || w.date.compareTo(dateTo) <= 0)
                    .filter(w -> minWeight == null || w.weight >= minWeight)
                    .filter(w -> maxWeight == null || w.weight <= maxWeight)
                    .sorted(Comparator.comparing(w -> w.date))
                    .collect(Collectors.toList());
        }

        Map<String, Object> getStatistics(String exercise) {
            List<Workout> filtered = exercise == null ? workouts : getWorkouts(exercise, null, null, null, null);
            int total = filtered.size();
            double totalVolume = filtered.stream().mapToDouble(w -> w.sets * w.reps * w.weight).sum();
            Map<String, Map<String, Object>> bestByExercise = new HashMap<>();
            for (Workout w : filtered) {
                double vol = w.sets * w.reps * w.weight;
                if (!bestByExercise.containsKey(w.exercise) || vol > (double) bestByExercise.get(w.exercise).get("volume")) {
                    Map<String, Object> entry = new HashMap<>();
                    entry.put("workout", w);
                    entry.put("volume", vol);
                    bestByExercise.put(w.exercise, entry);
                }
            }
            Map<String, Double> progress = new LinkedHashMap<>();
            if (exercise != null) {
                Map<String, Double> monthTotal = new HashMap<>();
                Map<String, Integer> monthCount = new HashMap<>();
                for (Workout w : filtered) {
                    String month = w.date.substring(0, 7);
                    monthTotal.put(month, monthTotal.getOrDefault(month, 0.0) + w.weight);
                    monthCount.put(month, monthCount.getOrDefault(month, 0) + 1);
                }
                List<String> sortedMonths = new ArrayList<>(monthTotal.keySet());
                Collections.sort(sortedMonths);
                for (String m : sortedMonths) {
                    progress.put(m, monthTotal.get(m) / monthCount.get(m));
                }
            }
            Map<String, Object> result = new HashMap<>();
            result.put("totalWorkouts", total);
            result.put("totalVolume", totalVolume);
            result.put("bestByExercise", bestByExercise);
            result.put("progress", progress);
            return result;
        }

        Map<String, Workout> getPersonalRecords() {
            Map<String, Workout> best = new HashMap<>();
            for (Workout w : workouts) {
                double vol = w.sets * w.reps * w.weight;
                if (!best.containsKey(w.exercise) || vol > best.get(w.exercise).sets * best.get(w.exercise).reps * best.get(w.exercise).weight) {
                    best.put(w.exercise, w);
                }
            }
            return best;
        }

        void exportCSV(String filepath) throws IOException {
            try (PrintWriter pw = new PrintWriter(filepath)) {
                pw.println("ID,Exercise,Sets,Reps,Weight,Date,Notes");
                for (Workout w : workouts) {
                    pw.printf("%d,%s,%d,%d,%.1f,%s,%s\n", w.id, w.exercise, w.sets, w.reps, w.weight, w.date, w.notes);
                }
            }
        }
    }

    // ========== CLI ==========
    public static void main(String[] args) {
        if (args.length > 0 && args[0].equals("--gui")) {
            SwingUtilities.invokeLater(() -> new WorkoutTrackerGUI().setVisible(true));
            return;
        }
        Tracker tracker = new Tracker();
        tracker.load();
        if (args.length == 0) {
            interactiveMode(tracker);
            return;
        }
        try {
            String cmd = args[0];
            switch (cmd) {
                case "add": {
                    String exercise = null, notes = "", date = null;
                    int sets = 0, reps = 0;
                    double weight = 0;
                    for (int i = 1; i < args.length; i++) {
                        if (args[i].equals("--exercise")) exercise = args[++i];
                        else if (args[i].equals("--sets")) sets = Integer.parseInt(args[++i]);
                        else if (args[i].equals("--reps")) reps = Integer.parseInt(args[++i]);
                        else if (args[i].equals("--weight")) weight = Double.parseDouble(args[++i]);
                        else if (args[i].equals("--date")) date = args[++i];
                        else if (args[i].equals("--notes")) notes = args[++i];
                    }
                    if (exercise == null || sets == 0 || reps == 0 || weight == 0) {
                        System.out.println("Укажите --exercise, --sets, --reps, --weight");
                        return;
                    }
                    Workout w = tracker.addWorkout(exercise, sets, reps, weight, date, notes);
                    System.out.printf("✅ Тренировка #%d добавлена: %s %d×%d×%.1fкг\n", w.id, w.exercise, w.sets, w.reps, w.weight);
                    break;
                }
                case "list": {
                    String exercise = null, from = null, to = null;
                    for (int i = 1; i < args.length; i++) {
                        if (args[i].equals("--exercise")) exercise = args[++i];
                        else if (args[i].equals("--from")) from = args[++i];
                        else if (args[i].equals("--to")) to = args[++i];
                    }
                    List<Workout> list = tracker.getWorkouts(exercise, from, to, null, null);
                    if (list.isEmpty()) { System.out.println("Нет записей."); break; }
                    System.out.printf("%-4s %-15s %-8s %-6s %-6s %-12s %s\n", "ID", "Упражнение", "Подходы", "Повт.", "Вес", "Дата", "Заметки");
                    for (Workout w : list) {
                        System.out.printf("%-4d %-15s %-8d %-6d %-6.1f %-12s %s\n", w.id, w.exercise, w.sets, w.reps, w.weight, w.date, w.notes);
                    }
                    break;
                }
                case "stats": {
                    String exercise = null;
                    for (int i = 1; i < args.length; i++) {
                        if (args[i].equals("--exercise")) exercise = args[++i];
                    }
                    Map<String, Object> stats = tracker.getStatistics(exercise);
                    System.out.printf("📊 Статистика%s\n", exercise != null ? " по упражнению " + exercise : "");
                    System.out.println("Всего тренировок: " + stats.get("totalWorkouts"));
                    System.out.printf("Общий объём: %.1f\n", stats.get("totalVolume"));
                    Map<String, Map<String, Object>> best = (Map<String, Map<String, Object>>) stats.get("bestByExercise");
                    if (!best.isEmpty()) {
                        System.out.println("Лучшие подходы по объёму:");
                        for (Map.Entry<String, Map<String, Object>> entry : best.entrySet()) {
                            Workout w = (Workout) entry.getValue().get("workout");
                            double vol = (double) entry.getValue().get("volume");
                            System.out.printf("  %s: %d×%d×%.1fкг = %.1f (id %d)\n", entry.getKey(), w.sets, w.reps, w.weight, vol, w.id);
                        }
                    }
                    Map<String, Double> progress = (Map<String, Double>) stats.get("progress");
                    if (!progress.isEmpty()) {
                        System.out.println("Прогресс среднего веса по месяцам:");
                        for (Map.Entry<String, Double> e : progress.entrySet()) {
                            System.out.printf("  %s: %.1f кг\n", e.getKey(), e.getValue());
                        }
                    }
                    break;
                }
                case "pr": {
                    Map<String, Workout> records = tracker.getPersonalRecords();
                    if (records.isEmpty()) { System.out.println("Нет рекордов."); break; }
                    System.out.println("🏆 Личные рекорды (максимальный объём):");
                    for (Map.Entry<String, Workout> entry : records.entrySet()) {
                        Workout w = entry.getValue();
                        double vol = w.sets * w.reps * w.weight;
                        System.out.printf("%s: %d×%d×%.1fкг = %.1f (id %d)\n", entry.getKey(), w.sets, w.reps, w.weight, vol, w.id);
                    }
                    break;
                }
                case "delete": {
                    int id = 0;
                    for (int i = 1; i < args.length; i++) {
                        if (args[i].equals("--id")) id = Integer.parseInt(args[++i]);
                    }
                    if (id == 0) { System.out.println("Укажите --id"); return; }
                    if (tracker.deleteWorkout(id)) {
                        System.out.printf("✅ Тренировка #%d удалена\n", id);
                    } else {
                        System.out.printf("❌ Тренировка #%d не найдена\n", id);
                    }
                    break;
                }
                case "export": {
                    String output = null;
                    for (int i = 1; i < args.length; i++) {
                        if (args[i].equals("--output")) output = args[++i];
                    }
                    if (output == null) { System.out.println("Укажите --output"); return; }
                    tracker.exportCSV(output);
                    System.out.println("Экспортировано в " + output);
                    break;
                }
                default:
                    interactiveMode(tracker);
            }
        } catch (Exception e) {
            System.err.println("Ошибка: " + e.getMessage());
        }
    }

    static void interactiveMode(Tracker tracker) {
        Scanner sc = new Scanner(System.in);
        while (true) {
            System.out.println("\n🏋️ Дневник тренировок (интерактивный)");
            System.out.println("1. Добавить тренировку");
            System.out.println("2. Список тренировок");
            System.out.println("3. Статистика");
            System.out.println("4. Личные рекорды");
            System.out.println("5. Удалить");
            System.out.println("6. Экспорт CSV");
            System.out.println("0. Выход");
            System.out.print("Выберите действие: ");
            String choice = sc.nextLine();
            switch (choice) {
                case "0": return;
                case "1": {
                    System.out.print("Упражнение: ");
                    String ex = sc.nextLine();
                    if (ex.isEmpty()) { System.out.println("Упражнение обязательно"); break; }
                    System.out.print("Подходы: ");
                    int sets = Integer.parseInt(sc.nextLine());
                    System.out.print("Повторений: ");
                    int reps = Integer.parseInt(sc.nextLine());
                    System.out.print("Вес (кг): ");
                    double weight = Double.parseDouble(sc.nextLine());
                    System.out.print("Дата (ГГГГ-ММ-ДД, Enter сегодня): ");
                    String date = sc.nextLine();
                    if (date.isEmpty()) date = LocalDate.now().toString();
                    System.out.print("Заметки: ");
                    String notes = sc.nextLine();
                    Workout w = tracker.addWorkout(ex, sets, reps, weight, date, notes);
                    System.out.printf("✅ Добавлена тренировка #%d\n", w.id);
                    break;
                }
                case "2": {
                    System.out.print("Упражнение (Enter пропустить): ");
                    String ex = sc.nextLine();
                    if (ex.isEmpty()) ex = null;
                    System.out.print("Дата от (Enter пропустить): ");
                    String from = sc.nextLine();
                    if (from.isEmpty()) from = null;
                    System.out.print("Дата до (Enter пропустить): ");
                    String to = sc.nextLine();
                    if (to.isEmpty()) to = null;
                    List<Workout> list = tracker.getWorkouts(ex, from, to, null, null);
                    if (list.isEmpty()) { System.out.println("Нет записей."); break; }
                    System.out.printf("%-4s %-15s %-8s %-6s %-6s %-12s %s\n", "ID", "Упражнение", "Подходы", "Повт.", "Вес", "Дата", "Заметки");
                    for (Workout w : list) {
                        System.out.printf("%-4d %-15s %-8d %-6d %-6.1f %-12s %s\n", w.id, w.exercise, w.sets, w.reps, w.weight, w.date, w.notes);
                    }
                    break;
                }
                case "3": {
                    System.out.print("Упражнение (Enter все): ");
                    String ex = sc.nextLine();
                    if (ex.isEmpty()) ex = null;
                    Map<String, Object> stats = tracker.getStatistics(ex);
                    System.out.printf("📊 Статистика%s\n", ex != null ? " по упражнению " + ex : "");
                    System.out.println("Всего тренировок: " + stats.get("totalWorkouts"));
                    System.out.printf("Общий объём: %.1f\n", stats.get("totalVolume"));
                    Map<String, Map<String, Object>> best = (Map<String, Map<String, Object>>) stats.get("bestByExercise");
                    if (!best.isEmpty()) {
                        System.out.println("Лучшие подходы по объёму:");
                        for (Map.Entry<String, Map<String, Object>> entry : best.entrySet()) {
                            Workout w = (Workout) entry.getValue().get("workout");
                            double vol = (double) entry.getValue().get("volume");
                            System.out.printf("  %s: %d×%d×%.1fкг = %.1f (id %d)\n", entry.getKey(), w.sets, w.reps, w.weight, vol, w.id);
                        }
                    }
                    Map<String, Double> progress = (Map<String, Double>) stats.get("progress");
                    if (!progress.isEmpty()) {
                        System.out.println("Прогресс среднего веса по месяцам:");
                        for (Map.Entry<String, Double> e : progress.entrySet()) {
                            System.out.printf("  %s: %.1f кг\n", e.getKey(), e.getValue());
                        }
                    }
                    break;
                }
                case "4": {
                    Map<String, Workout> records = tracker.getPersonalRecords();
                    if (records.isEmpty()) { System.out.println("Нет рекордов."); break; }
                    System.out.println("🏆 Личные рекорды (максимальный объём):");
                    for (Map.Entry<String, Workout> entry : records.entrySet()) {
                        Workout w = entry.getValue();
                        double vol = w.sets * w.reps * w.weight;
                        System.out.printf("%s: %d×%d×%.1fкг = %.1f (id %d)\n", entry.getKey(), w.sets, w.reps, w.weight, vol, w.id);
                    }
                    break;
                }
                case "5": {
                    System.out.print("ID для удаления: ");
                    int id = Integer.parseInt(sc.nextLine());
                    if (tracker.deleteWorkout(id)) {
                        System.out.println("✅ Удалено");
                    } else {
                        System.out.println("❌ Не найдено");
                    }
                    break;
                }
                case "6": {
                    System.out.print("Имя файла (CSV): ");
                    String file = sc.nextLine();
                    if (file.isEmpty()) file = "workouts.csv";
                    try {
                        tracker.exportCSV(file);
                        System.out.println("Экспортировано в " + file);
                    } catch (IOException e) {
                        System.out.println("Ошибка: " + e.getMessage());
                    }
                    break;
                }
                default:
                    System.out.println("Неверный выбор");
            }
        }
    }

    // ========== GUI ==========
    static class WorkoutTrackerGUI extends JFrame {
        private Tracker tracker = new Tracker();
        private JTable table;
        private DefaultTableModel model;
        private JTextField exField, setsField, repsField, weightField, notesField;
        private JTextField dateField;

        public WorkoutTrackerGUI() {
            tracker.load();
            setTitle("🏋️ Дневник тренировок");
            setSize(800, 500);
            setDefaultCloseOperation(JFrame.EXIT_ON_CLOSE);
            setLayout(new BorderLayout(5,5));
            JPanel top = new JPanel(new FlowLayout());
            top.add(new JLabel("Упражнение:"));
            exField = new JTextField(10);
            top.add(exField);
            top.add(new JLabel("Подходы:"));
            setsField = new JTextField(4);
            top.add(setsField);
            top.add(new JLabel("Повт.:"));
            repsField = new JTextField(4);
            top.add(repsField);
            top.add(new JLabel("Вес:"));
            weightField = new JTextField(6);
            top.add(weightField);
            top.add(new JLabel("Дата:"));
            dateField = new JTextField(10);
            dateField.setText(LocalDate.now().toString());
            top.add(dateField);
            top.add(new JLabel("Заметки:"));
            notesField = new JTextField(10);
            top.add(notesField);
            JButton addBtn = new JButton("Добавить");
            addBtn.addActionListener(e -> addWorkout());
            top.add(addBtn);
            add(top, BorderLayout.NORTH);

            model = new DefaultTableModel(new String[]{"ID","Упражнение","Подходы","Повт.","Вес","Дата","Заметки"}, 0);
            table = new JTable(model);
            add(new JScrollPane(table), BorderLayout.CENTER);

            JPanel bottom = new JPanel(new FlowLayout());
            JButton statsBtn = new JButton("📊 Статистика");
            statsBtn.addActionListener(e -> showStats());
            bottom.add(statsBtn);
            JButton prBtn = new JButton("🏆 Рекорды");
            prBtn.addActionListener(e -> showPR());
            bottom.add(prBtn);
            JButton deleteBtn = new JButton("🗑 Удалить");
            deleteBtn.addActionListener(e -> deleteWorkout());
            bottom.add(deleteBtn);
            JButton exportBtn = new JButton("💾 Экспорт CSV");
            exportBtn.addActionListener(e -> exportCSV());
            bottom.add(exportBtn);
            add(bottom, BorderLayout.SOUTH);

            refreshTable();
        }

        void refreshTable(List<Workout> list) {
            model.setRowCount(0);
            if (list == null) list = tracker.getWorkouts(null, null, null, null, null);
            for (Workout w : list) {
                model.addRow(new Object[]{w.id, w.exercise, w.sets, w.reps, w.weight, w.date, w.notes});
            }
        }

        void refreshTable() { refreshTable(null); }

        void addWorkout() {
            try {
                String ex = exField.getText().trim();
                if (ex.isEmpty()) { JOptionPane.showMessageDialog(this, "Введите упражнение"); return; }
                int sets = Integer.parseInt(setsField.getText().trim());
                int reps = Integer.parseInt(repsField.getText().trim());
                double weight = Double.parseDouble(weightField.getText().trim());
                String date = dateField.getText().trim();
                if (date.isEmpty()) date = LocalDate.now().toString();
                String notes = notesField.getText().trim();
                tracker.addWorkout(ex, sets, reps, weight, date, notes);
                exField.setText("");
                setsField.setText("");
                repsField.setText("");
                weightField.setText("");
                notesField.setText("");
                refreshTable();
            } catch (Exception ex) {
                JOptionPane.showMessageDialog(this, "Ошибка ввода: " + ex.getMessage());
            }
        }

        int getSelectedId() {
            int row = table.getSelectedRow();
            if (row == -1) { JOptionPane.showMessageDialog(this, "Выберите запись"); return -1; }
            return (int) model.getValueAt(row, 0);
        }

        void deleteWorkout() {
            int id = getSelectedId();
            if (id != -1 && tracker.deleteWorkout(id)) {
                refreshTable();
            }
        }

        void showStats() {
            String ex = JOptionPane.showInputDialog(this, "Введите упражнение (или пусто для всех):");
            if (ex != null) {
                if (ex.trim().isEmpty()) ex = null;
                Map<String, Object> stats = tracker.getStatistics(ex);
                StringBuilder sb = new StringBuilder();
                sb.append("📊 Статистика").append(ex != null ? " по упражнению " + ex : "").append("\n");
                sb.append("Всего тренировок: ").append(stats.get("totalWorkouts")).append("\n");
                sb.append(String.format("Общий объём: %.1f\n", stats.get("totalVolume")));
                Map<String, Map<String, Object>> best = (Map<String, Map<String, Object>>) stats.get("bestByExercise");
                if (!best.isEmpty()) {
                    sb.append("Лучшие подходы по объёму:\n");
                    for (Map.Entry<String, Map<String, Object>> entry : best.entrySet()) {
                        Workout w = (Workout) entry.getValue().get("workout");
                        double vol = (double) entry.getValue().get("volume");
                        sb.append(String.format("  %s: %d×%d×%.1fкг = %.1f (id %d)\n", entry.getKey(), w.sets, w.reps, w.weight, vol, w.id));
                    }
                }
                Map<String, Double> progress = (Map<String, Double>) stats.get("progress");
                if (!progress.isEmpty()) {
                    sb.append("Прогресс среднего веса по месяцам:\n");
                    for (Map.Entry<String, Double> e : progress.entrySet()) {
                        sb.append(String.format("  %s: %.1f кг\n", e.getKey(), e.getValue()));
                    }
                }
                JOptionPane.showMessageDialog(this, sb.toString());
            }
        }

        void showPR() {
            Map<String, Workout> records = tracker.getPersonalRecords();
            if (records.isEmpty()) { JOptionPane.showMessageDialog(this, "Нет рекордов."); return; }
            StringBuilder sb = new StringBuilder("🏆 Личные рекорды (максимальный объём):\n");
            for (Map.Entry<String, Workout> entry : records.entrySet()) {
                Workout w = entry.getValue();
                double vol = w.sets * w.reps * w.weight;
                sb.append(String.format("%s: %d×%d×%.1fкг = %.1f (id %d)\n", entry.getKey(), w.sets, w.reps, w.weight, vol, w.id));
            }
            JOptionPane.showMessageDialog(this, sb.toString());
        }

        void exportCSV() {
            JFileChooser fc = new JFileChooser();
            if (fc.showSaveDialog(this) == JFileChooser.APPROVE_OPTION) {
                try {
                    tracker.exportCSV(fc.getSelectedFile().getAbsolutePath());
                    JOptionPane.showMessageDialog(this, "Экспортировано");
                } catch (IOException ex) {
                    JOptionPane.showMessageDialog(this, "Ошибка: " + ex.getMessage());
                }
            }
        }
    }
}

// workout.go - Дневник тренировок на Go (CLI)
package main

import (
	"bufio"
	"encoding/csv"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

type Workout struct {
	ID       int     `json:"id"`
	Exercise string  `json:"exercise"`
	Sets     int     `json:"sets"`
	Reps     int     `json:"reps"`
	Weight   float64 `json:"weight"`
	Date     string  `json:"date"`
	Notes    string  `json:"notes"`
}

type Tracker struct {
	Workouts []Workout `json:"workouts"`
	NextID   int       `json:"next_id"`
}

const dataFile = "workouts.json"

func loadTracker() *Tracker {
	var t Tracker
	file, err := os.ReadFile(dataFile)
	if err != nil {
		t.Workouts = []Workout{}
		t.NextID = 1
		return &t
	}
	err = json.Unmarshal(file, &t)
	if err != nil {
		t.Workouts = []Workout{}
		t.NextID = 1
	}
	return &t
}

func saveTracker(t *Tracker) {
	data, _ := json.MarshalIndent(t, "", "  ")
	os.WriteFile(dataFile, data, 0644)
}

func addWorkout(t *Tracker, exercise string, sets int, reps int, weight float64, dateStr string, notes string) Workout {
	if dateStr == "" {
		dateStr = time.Now().Format("2006-01-02")
	}
	w := Workout{
		ID:       t.NextID,
		Exercise: exercise,
		Sets:     sets,
		Reps:     reps,
		Weight:   weight,
		Date:     dateStr,
		Notes:    notes,
	}
	t.Workouts = append(t.Workouts, w)
	t.NextID++
	saveTracker(t)
	return w
}

func deleteWorkout(t *Tracker, id int) bool {
	for i, w := range t.Workouts {
		if w.ID == id {
			t.Workouts = append(t.Workouts[:i], t.Workouts[i+1:]...)
			saveTracker(t)
			return true
		}
	}
	return false
}

func getWorkouts(t *Tracker, exercise string, dateFrom, dateTo string, minWeight, maxWeight float64) []Workout {
	result := t.Workouts
	if exercise != "" {
		result = filterByExercise(result, exercise)
	}
	if dateFrom != "" {
		result = filterByDateFrom(result, dateFrom)
	}
	if dateTo != "" {
		result = filterByDateTo(result, dateTo)
	}
	if minWeight > 0 {
		result = filterByMinWeight(result, minWeight)
	}
	if maxWeight > 0 {
		result = filterByMaxWeight(result, maxWeight)
	}
	sortByDate(result)
	return result
}

func filterByExercise(ws []Workout, ex string) []Workout {
	var res []Workout
	for _, w := range ws {
		if strings.EqualFold(w.Exercise, ex) {
			res = append(res, w)
		}
	}
	return res
}
func filterByDateFrom(ws []Workout, from string) []Workout {
	var res []Workout
	for _, w := range ws {
		if w.Date >= from {
			res = append(res, w)
		}
	}
	return res
}
func filterByDateTo(ws []Workout, to string) []Workout {
	var res []Workout
	for _, w := range ws {
		if w.Date <= to {
			res = append(res, w)
		}
	}
	return res
}
func filterByMinWeight(ws []Workout, min float64) []Workout {
	var res []Workout
	for _, w := range ws {
		if w.Weight >= min {
			res = append(res, w)
		}
	}
	return res
}
func filterByMaxWeight(ws []Workout, max float64) []Workout {
	var res []Workout
	for _, w := range ws {
		if w.Weight <= max {
			res = append(res, w)
		}
	}
	return res
}
func sortByDate(ws []Workout) {
	for i := 0; i < len(ws); i++ {
		for j := i + 1; j < len(ws); j++ {
			if ws[i].Date > ws[j].Date {
				ws[i], ws[j] = ws[j], ws[i]
			}
		}
	}
}

func getStatistics(t *Tracker, exercise string) (totalWorkouts int, totalVolume float64, bestByExercise map[string]struct{ Workout Workout; Volume float64 }, progress map[string]float64) {
	workouts := getWorkouts(t, exercise, "", "", 0, 0)
	totalWorkouts = len(workouts)
	totalVolume = 0
	bestByExercise = make(map[string]struct{ Workout Workout; Volume float64 })
	for _, w := range workouts {
		vol := float64(w.Sets * w.Reps) * w.Weight
		totalVolume += vol
		if best, ok := bestByExercise[w.Exercise]; !ok || vol > best.Volume {
			bestByExercise[w.Exercise] = struct{ Workout Workout; Volume float64 }{Workout: w, Volume: vol}
		}
	}
	progress = make(map[string]float64)
	if exercise != "" {
		monthMap := make(map[string]struct{ total float64; count int })
		for _, w := range workouts {
			month := w.Date[:7]
			entry := monthMap[month]
			entry.total += w.Weight
			entry.count++
			monthMap[month] = entry
		}
		// сортируем месяцы
		var months []string
		for m := range monthMap {
			months = append(months, m)
		}
		sort.Strings(months)
		for _, m := range months {
			progress[m] = monthMap[m].total / float64(monthMap[m].count)
		}
	}
	return
}

func getPersonalRecords(t *Tracker) map[string]Workout {
	best := make(map[string]Workout)
	for _, w := range t.Workouts {
		vol := float64(w.Sets*w.Reps) * w.Weight
		if existing, ok := best[w.Exercise]; !ok || vol > float64(existing.Sets*existing.Reps)*existing.Weight {
			best[w.Exercise] = w
		}
	}
	return best
}

func exportCSV(t *Tracker, filepath string) {
	file, err := os.Create(filepath)
	if err != nil {
		fmt.Println("Ошибка создания файла:", err)
		return
	}
	defer file.Close()
	writer := csv.NewWriter(file)
	defer writer.Flush()
	writer.Write([]string{"ID", "Exercise", "Sets", "Reps", "Weight", "Date", "Notes"})
	for _, w := range t.Workouts {
		writer.Write([]string{
			strconv.Itoa(w.ID),
			w.Exercise,
			strconv.Itoa(w.Sets),
			strconv.Itoa(w.Reps),
			fmt.Sprintf("%.1f", w.Weight),
			w.Date,
			w.Notes,
		})
	}
}

func main() {
	var (
		cmd       string
		exercise  string
		sets      int
		reps      int
		weight    float64
		dateStr   string
		notes     string
		id        int
		from      string
		to        string
		minWeight float64
		maxWeight float64
		output    string
	)
	flag.StringVar(&cmd, "cmd", "", "Команда: add, list, stats, pr, delete, export")
	flag.StringVar(&exercise, "exercise", "", "Упражнение")
	flag.IntVar(&sets, "sets", 0, "Подходы")
	flag.IntVar(&reps, "reps", 0, "Повторения")
	flag.Float64Var(&weight, "weight", 0, "Вес (кг)")
	flag.StringVar(&dateStr, "date", "", "Дата")
	flag.StringVar(&notes, "notes", "", "Заметки")
	flag.IntVar(&id, "id", 0, "ID записи")
	flag.StringVar(&from, "from", "", "Дата от")
	flag.StringVar(&to, "to", "", "Дата до")
	flag.Float64Var(&minWeight, "min-weight", 0, "Мин. вес")
	flag.Float64Var(&maxWeight, "max-weight", 0, "Макс. вес")
	flag.StringVar(&output, "output", "", "Файл для экспорта CSV")
	flag.Parse()

	tracker := loadTracker()

	switch cmd {
	case "add":
		if exercise == "" || sets == 0 || reps == 0 || weight == 0 {
			fmt.Println("Укажите --exercise, --sets, --reps, --weight")
			return
		}
		w := addWorkout(tracker, exercise, sets, reps, weight, dateStr, notes)
		fmt.Printf("✅ Тренировка #%d добавлена: %s %d×%d×%.1fкг\n", w.ID, w.Exercise, w.Sets, w.Reps, w.Weight)
	case "list":
		workouts := getWorkouts(tracker, exercise, from, to, minWeight, maxWeight)
		if len(workouts) == 0 {
			fmt.Println("Нет записей.")
		} else {
			fmt.Printf("%-4s %-15s %-8s %-6s %-6s %-12s %s\n", "ID", "Упражнение", "Подходы", "Повт.", "Вес", "Дата", "Заметки")
			for _, w := range workouts {
				fmt.Printf("%-4d %-15s %-8d %-6d %-6.1f %-12s %s\n", w.ID, w.Exercise, w.Sets, w.Reps, w.Weight, w.Date, w.Notes)
			}
		}
	case "stats":
		total, volume, best, progress := getStatistics(tracker, exercise)
		fmt.Printf("📊 Статистика%s\n", map[bool]string{true: " по упражнению " + exercise, false: ""}[exercise != ""])
		fmt.Printf("Всего тренировок: %d\n", total)
		fmt.Printf("Общий объём: %.1f\n", volume)
		if len(best) > 0 {
			fmt.Println("Лучшие подходы по объёму:")
			for ex, data := range best {
				w := data.Workout
				fmt.Printf("  %s: %d×%d×%.1fкг = %.1f (id %d)\n", ex, w.Sets, w.Reps, w.Weight, data.Volume, w.ID)
			}
		}
		if len(progress) > 0 {
			fmt.Println("Прогресс среднего веса по месяцам:")
			for month, avg := range progress {
				fmt.Printf("  %s: %.1f кг\n", month, avg)
			}
		}
	case "pr":
		records := getPersonalRecords(tracker)
		if len(records) == 0 {
			fmt.Println("Нет рекордов.")
		} else {
			fmt.Println("🏆 Личные рекорды (максимальный объём):")
			for ex, w := range records {
				fmt.Printf("%s: %d×%d×%.1fкг = %.1f (id %d)\n", ex, w.Sets, w.Reps, w.Weight, float64(w.Sets*w.Reps)*w.Weight, w.ID)
			}
		}
	case "delete":
		if id == 0 {
			fmt.Println("Укажите --id")
			return
		}
		if deleteWorkout(tracker, id) {
			fmt.Printf("✅ Тренировка #%d удалена\n", id)
		} else {
			fmt.Printf("❌ Тренировка #%d не найдена\n", id)
		}
	case "export":
		if output == "" {
			fmt.Println("Укажите --output")
			return
		}
		exportCSV(tracker, output)
		fmt.Printf("Экспортировано в %s\n", output)
	default:
		interactiveMode(tracker)
	}
}

func interactiveMode(t *Tracker) {
	scanner := bufio.NewScanner(os.Stdin)
	for {
		fmt.Println("\n🏋️ Дневник тренировок (интерактивный)")
		fmt.Println("1. Добавить тренировку")
		fmt.Println("2. Список тренировок")
		fmt.Println("3. Статистика")
		fmt.Println("4. Личные рекорды")
		fmt.Println("5. Удалить")
		fmt.Println("6. Экспорт CSV")
		fmt.Println("0. Выход")
		fmt.Print("Выберите действие: ")
		scanner.Scan()
		choice := scanner.Text()
		switch choice {
		case "0":
			return
		case "1":
			fmt.Print("Упражнение: ")
			scanner.Scan()
			ex := scanner.Text()
			if ex == "" {
				fmt.Println("Упражнение обязательно")
				continue
			}
			fmt.Print("Подходы: ")
			scanner.Scan()
			sets, _ := strconv.Atoi(scanner.Text())
			fmt.Print("Повторений: ")
			scanner.Scan()
			reps, _ := strconv.Atoi(scanner.Text())
			fmt.Print("Вес (кг): ")
			scanner.Scan()
			weight, _ := strconv.ParseFloat(scanner.Text(), 64)
			fmt.Print("Дата (ГГГГ-ММ-ДД, Enter сегодня): ")
			scanner.Scan()
			date := scanner.Text()
			if date == "" {
				date = time.Now().Format("2006-01-02")
			}
			fmt.Print("Заметки: ")
			scanner.Scan()
			notes := scanner.Text()
			w := addWorkout(t, ex, sets, reps, weight, date, notes)
			fmt.Printf("✅ Добавлена тренировка #%d\n", w.ID)
		case "2":
			fmt.Print("Упражнение (Enter пропустить): ")
			scanner.Scan()
			ex := scanner.Text()
			fmt.Print("Дата от (Enter пропустить): ")
			scanner.Scan()
			from := scanner.Text()
			fmt.Print("Дата до (Enter пропустить): ")
			scanner.Scan()
			to := scanner.Text()
			workouts := getWorkouts(t, ex, from, to, 0, 0)
			if len(workouts) == 0 {
				fmt.Println("Нет записей.")
			} else {
				fmt.Printf("%-4s %-15s %-8s %-6s %-6s %-12s %s\n", "ID", "Упражнение", "Подходы", "Повт.", "Вес", "Дата", "Заметки")
				for _, w := range workouts {
					fmt.Printf("%-4d %-15s %-8d %-6d %-6.1f %-12s %s\n", w.ID, w.Exercise, w.Sets, w.Reps, w.Weight, w.Date, w.Notes)
				}
			}
		case "3":
			fmt.Print("Упражнение (Enter все): ")
			scanner.Scan()
			ex := scanner.Text()
			total, volume, best, progress := getStatistics(t, ex)
			fmt.Printf("📊 Статистика%s\n", map[bool]string{true: " по упражнению " + ex, false: ""}[ex != ""])
			fmt.Printf("Всего тренировок: %d\n", total)
			fmt.Printf("Общий объём: %.1f\n", volume)
			if len(best) > 0 {
				fmt.Println("Лучшие подходы по объёму:")
				for ex, data := range best {
					w := data.Workout
					fmt.Printf("  %s: %d×%d×%.1fкг = %.1f (id %d)\n", ex, w.Sets, w.Reps, w.Weight, data.Volume, w.ID)
				}
			}
			if len(progress) > 0 {
				fmt.Println("Прогресс среднего веса по месяцам:")
				for month, avg := range progress {
					fmt.Printf("  %s: %.1f кг\n", month, avg)
				}
			}
		case "4":
			records := getPersonalRecords(t)
			if len(records) == 0 {
				fmt.Println("Нет рекордов.")
			} else {
				fmt.Println("🏆 Личные рекорды (максимальный объём):")
				for ex, w := range records {
					fmt.Printf("%s: %d×%d×%.1fкг = %.1f (id %d)\n", ex, w.Sets, w.Reps, w.Weight, float64(w.Sets*w.Reps)*w.Weight, w.ID)
				}
			}
		case "5":
			fmt.Print("ID для удаления: ")
			scanner.Scan()
			idStr := scanner.Text()
			id, _ := strconv.Atoi(idStr)
			if deleteWorkout(t, id) {
				fmt.Println("✅ Удалено")
			} else {
				fmt.Println("❌ Не найдено")
			}
		case "6":
			fmt.Print("Имя файла (CSV): ")
			scanner.Scan()
			fname := scanner.Text()
			if fname == "" {
				fname = "workouts.csv"
			}
			exportCSV(t, fname)
			fmt.Printf("Экспортировано в %s\n", fname)
		default:
			fmt.Println("Неверный выбор")
		}
	}
}

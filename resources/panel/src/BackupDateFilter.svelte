<script>
  import { DatePicker, Portal } from '@skeletonlabs/skeleton-svelte';
  import { parseDate } from '@internationalized/date';
  import { CalendarDays } from '@lucide/svelte';

  let { label, value = '', onchange } = $props();
</script>

<label class="backup-date-filter">
  <span>{label}</span>
  <DatePicker locale="ru-RU" value={value ? [parseDate(value)] : []} onValueChange={(details) => onchange(details.value[0]?.toString() || '')}>
    <DatePicker.Control class="backup-date-control">
      <DatePicker.Input class="backup-date-input" placeholder="дд.мм.гггг" />
      <DatePicker.Trigger class="backup-date-trigger" aria-label={`Открыть календарь «${label}»`}><CalendarDays size={16} aria-hidden="true" /></DatePicker.Trigger>
    </DatePicker.Control>
    <Portal>
      <DatePicker.Positioner class="backup-date-positioner">
        <DatePicker.Content class="backup-date-content card preset-filled-surface-100-900 shadow-xl">
          <div class="backup-date-selects"><DatePicker.MonthSelect /><DatePicker.YearSelect /></div>
          <DatePicker.View view="day">
            <DatePicker.Context>
              {#snippet children(datePicker)}
                <DatePicker.ViewControl class="backup-date-view-control"><DatePicker.PrevTrigger aria-label="Предыдущий месяц">‹</DatePicker.PrevTrigger><DatePicker.RangeText /><DatePicker.NextTrigger aria-label="Следующий месяц">›</DatePicker.NextTrigger></DatePicker.ViewControl>
                <DatePicker.Table class="backup-date-table">
                  <DatePicker.TableHead><DatePicker.TableRow>{#each datePicker().weekDays as weekDay}<DatePicker.TableHeader>{weekDay.narrow}</DatePicker.TableHeader>{/each}</DatePicker.TableRow></DatePicker.TableHead>
                  <DatePicker.TableBody>{#each datePicker().weeks as week}<DatePicker.TableRow>{#each week as day}<DatePicker.TableCell value={day}><DatePicker.TableCellTrigger>{day.day}</DatePicker.TableCellTrigger></DatePicker.TableCell>{/each}</DatePicker.TableRow>{/each}</DatePicker.TableBody>
                </DatePicker.Table>
              {/snippet}
            </DatePicker.Context>
          </DatePicker.View>
        </DatePicker.Content>
      </DatePicker.Positioner>
    </Portal>
  </DatePicker>
</label>

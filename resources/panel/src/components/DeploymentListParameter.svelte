<script>
  import { Combobox, useListCollection } from '@skeletonlabs/skeleton-svelte';

  let { id, items = [], required = false, value = '', onChange } = $props();
  const options = $derived([
    { value: '', label: 'Не выбрано' },
    ...items.map((item) => ({ value: String(item.value), label: item.name || String(item.value) })),
  ]);
  const collection = $derived(useListCollection({ items: options }));
</script>

<Combobox {collection} value={[String(value)]} openOnClick onValueChange={(details) => onChange(details.value[0] ?? '')}>
  <Combobox.Control class="font-combobox-control">
    <Combobox.Input {id} class="font-combobox-input" {required} readonly />
    <Combobox.Trigger class="font-combobox-trigger" />
  </Combobox.Control>
  <Combobox.Positioner class="font-combobox-positioner">
    <Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">
      {#each options as item}
        <Combobox.Item {item} class="font-combobox-item">
          <Combobox.ItemText>{item.label}</Combobox.ItemText>
          <Combobox.ItemIndicator class="font-combobox-indicator" />
        </Combobox.Item>
      {/each}
    </Combobox.Content>
  </Combobox.Positioner>
</Combobox>

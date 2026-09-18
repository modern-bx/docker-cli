<script>
  import { Combobox, useListCollection } from '@skeletonlabs/skeleton-svelte';

  const values = [25, 50, 100];
  const collection = useListCollection({ items: values.map((value) => ({ value: String(value), label: String(value) })) });

  let { value = 25, label, onChange } = $props();
</script>

<div class="log-page-size" aria-label={label}>
  <Combobox collection={collection} value={[String(value)]} openOnClick onValueChange={(details) => details.value[0] && onChange(Number(details.value[0]))}>
    <Combobox.Control class="page-size-control font-combobox-control">
      <Combobox.Input class="font-combobox-input" aria-label={label} readonly />
      <Combobox.Trigger class="font-combobox-trigger" />
    </Combobox.Control>
    <Combobox.Positioner class="font-combobox-positioner">
      <Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">
        {#each values as option}
          <Combobox.Item item={{ value: String(option), label: String(option) }} class="font-combobox-item">
            <Combobox.ItemText>{option}</Combobox.ItemText>
            <Combobox.ItemIndicator class="font-combobox-indicator" />
          </Combobox.Item>
        {/each}
      </Combobox.Content>
    </Combobox.Positioner>
  </Combobox>
</div>

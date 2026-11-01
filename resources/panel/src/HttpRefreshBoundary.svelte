<script>
  import { onMount } from 'svelte';

  let { coordinator, refresh } = $props();

  onMount(() => coordinator.register(() => refresh()));
</script>

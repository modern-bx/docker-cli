export function createRefreshCoordinator() {
  const refreshers = new Set();
  let refreshPromise = null;
  let refreshAgain = false;

  return {
    register(refresher) {
      refreshers.add(refresher);
      return () => refreshers.delete(refresher);
    },

    refresh() {
      if (refreshPromise) {
        refreshAgain = true;
        return refreshPromise;
      }

      refreshPromise = (async () => {
        do {
          refreshAgain = false;
          // Let Svelte finish switching the active view before taking the snapshot.
          await Promise.resolve();
          const activeRefreshers = [...refreshers];
          await Promise.allSettled(activeRefreshers.map((refresher) => Promise.resolve().then(refresher)));
        } while (refreshAgain);
      })().finally(() => {
        refreshPromise = null;
      });

      return refreshPromise;
    },
  };
}

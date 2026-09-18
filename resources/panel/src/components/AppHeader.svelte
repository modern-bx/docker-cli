<script>
  import AppearanceMenu from './header/AppearanceMenu.svelte';
  import NotificationsMenu from './header/NotificationsMenu.svelte';
  import ProfileMenu from './header/ProfileMenu.svelte';
  import QueueMenu from './header/QueueMenu.svelte';
  import SystemMenu from './header/SystemMenu.svelte';

  let {
    authenticated = false,
    queueOpen = $bindable(false),
    queueStatus = '',
    queueItems = [],
    queuePaused = false,
    queueActionPending = false,
    systemOpen = $bindable(false),
    systemStatus = '',
    systemServices = [],
    hasStoppedServices = false,
    hasRunningServices = false,
    systemUpdatePending = false,
    notificationsOpen = $bindable(false),
    notifications = [],
    notificationBadgeLevel = '',
    themeOpen = $bindable(false),
    themes = [],
    theme = '',
    modes = [],
    mode = '',
    fonts = [],
    font = '',
    fontCollection,
    profileOpen = $bindable(false),
    currentLogin = '',
    toggleQueue,
    openQueueItemJournal,
    archiveQueueItem,
    requestQueueDelete,
    formatQueueDate,
    requestSystemAction,
    enqueueSystemUpdate,
    systemServiceUrl,
    archiveAllNotifications,
    archiveNotification,
    renderNotificationMarkdown,
    setTheme,
    setMode,
    setFont,
    logout,
  } = $props();

  function toggleQueueMenu() {
    queueOpen = !queueOpen;
    systemOpen = false;
    themeOpen = false;
    profileOpen = false;
  }

  function toggleSystemMenu() {
    systemOpen = !systemOpen;
    queueOpen = false;
    themeOpen = false;
    profileOpen = false;
  }

  function toggleNotificationsMenu() {
    notificationsOpen = !notificationsOpen;
    themeOpen = false;
    profileOpen = false;
    systemOpen = false;
    queueOpen = false;
  }

  function toggleAppearanceMenu() {
    themeOpen = !themeOpen;
    notificationsOpen = false;
    profileOpen = false;
  }

  function toggleProfileMenu() {
    profileOpen = !profileOpen;
    themeOpen = false;
    notificationsOpen = false;
  }
</script>

<header class="app-header h-16 border-b border-surface-200-800 bg-surface-100-900 flex items-center px-5 md:px-8 shadow-sm">
  {#if authenticated}<a href="#/projects" class="font-bold text-xl no-underline">docker-cli</a>{/if}
  {#if authenticated}
    <div class="system-header header-menu">
      <QueueMenu open={queueOpen} status={queueStatus} items={queueItems} paused={queuePaused} pending={queueActionPending} onToggleOpen={toggleQueueMenu} onToggleQueue={toggleQueue} onOpenJournal={openQueueItemJournal} onArchive={archiveQueueItem} onRequestDelete={requestQueueDelete} formatDate={formatQueueDate} />
      <SystemMenu open={systemOpen} status={systemStatus} services={systemServices} {hasStoppedServices} {hasRunningServices} updatePending={systemUpdatePending} onToggleOpen={toggleSystemMenu} onRequestAction={requestSystemAction} onEnqueueUpdate={enqueueSystemUpdate} serviceUrl={systemServiceUrl} />
    </div>
  {/if}
  <div class="ml-auto flex items-center gap-3">
    {#if authenticated}
      <NotificationsMenu open={notificationsOpen} {notifications} badgeLevel={notificationBadgeLevel} onToggleOpen={toggleNotificationsMenu} onArchiveAll={archiveAllNotifications} onArchive={archiveNotification} formatDate={formatQueueDate} renderMarkdown={renderNotificationMarkdown} />
    {/if}
    <AppearanceMenu open={themeOpen} {themes} {theme} {modes} {mode} {fonts} {font} {fontCollection} onToggleOpen={toggleAppearanceMenu} onSetTheme={setTheme} onSetMode={setMode} onSetFont={setFont} />
    {#if authenticated}
      <ProfileMenu open={profileOpen} login={currentLogin} onToggleOpen={toggleProfileMenu} onLogout={logout} />
    {/if}
  </div>
</header>

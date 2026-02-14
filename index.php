<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) redirect(ROOT_URL . '/admin/controllers/dashboard/index.php');

$title = 'IoT Vision';
require ROOT_PATH . '/includes/header.php';
?>
<div class="min-h-screen">
  <div class="mx-auto max-w-6xl px-4 py-10">
    <div class="flex items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <div class="h-10 w-10 rounded-2xl bg-slate-800 flex items-center justify-center">
          <div class="h-3 w-3 rounded-full bg-emerald-400"></div>
        </div>
        <div>
          <div class="text-lg font-semibold leading-tight">IoT Vision</div>
          <div class="text-xs text-slate-400">Telemetry + Vision Events</div>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <a href="<?php echo ROOT_URL; ?>/admin/controllers/auth/login.php" class="text-sm px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700">Login</a>
        <a href="<?php echo ROOT_URL; ?>/admin/controllers/auth/registration.php" class="text-sm px-4 py-2 rounded-xl bg-emerald-500/15 text-emerald-300 hover:bg-emerald-500/20 border border-emerald-500/40">Create account</a>
      </div>
    </div>

    <div class="mt-10 grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="rounded-2xl border border-slate-800 bg-slate-900/30 p-6">
        <div class="text-2xl font-semibold leading-tight">Monitor your IoT in real time</div>
        <div class="mt-2 text-sm text-slate-300">
          Live MQTT updates, device health telemetry, and vision detections (faces + objects) in one dashboard.
        </div>
        <div class="mt-6 flex flex-wrap gap-2">
          <div class="px-3 py-2 rounded-xl bg-slate-950/40 border border-slate-800 text-sm">CPU temperature</div>
          <div class="px-3 py-2 rounded-xl bg-slate-950/40 border border-slate-800 text-sm">Disk usage</div>
          <div class="px-3 py-2 rounded-xl bg-slate-950/40 border border-slate-800 text-sm">Snapshots</div>
          <div class="px-3 py-2 rounded-xl bg-slate-950/40 border border-slate-800 text-sm">Event stream</div>
        </div>
        <div class="mt-6">
          <a href="<?php echo ROOT_URL; ?>/admin/controllers/auth/login.php" class="inline-flex items-center justify-center text-sm px-4 py-2 rounded-xl bg-emerald-500/15 text-emerald-300 hover:bg-emerald-500/20 border border-emerald-500/40">
            Go to Dashboard
          </a>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-800 bg-slate-900/30 p-6">
        <div class="text-sm font-semibold">Quick start</div>
        <div class="mt-3 space-y-2 text-sm text-slate-300">
          <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-4">1) Login</div>
          <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-4">2) Start worker + IoT publisher</div>
          <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-4">3) Watch live telemetry + detections</div>
        </div>
        <div class="mt-6 text-xs text-slate-400 break-words">
          ROOT_URL: <?php echo htmlspecialchars(ROOT_URL, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>

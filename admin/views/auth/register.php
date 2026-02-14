<div class="min-h-screen flex items-center justify-center p-6">
  <div class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
    <div class="text-lg font-semibold">Create account</div>
    <div class="mt-1 text-sm text-slate-400">Secure access to your dashboard</div>

    <?php if (!empty($error)) { ?>
      <div class="mt-4 rounded-xl border border-rose-500/40 bg-rose-500/10 p-3 text-sm text-rose-200">
        <?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
      </div>
    <?php } ?>

    <form class="mt-5 space-y-3" method="post" action="<?php echo ROOT_URL; ?>/admin/controllers/auth/registration.php">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

      <div>
        <label class="text-xs text-slate-400">Email</label>
        <input name="email" type="email" class="mt-1 w-full rounded-xl border border-slate-800 bg-slate-950/30 px-3 py-2 text-sm outline-none focus:border-emerald-500/60" placeholder="you@example.com" autocomplete="email" required>
      </div>

      <div>
        <label class="text-xs text-slate-400">Password</label>
        <input name="password" type="password" class="mt-1 w-full rounded-xl border border-slate-800 bg-slate-950/30 px-3 py-2 text-sm outline-none focus:border-emerald-500/60" placeholder="min 8 chars" autocomplete="new-password" required>
      </div>

      <div>
        <label class="text-xs text-slate-400">Confirm password</label>
        <input name="password2" type="password" class="mt-1 w-full rounded-xl border border-slate-800 bg-slate-950/30 px-3 py-2 text-sm outline-none focus:border-emerald-500/60" placeholder="repeat password" autocomplete="new-password" required>
      </div>

      <button class="w-full rounded-xl bg-emerald-500/15 text-emerald-300 hover:bg-emerald-500/20 border border-emerald-500/40 px-4 py-2 text-sm font-semibold">
        Create account
      </button>

      <div class="text-xs text-slate-400">
        Already have an account?
        <a class="text-emerald-300 hover:underline" href="<?php echo ROOT_URL; ?>/admin/controllers/auth/login.php">Login</a>
      </div>
    </form>
  </div>
</div>

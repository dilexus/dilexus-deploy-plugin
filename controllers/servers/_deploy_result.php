<div id="deploy-result">
    <?php if (isset($log)): ?>
    <div class="callout <?= $statusClass === 'success' ? 'callout-success' : 'callout-danger' ?> no-subtext">
        <div class="header">
            <i class="icon-<?= $statusClass === 'success' ? 'check' : 'times' ?>"></i>
            Deployment <?= e($statusLabel) ?> — Log #<?= $log->id ?>
        </div>
        <?php if ($log->output): ?>
        <pre style="max-height:300px;overflow:auto;font-size:12px;margin-top:10px"><?= e($log->output) ?></pre>
        <?php endif?>
    </div>
    <?php endif?>
</div>

<?php Block::put('button-list')?>
    <a
        href="javascript:;"
        class="btn btn-danger oc-icon-cloud-upload"
        data-request="onDeploy"
        data-request-data="id: '<?= $formModel->id ?>'"
        data-request-update="'@deploy_result': '#deploy-result'"
        data-request-confirm="Deploy <?= e($formModel->name) ?> now?"
        data-load-indicator="Deploying...">
        Deploy Now
    </a>
<?php Block::endPut()?>

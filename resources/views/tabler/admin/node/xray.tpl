{include file='admin/header.tpl'}

<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header d-print-none text-white">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        <span class="home-title">VLESS (Xray) 节点配置</span>
                    </h2>
                    <div class="page-pretitle my-3">
                        <span class="home-subtitle">节点：{$node->name}（{$node->server}）</span>
                    </div>
                </div>
                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <a href="/admin/node" class="btn btn-primary">返回节点列表</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header card-header-light">
                            <h3 class="card-title">一键部署命令（在本节点服务器以 root 执行）</h3>
                        </div>
                        <div class="card-body">
                            <pre id="deploy-cmd" class="p-3" style="white-space: pre-wrap;">{$deploy_command}</pre>
                            <button class="btn btn-primary" onclick="copyText('deploy-cmd')">复制命令</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header card-header-light">
                            <h3 class="card-title">生成的 Xray config.json（与 deploy.sh 输出一致）</h3>
                        </div>
                        <div class="card-body">
                            <pre id="xray-config" class="p-3" style="white-space: pre-wrap;">{$config_json}</pre>
                            <button class="btn btn-primary" onclick="copyText('xray-config')">复制配置</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header card-header-light">
                            <h3 class="card-title">客户端订阅示例（用户 uuid 替换后进行分发）</h3>
                        </div>
                        <div class="card-body">
                            <pre id="vless-uri" class="p-3" style="white-space: pre-wrap;">{$vless_uri_example}</pre>
                            <p class="text-muted">实际分发时 uuid 取自订阅用户；此处仅用于验证节点连通性。</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function copyText(id) {
        const el = document.getElementById(id);
        navigator.clipboard.writeText(el.innerText).then(function () {
            alert('已复制');
        });
    }
</script>

{include file='admin/footer.tpl'}
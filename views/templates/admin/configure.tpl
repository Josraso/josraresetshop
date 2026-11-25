{* JosraResetShop - configuración (interfaz nativa de PrestaShop) *}
<div id="jrs" data-ajax="{$jrs_ajax_url|escape:'html':'UTF-8'}">

  <div class="alert alert-danger">
    <p><strong>{l s='Esto borra datos de verdad y no se puede deshacer.' mod='josraresetshop'}</strong>
      {l s='Haz antes una copia de seguridad de la base de datos y de la carpeta img/.' mod='josraresetshop'}</p>
    {if $jrs_multishop}<p>{l s='La multitienda está activa: se vacía en todas las tiendas a la vez.' mod='josraresetshop'}</p>{/if}
  </div>

  <div class="panel">
    <div class="panel-heading"><i class="icon-trash"></i> {l s='Qué vaciar' mod='josraresetshop'}</div>
    <p class="help-block">{l s='Algunas secciones necesitan otras para no dejar la tienda rota: al marcarlas, se marcan solas y se explica por qué.' mod='josraresetshop'}</p>

    {foreach from=$jrs_sections item=s}
      {if $s.group == 'data'}
        <div class="checkbox jrs-section">
          <label>
            <input type="checkbox" class="jrs-chk" value="{$s.key|escape:'html':'UTF-8'}" data-requires="{$s.requires_str|escape:'html':'UTF-8'}" data-label="{$s.label|escape:'html':'UTF-8'}">
            <strong>{$s.label|escape:'html':'UTF-8'}</strong>
            {if $s.count >= 0}<span class="badge">{$s.count|intval} {$s.unit|escape:'html':'UTF-8'}</span>{/if}
          </label>
          <p class="help-block">{$s.desc|escape:'html':'UTF-8'}</p>
          <p class="help-block text-warning jrs-why" hidden></p>
        </div>
      {/if}
    {/foreach}

    <hr>
    <h4>{l s='Archivos' mod='josraresetshop'}</h4>
    {foreach from=$jrs_sections item=s}
      {if $s.group == 'files'}
        <div class="checkbox jrs-section">
          <label>
            <input type="checkbox" class="jrs-chk" value="{$s.key|escape:'html':'UTF-8'}" data-requires="" data-label="{$s.label|escape:'html':'UTF-8'}">
            <strong>{$s.label|escape:'html':'UTF-8'}</strong>
          </label>
          <p class="help-block">{$s.desc|escape:'html':'UTF-8'}</p>
        </div>
      {/if}
    {/foreach}

    <div class="panel-footer">
      <button type="button" class="btn btn-default" data-jrs-all><i class="icon-check"></i> {l s='Marcar todas las secciones' mod='josraresetshop'}</button>
      <button type="button" class="btn btn-default" data-jrs-none><i class="icon-check-empty"></i> {l s='Desmarcar todo' mod='josraresetshop'}</button>
    </div>
  </div>

  <div class="panel">
    <div class="panel-heading"><i class="icon-puzzle-piece"></i> {l s='Tablas de otros módulos' mod='josraresetshop'}
      <span class="badge">{$jrs_module_tables_n|intval}</span></div>
    <div class="alert alert-warning">
      {l s='Estas tablas son de otros módulos y guardan datos de pedidos, clientes, carritos o productos (o suscriptores del boletín).' mod='josraresetshop'}
      <strong>{l s='Si estás preparando una migración, a lo mejor no hace falta vaciarlas' mod='josraresetshop'}</strong>
      {l s='(el módulo de migración las puede necesitar, o guardan configuración, como las reglas de regalo de un producto). Ninguna viene marcada: marca solo las que sepas que quieres vaciar. Las filas son aproximadas.' mod='josraresetshop'}
    </div>
    {if $jrs_module_tables}
      {foreach from=$jrs_module_tables item=g}
        <details class="jrs-mod">
          <summary>
            <strong>{$g.name|escape:'html':'UTF-8'}</strong>
            {if $g.module}<code>{$g.module|escape:'html':'UTF-8'}</code>{/if}
            {if $g.module && !$g.installed}<span class="label label-default">{l s='no instalado (tablas que quedaron)' mod='josraresetshop'}</span>{/if}
            <span class="badge">{$g.n|intval} {l s='tablas' mod='josraresetshop'}</span>
          </summary>
          <table class="table table-condensed">
            <thead><tr><th style="width:30px"></th><th>{l s='Tabla' mod='josraresetshop'}</th><th>{l s='Filas' mod='josraresetshop'}</th><th>{l s='Columnas que la delatan' mod='josraresetshop'}</th></tr></thead>
            <tbody>
              {foreach from=$g.tables item=t}
                <tr>
                  <td><input type="checkbox" class="jrs-tbl" value="{$t.table|escape:'html':'UTF-8'}" id="jrs-t-{$t.table|escape:'html':'UTF-8'}"></td>
                  <td><label for="jrs-t-{$t.table|escape:'html':'UTF-8'}"><code>{$t.table|escape:'html':'UTF-8'}</code></label></td>
                  <td>≈ {$t.rows|intval}</td>
                  <td><small>{$t.cols|escape:'html':'UTF-8'}</small></td>
                </tr>
              {/foreach}
            </tbody>
          </table>
        </details>
      {/foreach}
    {else}
      <p>{l s='No hay tablas de otros módulos con esos datos.' mod='josraresetshop'}</p>
    {/if}
  </div>

  <div class="panel">
    <div class="panel-heading"><i class="icon-unlink"></i> {l s='Módulos conectados a PrestaShop / Addons' mod='josraresetshop'}
      <span class="badge">{$jrs_ps_modules_n|intval}</span></div>
    {if $jrs_ps_modules}
      <p class="help-block">{l s='Estos módulos se conectan a los servidores de PrestaShop o de Addons. Los marcados se desinstalan (sus archivos se quedan en /modules).' mod='josraresetshop'}</p>
      {foreach from=$jrs_ps_modules item=m}
        <div class="checkbox">
          <label><input type="checkbox" class="jrs-psmod" value="{$m.name|escape:'html':'UTF-8'}" checked>
            {$m.display|escape:'html':'UTF-8'} <code>{$m.name|escape:'html':'UTF-8'}</code></label>
        </div>
      {/foreach}
    {else}
      <p>{l s='No hay ninguno instalado.' mod='josraresetshop'}</p>
    {/if}
  </div>

  <div class="panel" id="jrs-run">
    <div class="panel-heading"><i class="icon-warning-sign"></i> {l s='Confirmar y vaciar' mod='josraresetshop'}</div>
    <div id="jrs-summary" class="alert alert-info">{l s='Marca arriba lo que quieras vaciar.' mod='josraresetshop'}</div>
    <div class="form-group">
      <label for="jrs-confirm">{l s='Para confirmar, escribe BORRAR' mod='josraresetshop'}</label>
      <input type="text" id="jrs-confirm" class="form-control" autocomplete="off" style="max-width:220px" placeholder="BORRAR">
    </div>
    <button type="button" class="btn btn-danger btn-lg" id="jrs-go" disabled><i class="icon-trash"></i> {l s='Vaciar ahora' mod='josraresetshop'}</button>
  </div>

  <div class="panel" id="jrs-progress" hidden>
    <div class="panel-heading"><i class="icon-refresh"></i> {l s='Progreso' mod='josraresetshop'}</div>
    <p><strong id="jrs-now">…</strong> <span class="pull-right" id="jrs-pct">0%</span></p>
    <div class="progress"><div class="progress-bar progress-bar-striped active" id="jrs-bar" role="progressbar" style="width:0%"></div></div>
    <div id="jrs-done" hidden></div>
    <pre id="jrs-log" class="jrs-log"></pre>
    <button type="button" class="btn btn-default" id="jrs-dl" hidden><i class="icon-download"></i> {l s='Descargar el registro' mod='josraresetshop'}</button>
  </div>

  <script type="application/json" id="jrs-txt">{$jrs_txt nofilter}</script>
</div>

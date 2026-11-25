{* JosraResetShop - configuración (componentes nativos de PrestaShop: paneles, rejilla, interruptores, alertas) *}
<div id="jrs" data-ajax="{$jrs_ajax_url|escape:'html':'UTF-8'}">

  <div class="alert alert-danger">
      <strong>{l s='Esto borra datos de verdad y no se puede deshacer.' mod='josraresetshop'}</strong>
      {l s='Haz antes una copia de seguridad de la base de datos y de las carpetas img/ y download/.' mod='josraresetshop'}
      {if $jrs_multishop}<br>{l s='La multitienda está activa: se vacía en todas las tiendas a la vez.' mod='josraresetshop'}{/if}
  </div>

  {* ------------------------------------------------------------- modo *}
  <div class="row jrs-modes">
    <div class="col-md-6">
      <label class="panel jrs-mode is-on" for="jrs-mode-sections">
        <input type="radio" name="jrs_mode" id="jrs-mode-sections" value="sections" checked>
        <span class="jrs-mode__ico"><i class="material-icons">checklist</i></span>
        <span class="jrs-mode__txt">
          <strong>{l s='Vaciar por secciones' mod='josraresetshop'}</strong>
          <small>{l s='Eliges qué borrar: pedidos, clientes, catálogo... El resto de la tienda se queda como está.' mod='josraresetshop'}</small>
        </span>
      </label>
    </div>
    <div class="col-md-6">
      <label class="panel jrs-mode{if !$jrs_factory.supported} is-disabled{/if}" for="jrs-mode-factory">
        <input type="radio" name="jrs_mode" id="jrs-mode-factory" value="factory"{if !$jrs_factory.supported} disabled{/if}>
        <span class="jrs-mode__ico jrs-mode__ico--factory"><i class="material-icons">restart_alt</i></span>
        <span class="jrs-mode__txt">
          <strong>{l s='Dejar como recién instalada' mod='josraresetshop'}</strong>
          <small>
            {if $jrs_factory.supported}
              {l s='Borra todo y vuelve a poner lo que trae una instalación nueva de PrestaShop, con o sin productos de demostración.' mod='josraresetshop'}
            {else}
              {l s='Necesita PrestaShop %s o superior.' sprintf=[$jrs_min_factory] mod='josraresetshop'}
            {/if}
          </small>
        </span>
      </label>
    </div>
  </div>

  {* ------------------------------------------------------------- de fábrica *}
  <div class="panel" id="jrs-factory" hidden>
    <div class="panel-heading"><i class="material-icons">restart_alt</i> {l s='Dejar como recién instalada' mod='josraresetshop'}
      <span class="badge badge-info">PrestaShop {$jrs_factory.version|escape:'html':'UTF-8'}</span></div>
    <div class="row">
      <div class="col-md-6">
        <h4 class="jrs-h4 text-danger"><i class="material-icons">delete_forever</i> {l s='Se borra y vuelve de fábrica' mod='josraresetshop'}</h4>
        <ul class="jrs-list">
          <li>{l s='Todas las secciones de abajo: pedidos, clientes, catálogo, categorías, atributos, marcas, cupones, mensajes, empleados (menos tú) y estadísticas.' mod='josraresetshop'}</li>
          <li>{l s='Configuración de la tienda (pedidos, productos, clientes, correo, SEO, rendimiento...) con los valores de una instalación nueva; los datos de la tienda (dirección, teléfono) quedan vacíos.' mod='josraresetshop'}</li>
          <li>{l s='Transportistas, estados de pedido, grupos de clientes, páginas CMS, contactos, títulos y URLs de las páginas, tratamientos y accesos rápidos de fábrica.' mod='josraresetshop'}</li>
          <li>{l s='Tiendas físicas, mensajes predefinidos, claves del webservice, consultas SQL, alias de búsqueda y traducciones propias: vacíos.' mod='josraresetshop'}</li>
        </ul>
      </div>
      <div class="col-md-6">
        <h4 class="jrs-h4 text-success"><i class="material-icons">verified_user</i> {l s='Se conserva' mod='josraresetshop'}</h4>
        <ul class="jrs-list">
          <li>{l s='Tu empleado (%s) y los perfiles y permisos.' sprintf=[$jrs_employee] mod='josraresetshop'}</li>
          <li>{l s='Dominio, SSL, URL amigables, nombre de la tienda, logos y base de datos.' mod='josraresetshop'}</li>
          <li>{l s='Idiomas, países, zonas, impuestos y monedas.' mod='josraresetshop'}</li>
          <li>{l s='El tema y los módulos instalados con su configuración, con sus transportistas y sus estados de pedido (Redsys, MBE...).' mod='josraresetshop'}</li>
        </ul>
      </div>
    </div>
    <hr>
    <div class="row">
      <div class="col-md-6">
        <div class="form-group">
          <label class="control-label">{l s='Productos de demostración' mod='josraresetshop'}</label>
          <div>
            <span class="switch prestashop-switch fixed-width-lg">
              <input type="radio" name="jrs_demo" id="jrs_demo_on" value="1"><label for="jrs_demo_on">{l s='Sí' mod='josraresetshop'}</label>
              <input type="radio" name="jrs_demo" id="jrs_demo_off" value="0" checked><label for="jrs_demo_off">{l s='No' mod='josraresetshop'}</label>
              <a class="slide-button btn"></a>
            </span>
          </div>
          <p class="help-block">{l s='Los de una instalación nueva: categorías Ropa y Accesorios, camisetas, tazas, cuadros..., con sus imágenes, marcas, proveedores, un cliente y pedidos de ejemplo.' mod='josraresetshop'}</p>
        </div>
      </div>
      <div class="col-md-6">
        <label class="control-label">{l s='Datos de instalación de PrestaShop %s' sprintf=[$jrs_factory.version] mod='josraresetshop'}</label>
        {if $jrs_factory.ready}
          <p class="text-success"><i class="material-icons">check_circle</i> {l s='Ya están en el servidor.' mod='josraresetshop'}</p>
        {elseif $jrs_factory.can_download}
          <p><i class="material-icons">cloud_download</i> {l s='Se bajan solos de GitHub al empezar (unos 50 MB, una sola vez).' mod='josraresetshop'}</p>
          <p class="help-block">{l s='Si el servidor no puede salir a internet, sube el zip de PrestaShop %s (el oficial o el del código fuente) por FTP a %s' sprintf=[$jrs_factory.version, $jrs_factory.base] mod='josraresetshop'}</p>
        {else}
          <p class="text-danger">{l s='El servidor no tiene las extensiones curl y zip de PHP: no se pueden preparar.' mod='josraresetshop'}</p>
        {/if}
      </div>
    </div>
  </div>

  {* ------------------------------------------------------------- secciones *}
  <div class="panel" id="jrs-sections">
    <div class="panel-heading"><i class="material-icons">checklist</i> {l s='Qué vaciar' mod='josraresetshop'}
      <span class="panel-heading-action">
        <a href="#" class="btn btn-default btn-sm" data-jrs-all><i class="material-icons">done_all</i> {l s='Todas' mod='josraresetshop'}</a>
        <a href="#" class="btn btn-default btn-sm" data-jrs-none><i class="material-icons">remove_done</i> {l s='Ninguna' mod='josraresetshop'}</a>
      </span>
    </div>
    <p class="help-block jrs-intro">{l s='Toca una tarjeta para marcarla. Las que otras necesitan se marcan solas (para no dejar productos sin categoría, pedidos sin cliente o combinaciones rotas) y te dice por qué.' mod='josraresetshop'}</p>
    <div class="row jrs-grid">
      {foreach from=$jrs_sections item=s}
        <div class="col-lg-3 col-md-4 col-sm-6">
          <label class="panel jrs-card{if $s.group == 'files'} jrs-card--files{/if}" data-key="{$s.key|escape:'html':'UTF-8'}">
            <input type="checkbox" class="jrs-chk" value="{$s.key|escape:'html':'UTF-8'}" data-requires="{$s.requires_str|escape:'html':'UTF-8'}" data-label="{$s.label|escape:'html':'UTF-8'}" data-group="{$s.group|escape:'html':'UTF-8'}">
            <span class="jrs-card__top">
              <i class="material-icons jrs-card__ico">{$s.icon|escape:'html':'UTF-8'}</i>
              <i class="material-icons jrs-card__tick">check_circle</i>
            </span>
            <span class="jrs-card__title">{$s.label|escape:'html':'UTF-8'}</span>
            {if $s.count >= 0}
              <span class="jrs-card__num">{$s.count|intval}</span>
              <span class="jrs-card__unit">{$s.unit|escape:'html':'UTF-8'}</span>
            {else}
              <span class="jrs-card__num jrs-card__num--small">{l s='Archivos' mod='josraresetshop'}</span>
            {/if}
            <span class="jrs-card__desc">{$s.desc|escape:'html':'UTF-8'}</span>
            <span class="label label-warning jrs-why" hidden></span>
          </label>
        </div>
      {/foreach}
    </div>
  </div>

  {* ------------------------------------------------------------- módulos *}
  <div class="row">
    <div class="col-lg-8">
      <div class="panel">
        <div class="panel-heading"><i class="material-icons">extension</i> {l s='Tablas de otros módulos' mod='josraresetshop'}
          <span class="badge">{$jrs_module_tables_n|intval}</span></div>
        <div class="alert alert-warning">
          {l s='Guardan datos de pedidos, clientes, carritos o productos (o suscriptores del boletín).' mod='josraresetshop'}
          <strong>{l s='Si estás preparando una migración, a lo mejor no hace falta vaciarlas' mod='josraresetshop'}</strong>
          {l s='(el módulo de migración puede necesitarlas, o guardan configuración, como las reglas de regalo). Ninguna viene marcada. Las filas son aproximadas.' mod='josraresetshop'}
        </div>
        {if $jrs_module_tables}
          {foreach from=$jrs_module_tables item=g}
            <details class="jrs-mod">
              <summary>
                <i class="material-icons">chevron_right</i>
                <strong>{$g.name|escape:'html':'UTF-8'}</strong>
                {if $g.module}<code>{$g.module|escape:'html':'UTF-8'}</code>{/if}
                {if $g.module && !$g.installed}<span class="label label-default">{l s='no instalado' mod='josraresetshop'}</span>{/if}
                <span class="badge pull-right">{$g.n|intval}</span>
                <span class="badge badge-danger pull-right jrs-mod-sel" hidden></span>
              </summary>
              <table class="table table-condensed">
                <thead><tr><th style="width:30px"><input type="checkbox" class="jrs-tbl-all" title="{l s='Todas las de este módulo' mod='josraresetshop'}"></th><th>{l s='Tabla' mod='josraresetshop'}</th><th class="text-right">{l s='Filas' mod='josraresetshop'}</th><th>{l s='Datos de' mod='josraresetshop'}</th></tr></thead>
                <tbody>
                  {foreach from=$g.tables item=t}
                    <tr>
                      <td><input type="checkbox" class="jrs-tbl" value="{$t.table|escape:'html':'UTF-8'}" id="jrs-t-{$t.table|escape:'html':'UTF-8'}"></td>
                      <td><label for="jrs-t-{$t.table|escape:'html':'UTF-8'}"><code>{$t.table|escape:'html':'UTF-8'}</code></label></td>
                      <td class="text-right">≈ {$t.rows|intval}</td>
                      <td><small class="text-muted">{$t.cols|escape:'html':'UTF-8'}</small></td>
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
    </div>
    <div class="col-lg-4">
      <div class="panel">
        <div class="panel-heading"><i class="material-icons">link_off</i> {l s='Módulos de PrestaShop / Addons' mod='josraresetshop'}
          <span class="badge">{$jrs_ps_modules_n|intval}</span></div>
        {if $jrs_ps_modules}
          <p class="help-block">{l s='Se conectan a los servidores de PrestaShop o de Addons. Los marcados se desinstalan (sus archivos se quedan en /modules).' mod='josraresetshop'}</p>
          <ul class="list-unstyled jrs-psmods">
            {foreach from=$jrs_ps_modules item=m}
              <li class="checkbox"><label><input type="checkbox" class="jrs-psmod" value="{$m.name|escape:'html':'UTF-8'}" checked>
                {$m.display|escape:'html':'UTF-8'} <code>{$m.name|escape:'html':'UTF-8'}</code></label></li>
            {/foreach}
          </ul>
        {else}
          <p class="text-success"><i class="material-icons">check_circle</i> {l s='No hay ninguno instalado.' mod='josraresetshop'}</p>
        {/if}
      </div>
    </div>
  </div>

  {* ------------------------------------------------------------- confirmar *}
  <div class="panel jrs-go" id="jrs-run">
    <div class="row">
      <div class="col-md-7">
        <div id="jrs-summary" class="alert alert-info">{l s='Marca arriba lo que quieras vaciar.' mod='josraresetshop'}</div>
      </div>
      <div class="col-md-5">
        <div class="form-group">
          <label for="jrs-confirm">{l s='Para confirmar, escribe BORRAR' mod='josraresetshop'}</label>
          <div class="input-group">
            <input type="text" id="jrs-confirm" class="form-control" autocomplete="off" placeholder="BORRAR">
            <span class="input-group-btn">
              <button type="button" class="btn btn-danger" id="jrs-go" disabled><i class="material-icons">delete_sweep</i> <span id="jrs-go-txt">{l s='Vaciar ahora' mod='josraresetshop'}</span></button>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>

  {* ------------------------------------------------------------- progreso *}
  <div class="panel" id="jrs-progress" hidden>
    <div class="panel-heading"><i class="material-icons">sync</i> {l s='Progreso' mod='josraresetshop'}
      <span class="badge" id="jrs-count"></span></div>
    <p class="jrs-now"><strong id="jrs-now">…</strong> <span class="pull-right" id="jrs-pct">0%</span></p>
    <div class="progress"><div class="progress-bar progress-bar-striped active" id="jrs-bar" role="progressbar" style="width:0%"></div></div>
    <div id="jrs-done" hidden></div>
    <div class="row">
      <div class="col-md-5">
        <ul class="list-unstyled jrs-steps" id="jrs-steps"></ul>
      </div>
      <div class="col-md-7">
        <pre id="jrs-log" class="jrs-log"></pre>
        <button type="button" class="btn btn-default" id="jrs-dl" hidden><i class="material-icons">download</i> {l s='Descargar el registro' mod='josraresetshop'}</button>
      </div>
    </div>
  </div>

  <script type="application/json" id="jrs-txt">{$jrs_txt nofilter}</script>
</div>

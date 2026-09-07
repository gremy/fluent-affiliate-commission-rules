/**
 * Commission Rules admin app. Mounts on Fluent Affiliate's #fluent-framework-app
 * (printed by their AdminMenuHandler::render()) and talks to fa-commission-rules/v1.
 * Plain script, no build step: Vue, Element Plus, facrHelpers and facrAdmin are
 * globals registered by includes/Admin/Menu.php. Every visible string is i18n.*.
 */
( function () {
  'use strict';

  var cfg   = window.facrAdmin || {};
  var i18n  = cfg.i18n || {};
  var H     = window.facrHelpers;
  var EP    = window.ElementPlus;
  var mount = document.getElementById( 'fluent-framework-app' );

  if ( ! mount || ! window.Vue || ! EP || ! H ) {
    return;
  }

  var MONEY = { money_template: cfg.money_template || '%s', decimal_separator: cfg.decimal_separator || '.' };
  /** Site-local 'YYYY-MM-DD', for status comparisons and the year-preset button. */
  var TODAY = cfg.today || H.ymd( new Date() );

  /** wp_localize_script casts top-level scalars to strings: '1' means true. */
  function flag( value ) {
    return value === true || value === 1 || value === '1';
  }

  /** rest_url() may be a ?rest_route= URL on plain permalinks, so build with URL. */
  function restUrl( path, query ) {
    var url = new URL( cfg.rest_url + path, window.location.origin );
    url.searchParams.set( '_locale', 'user' );
    Object.keys( query || {} ).forEach( function ( key ) {
      url.searchParams.set( key, query[ key ] );
    } );
    return url.toString();
  }

  /**
   * fetch() with the wp_rest nonce; resolves with parsed JSON, rejects with
   * {status, data}. A non-JSON body (an HTML login/proxy page, typically with
   * a 200 status) must never be treated as a successful empty response, so
   * the content-type and the parse itself are both checked before `ok`.
   */
  function api( path, options ) {
    options = options || {};
    var headers = { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' };
    var init    = { method: options.method || 'GET', credentials: 'same-origin', headers: headers };
    if ( options.revision ) { headers['If-Match'] = options.revision; }
    if ( options.body !== undefined ) {
      headers[ 'Content-Type' ] = 'application/json';
      init.body = JSON.stringify( options.body );
    }
    return window.fetch( restUrl( path, options.query ), init ).then( function ( response ) {
      var contentType = response.headers.get( 'Content-Type' ) || '';
      if ( contentType.indexOf( 'application/json' ) === -1 ) {
        throw new Error( i18n.error_generic );
      }
      return response.json().then( function ( data ) {
        if ( response.ok ) {
          return data;
        }
        var error = new Error( ( data && data.message ) || i18n.error_generic );
        error.status = response.status;
        error.data   = data || {};
        throw error;
      }, function () {
        throw new Error( i18n.error_generic );
      } );
    } );
  }

  function notify( type, message ) {
    EP.ElNotification( { type: type, message: message, duration: 4000, offset: 40 } );
  }

  /** Resolves true/false; never rejects. */
  function confirm( message ) {
    return EP.ElMessageBox.confirm( message, i18n.confirm_title, {
      confirmButtonText: i18n.confirm_ok,
      cancelButtonText: i18n.confirm_cancel,
      type: 'warning'
    } ).then( function () {
      return true;
    }, function () {
      return false;
    } );
  }

  /** scope_id is null (not 0) so the select shows its placeholder; it is sent as 0. */
  function blankForm() {
    return {
      id: '',
      status: 'active',
      scope_type: 'all',
      scope_id: null,
      customer_type: 'all',
      target_type: 'all',
      category_ids: [],
      product_ids: [],
      rate: null,
      rate_type: 'percentage',
      starts_at: '',
      ends_at: '',
      note: ''
    };
  }

  var LIST_TEMPLATE = [
    '<div class="fa-affiliate-wrap facr-app" v-loading="loading">',
    '  <div class="fa_page_heading"><h1 class="fa_page_title">{{ i18n.page_title }}</h1></div>',
    '  <div class="fa-affiliate-body">',
    '    <div class="fa-affiliate-body-actions-bar">',
    '      <div class="facr-filters">',
    '        <el-select v-model="filters.scope" :aria-label="i18n.filter_all_audiences" :placeholder="i18n.filter_all_audiences" style="width:150px">',
    '          <el-option value="" :label="i18n.filter_all_audiences"></el-option>',
    '          <el-option value="affiliate" :label="i18n.filter_affiliate"></el-option>',
    '          <el-option value="group" :label="i18n.filter_group"></el-option>',
    '          <el-option value="all" :label="i18n.filter_everyone"></el-option>',
    '        </el-select>',
    '        <el-select v-model="filters.target" :aria-label="i18n.filter_any_target" :placeholder="i18n.filter_any_target" style="width:150px">',
    '          <el-option value="" :label="i18n.filter_any_target"></el-option>',
    '          <el-option value="product" :label="i18n.filter_product"></el-option>',
    '          <el-option value="category" :label="i18n.filter_category"></el-option>',
    '          <el-option value="all" :label="i18n.filter_all_products"></el-option>',
    '        </el-select>',
    '        <el-select v-model="filters.customer_type" :aria-label="i18n.filter_customer" :placeholder="i18n.filter_customer" style="width:150px">',
    '          <el-option value="" :label="i18n.filter_customer"></el-option>',
    '          <el-option value="all" :label="i18n.customer_all"></el-option>',
    '          <el-option value="b2b" :label="i18n.customer_b2b"></el-option>',
    '          <el-option value="b2c" :label="i18n.customer_b2c"></el-option>',
    '        </el-select>',
    '        <el-select v-model="filters.status" :aria-label="i18n.filter_any_status" :placeholder="i18n.filter_any_status" style="width:130px">',
    '          <el-option value="" :label="i18n.filter_any_status"></el-option>',
    '          <el-option value="active" :label="i18n.filter_active"></el-option>',
    '          <el-option value="inactive" :label="i18n.filter_inactive"></el-option>',
    '        </el-select>',
    '        <el-input v-model="filters.q" :aria-label="i18n.search_placeholder" clearable :placeholder="i18n.search_placeholder" style="width:220px"></el-input>',
    '      </div>',
    '      <el-button type="primary" :disabled="loading || mutating || !!loadError || !optionsReady" @click="openEditor()">{{ i18n.add_rule }}</el-button>',
    '    </div>',
    '    <div class="fa-affiliate-body-actions-bar facr-bulk-bar" v-if="selected.length">',
    '      <span>{{ selectedText }}</span>',
    '      <span>',
    '        <el-button size="small" :disabled="mutating || loading" @click="bulk(\'activate\')">{{ i18n.activate }}</el-button>',
    '        <el-button size="small" :disabled="mutating || loading" @click="bulk(\'deactivate\')">{{ i18n.deactivate }}</el-button>',
    '        <el-button size="small" type="danger" plain :disabled="mutating || loading" @click="bulk(\'delete\')">{{ i18n.delete }}</el-button>',
    '      </span>',
    '    </div>',
    '    <div class="fa_empty_state" v-if="!loading && (loadError || optionsError)">',
    '      <el-alert type="error" :closable="false" show-icon :title="loadError || optionsError"></el-alert>',
    '      <el-button @click="reloadRules">{{ i18n.reload }}</el-button>',
    '    </div>',
    '    <div class="fa_empty_state" v-else-if="!loading && !rules.length">',
    '      <el-empty :description="emptyText">',
    '        <el-button type="primary" :disabled="loading || mutating || !!loadError || !optionsReady" @click="openEditor()">{{ i18n.add_first }}</el-button>',
    '      </el-empty>',
    '    </div>',
    '    <div class="fa_table_wrap" v-else>',
    '      <el-table :data="visibleRules" row-key="id" :empty-text="i18n.no_match" :row-class-name="rowClassName" @selection-change="onSelect" @row-click="onRowClick" style="width:100%">',
    '        <el-table-column type="selection" :label="i18n.select_rules" width="44" :selectable="selectable"></el-table-column>',
    '        <el-table-column :label="i18n.col_who" prop="labels.scope" min-width="140"></el-table-column>',
    '        <el-table-column :label="i18n.col_what" prop="labels.target" min-width="160"></el-table-column>',
    '        <el-table-column :label="i18n.col_rate" prop="labels.rate" width="90"></el-table-column>',
    '        <el-table-column :label="i18n.col_window" prop="labels.window" min-width="130"></el-table-column>',
    '        <el-table-column :label="i18n.col_status" min-width="170">',
    '          <template #default="{ row }">',
    '            <span class="fa_badge" :class="statusClass(row)">{{ statusText(row) }}</span>',
    '            <div v-if="badge(row).kind" class="facr-badge-note">{{ badge(row).text }}</div>',
    '          </template>',
    '        </el-table-column>',
    '        <el-table-column :label="i18n.col_note" prop="note" min-width="140" show-overflow-tooltip></el-table-column>',
    '        <el-table-column :label="isNarrow ? \'\' : i18n.col_actions" :width="isNarrow ? 56 : 130" align="right" fixed="right" class-name="facr-col-actions">',
    '          <template #default="{ row }">',
    '            <template v-if="isNarrow">',
    '              <el-dropdown v-if="!row.readonly" trigger="click" @command="onRowCommand">',
    '                <span class="facr-dots" role="button" :aria-label="i18n.actions_menu">⋯</span>',
    '                <template #dropdown>',
    '                  <el-dropdown-menu>',
    '                    <el-dropdown-item :command="{ action: \'edit\', row: row }">{{ i18n.edit }}</el-dropdown-item>',
    '                    <el-dropdown-item :command="{ action: \'toggle\', row: row }">{{ row.status === \'active\' ? i18n.deactivate : i18n.activate }}</el-dropdown-item>',
    '                    <el-dropdown-item :command="{ action: \'delete\', row: row }" style="color:var(--el-color-danger)">{{ i18n.delete }}</el-dropdown-item>',
    '                  </el-dropdown-menu>',
    '                </template>',
    '              </el-dropdown>',
    '              <svg v-else class="facr-lock" width="16" height="16" viewBox="0 0 16 16" role="img" :aria-label="i18n.readonly_hint" :title="i18n.readonly_hint">',
    '                <title>{{ i18n.readonly_hint }}</title>',
    '                <path fill="currentColor" d="M8 1a3 3 0 0 0-3 3v2H4a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1h-1V4a3 3 0 0 0-3-3zm0 1.5A1.5 1.5 0 0 1 9.5 4v2h-3V4A1.5 1.5 0 0 1 8 2.5zM5.5 7.5h5v5h-5v-5z"></path>',
    '              </svg>',
    '            </template>',
    '            <template v-else>',
    '              <template v-if="!row.readonly">',
    '                <el-button link type="primary" size="small" @click="openEditor(row)">{{ i18n.edit }}</el-button>',
    '                <el-button link type="danger" size="small" @click="remove(row)">{{ i18n.delete }}</el-button>',
    '              </template>',
    '              <el-tooltip v-else :content="i18n.readonly_hint" placement="top">',
    '                <span class="facr-readonly">{{ i18n.readonly_short }}</span>',
    '              </el-tooltip>',
    '            </template>',
    '          </template>',
    '        </el-table-column>',
    '      </el-table>',
    '    </div>',
    '  </div>',
    '  <el-drawer v-model="editor.open" :title="editorTitle" :size="editor.size" class="fa_common_drawer" :before-close="closeEditor" :close-on-click-modal="false" :destroy-on-close="true">',
    '    <el-form label-position="top" :disabled="editor.saving" @submit.prevent="save">',
    '      <el-alert v-if="editor.submitError" type="error" :closable="false" :title="editor.submitError" show-icon></el-alert>',
    '      <el-button v-if="editor.conflict" :disabled="editor.saving" @click="reviewConflict">{{ i18n.review_latest }}</el-button>',
    '      <template v-if="editor.draft">',
    '        <el-alert type="info" :closable="false" :title="i18n.draft_kept"></el-alert>',
    '        <el-button :disabled="editor.saving" @click="restoreDraft">{{ i18n.restore_draft }}</el-button>',
    '      </template>',
    '      <el-divider content-position="left">{{ i18n.section_audience }}</el-divider>',
    '      <el-form-item>',
    '        <el-radio-group v-model="editor.form.scope_type" :aria-label="i18n.section_audience" @change="editor.form.scope_id = null">',
    '          <el-radio-button value="all">{{ i18n.scope_all }}</el-radio-button>',
    '          <el-radio-button v-if="options.has_pro" value="group">{{ i18n.scope_group }}</el-radio-button>',
    '          <el-radio-button value="affiliate">{{ i18n.scope_affiliate }}</el-radio-button>',
    '        </el-radio-group>',
    '      </el-form-item>',
    '      <el-form-item v-if="editor.form.scope_type !== \'all\'" :error="editor.errors.scope_id">',
    '        <el-select v-model="editor.form.scope_id" :aria-label="i18n.section_audience" filterable :placeholder="editor.form.scope_type === \'group\' ? i18n.choose_group : i18n.choose_affiliate" style="width:100%">',
    '          <el-option v-for="choice in scopeChoices" :key="choice.id" :value="choice.id" :label="choice.label"></el-option>',
    '        </el-select>',
    '      </el-form-item>',
    '      <el-form-item :label="i18n.customer_type">',
    '        <div v-if="editor.errors.customer_type" id="facr-customer-error" role="alert" class="facr-error">{{ editor.errors.customer_type }}</div>',
    '        <el-radio-group v-model="editor.form.customer_type" :aria-label="i18n.customer_type" aria-describedby="facr-customer-help facr-customer-error" :aria-invalid="!!editor.errors.customer_type">',
    '          <el-radio-button value="all">{{ i18n.customer_all }}</el-radio-button>',
    '          <el-radio-button value="b2b">{{ i18n.customer_b2b }}</el-radio-button>',
    '          <el-radio-button value="b2c">{{ i18n.customer_b2c }}</el-radio-button>',
    '        </el-radio-group>',
    '        <div id="facr-customer-help" class="facr-help">{{ i18n.customer_help }}</div>',
    '      </el-form-item>',
    '      <el-divider content-position="left">{{ i18n.section_target }}</el-divider>',
    '      <el-form-item :error="editor.form.target_type === \'all\' ? editor.errors.target_ids : \'\'">',
    '        <el-radio-group v-model="editor.form.target_type" :aria-label="i18n.section_target">',
    '          <el-radio-button value="all">{{ i18n.target_all }}</el-radio-button>',
    '          <el-radio-button v-if="options.has_woo" value="category">{{ i18n.target_category }}</el-radio-button>',
    '          <el-radio-button v-if="options.has_woo" value="product">{{ i18n.target_product }}</el-radio-button>',
    '        </el-radio-group>',
    '      </el-form-item>',
    '      <el-form-item v-if="editor.form.target_type === \'category\'" :error="editor.errors.target_ids">',
    '        <el-select v-model="editor.form.category_ids" :aria-label="i18n.choose_categories" multiple filterable :placeholder="i18n.choose_categories" style="width:100%">',
    '          <el-option v-for="cat in options.categories" :key="cat.id" :value="cat.id" :label="cat.label"></el-option>',
    '        </el-select>',
    '      </el-form-item>',
    '      <el-form-item v-if="editor.form.target_type === \'product\'" :error="editor.errors.target_ids">',
    '        <el-select v-model="editor.form.product_ids" :aria-label="i18n.search_products" multiple filterable remote reserve-keyword :remote-method="searchProducts" :loading="editor.productLoading" :placeholder="i18n.search_products" :no-data-text="editor.productQuery.length < 2 ? i18n.search_min : i18n.search_none" style="width:100%">',
    '          <el-option v-for="product in editor.productOptions" :key="product.id" :value="product.id" :label="product.label"></el-option>',
    '        </el-select>',
    '      </el-form-item>',
    '      <el-divider content-position="left">{{ i18n.section_money }}</el-divider>',
    '      <el-form-item>',
    '        <div v-if="editor.errors.rate" id="facr-rate-error" role="alert" class="facr-error">{{ editor.errors.rate }}</div>',
    '        <div class="facr-money">',
    '          <el-input-number v-model="editor.form.rate" :aria-label="i18n.rate_label" aria-describedby="facr-rate-help facr-rate-error" :aria-invalid="!!editor.errors.rate" :min="0" :max="editor.form.rate_type === \'percentage\' ? 100 : Infinity" :precision="2" :step="1" :controls="false" style="width:140px"></el-input-number>',
    '          <el-radio-group v-model="editor.form.rate_type" :aria-label="i18n.rate_type_label">',
    '            <el-radio-button value="percentage">{{ i18n.rate_percentage }}</el-radio-button>',
    '            <el-radio-button value="flat">{{ i18n.rate_flat }}</el-radio-button>',
    '          </el-radio-group>',
    '        </div>',
    '        <div id="facr-rate-help" class="facr-help">{{ i18n.rate_help }}</div>',
    '      </el-form-item>',
    '      <el-divider content-position="left">{{ i18n.section_time }}</el-divider>',
    '      <el-form-item>',
    '        <div v-if="editor.errors.starts_at || editor.errors.ends_at" id="facr-time-error" role="alert" class="facr-error">{{ editor.errors.starts_at || editor.errors.ends_at }}</div>',
    '        <div class="facr-dates">',
    '          <el-date-picker v-model="editor.form.starts_at" :aria-label="i18n.starts_at" aria-describedby="facr-time-help facr-time-error" type="date" value-format="YYYY-MM-DD" :placeholder="i18n.starts_at" style="width:160px"></el-date-picker>',
    '          <el-date-picker v-model="editor.form.ends_at" :aria-label="i18n.ends_at" aria-describedby="facr-time-help facr-time-error" type="date" value-format="YYYY-MM-DD" :placeholder="i18n.ends_at" style="width:160px"></el-date-picker>',
    '          <el-button link type="primary" @click="presetYear">{{ i18n.preset_year }}</el-button>',
    '        </div>',
    '        <div id="facr-time-help" class="facr-help">{{ i18n.time_help }}</div>',
    '      </el-form-item>',
    '      <el-divider content-position="left">{{ i18n.section_note }}</el-divider>',
    '      <el-form-item>',
    '        <el-input v-model="editor.form.note" :aria-label="i18n.section_note" aria-describedby="facr-note-help" type="textarea" :rows="2" maxlength="200"></el-input>',
    '        <div id="facr-note-help" class="facr-help">{{ i18n.note_help }}</div>',
    '      </el-form-item>',
    '      <el-form-item :label="i18n.status_label">',
    '        <el-radio-group v-model="editor.form.status">',
    '          <el-radio-button value="active">{{ i18n.status_active }}</el-radio-button>',
    '          <el-radio-button value="inactive">{{ i18n.status_inactive_option }}</el-radio-button>',
    '        </el-radio-group>',
    '      </el-form-item>',
    '      <p class="facr-result"><strong>{{ i18n.result_label }}</strong> {{ resultSentence }}</p>',
    '    </el-form>',
    '    <template #footer>',
    '      <el-button :disabled="editor.saving" @click="closeEditor">{{ i18n.cancel }}</el-button>',
    '      <el-button type="primary" :loading="editor.saving" @click="save">{{ i18n.save }}</el-button>',
    '    </template>',
    '  </el-drawer>',
    '</div>'
  ].join( '\n' );

  var app = window.Vue.createApp( {
    template: LIST_TEMPLATE,

    data: function () {
      return {
        i18n: i18n,
        loading: true,
        revision: '',
        mutating: false,
        optionsReady: false,
        loadSeq: 0,
        loadError: '',
        optionsError: '',
        isNarrow: false,
        productSearchSeq: 0,
        editorSeq: 0,
        rules: [],
        shadow: {},
        tie: {},
        defaultRate: cfg.default_rate || '',
        options: { affiliates: [], groups: [], categories: [], has_pro: flag( cfg.has_pro ), has_woo: flag( cfg.has_woo ) },
        filters: { scope: '', target: '', customer_type: '', status: '', q: '' },
        selected: [],
        editor: {
          open: false,
          initial: '',
          revision: '',
          saving: false,
          isEdit: false,
          size: '520px',
          errors: {},
          submitError: '',
          conflict: false,
          draft: null,
          productQuery: '',
          productLoading: false,
          productOptions: [],
          form: blankForm()
        }
      };
    },

    computed: {
      isDirty: function () {
        return this.editor.open && ( !!this.editor.draft || JSON.stringify( this.editor.form ) !== this.editor.initial );
      },
      byId: function () {
        var map = {};
        this.rules.forEach( function ( rule ) {
          map[ rule.id ] = rule;
        } );
        return map;
      },
      visibleRules: function () {
        return H.sortRules( H.filterRules( this.rules, this.filters ) );
      },
      emptyText: function () {
        return H.sprintf( i18n.empty_body, this.defaultRate );
      },
      selectedText: function () {
        return H.sprintf( i18n.selected_count, this.selected.length );
      },
      editorTitle: function () {
        return this.editor.isEdit ? i18n.editor_edit_title : i18n.editor_add_title;
      },
      scopeChoices: function () {
        return this.editor.form.scope_type === 'group' ? this.options.groups : this.options.affiliates;
      },
      /** What the rule will do, in words, kept current while you type; mirrors Labels::describe(). */
      resultSentence: function () {
        var form  = this.editor.form;
        var byId  = {};
        var names = [];
        this.scopeChoices.forEach( function ( choice ) {
          byId[ choice.id ] = choice.label;
        } );
        if ( form.target_type === 'category' ) {
          var cats = {};
          this.options.categories.forEach( function ( cat ) {
            cats[ cat.id ] = cat.name || cat.label;
          } );
          names = form.category_ids.map( function ( id ) {
            return cats[ id ];
          } );
        } else if ( form.target_type === 'product' ) {
          var products = {};
          this.editor.productOptions.forEach( function ( product ) {
            products[ product.id ] = product.label;
          } );
          names = form.product_ids.map( function ( id ) {
            return products[ id ];
          } );
        }
        return H.sentence(
          {
            scope_type: form.scope_type,
            scope_id: form.scope_id,
            customer_type: form.customer_type,
            target_type: form.target_type,
            target_ids: form.target_type === 'category' ? form.category_ids : form.product_ids,
            rate: form.rate === null ? 0 : form.rate,
            rate_type: form.rate_type,
            starts_at: form.starts_at || '',
            ends_at: form.ends_at || ''
          },
          { scope_name: form.scope_id !== null ? ( byId[ form.scope_id ] || '' ) : '', target_names: names, money_template: MONEY.money_template, decimal_separator: MONEY.decimal_separator },
          i18n
        );
      }
    },

    created: function () {
      var vm     = this;
      var params = new URLSearchParams( window.location.search );
      // Sticky Actions column collapses to a three-dot menu under Fluent's own
      // 782px admin breakpoint; addListener is the fallback for older WebViews.
      var narrowQuery = window.matchMedia( '(max-width: 782px)' );
      var onNarrowChange = function ( event ) {
        vm.isNarrow = event.matches;
      };
      vm.isNarrow = narrowQuery.matches;
      if ( narrowQuery.addEventListener ) {
        narrowQuery.addEventListener( 'change', onNarrowChange );
      } else if ( narrowQuery.addListener ) {
        narrowQuery.addListener( onNarrowChange );
      }
      vm.beforeUnload = function ( event ) {
        if ( vm.isDirty || vm.editor.saving ) { event.preventDefault(); event.returnValue = ''; }
      };
      window.addEventListener( 'beforeunload', vm.beforeUnload );
      Promise.all( [ vm.load(), vm.loadOptions() ] ).then( function () {
        // ?action=add&affiliate_id=N from the affiliate profile card: open the
        // editor already scoped to that affiliate once the picker has its options.
        if ( vm.loadError || !vm.optionsReady || params.get( 'action' ) !== 'add' ) {
          return;
        }
        vm.openEditor();
        var affiliateId = parseInt( params.get( 'affiliate_id' ) || '0', 10 );
        if ( affiliateId > 0 ) {
          vm.editor.form.scope_type = 'affiliate';
          vm.editor.form.scope_id   = affiliateId;
          vm.editor.initial = JSON.stringify( vm.editor.form );
        }
      } );
    },

    beforeUnmount: function () {
      window.removeEventListener( 'beforeunload', this.beforeUnload );
    },

    methods: {
      reloadRules: function () {
        this.loadOptions();
        return this.load();
      },
      load: function () {
        var vm = this;
        vm.loading = true;
        var seq = ++vm.loadSeq;
        return api( '/rules' ).then( function ( data ) {
          if ( seq !== vm.loadSeq ) { return; }
          vm.revision    = data.revision || '';
          vm.rules       = data.rules || [];
          vm.shadow      = data.shadow || {};
          vm.tie         = data.tie || {};
          vm.defaultRate = data.default_rate || vm.defaultRate;
          vm.loading     = false;
          vm.loadError   = '';
          return data;
        }, function ( error ) {
          if ( seq !== vm.loadSeq ) { return; }
          vm.loading   = false;
          vm.loadError = error.message || i18n.error_generic;
          notify( 'error', error.message );
        } );
      },
      loadOptions: function () {
        var vm = this;
        return api( '/options' ).then( function ( data ) {
          vm.optionsReady = true;
          vm.optionsError = '';
          vm.options = {
            affiliates: data.affiliates || [],
            groups: data.groups || [],
            categories: data.categories || [],
            has_pro: !! data.has_pro,
            has_woo: !! data.has_woo
          };
          vm.defaultRate = data.default_rate || vm.defaultRate;
        }, function ( error ) {
          vm.optionsReady = false;
          vm.optionsError = error.message || i18n.error_generic;
          notify( 'error', error.message );
        } );
      },
      selectable: function ( row ) {
        return ! row.readonly;
      },
      onSelect: function ( rows ) {
        this.selected = rows.filter( function ( row ) {
          return ! row.readonly;
        } );
      },
      rowClassName: function ( data ) {
        return data.row.readonly ? '' : 'facr-row--editable';
      },
      /** Click-to-edit: ignore the selection checkbox, the actions column, and any interactive control inside a cell. */
      onRowClick: function ( row, column, event ) {
        if ( row.readonly ) {
          return;
        }
        if ( column && ( column.type === 'selection' || ( column.className || '' ).indexOf( 'facr-col-actions' ) !== -1 ) ) {
          return;
        }
        var target = event && event.target;
        if ( target && target.closest && target.closest( 'button, a, input, .el-checkbox, .facr-dots, .el-dropdown, .el-dropdown-menu' ) ) {
          return;
        }
        this.openEditor( row );
      },
      /** The mobile three-dot menu: same actions as the desktop Edit/Delete links, plus a quick status flip. */
      write: function ( path, body, method ) {
        var vm = this;
        if ( vm.mutating || vm.loading || !vm.revision ) { return; }
        vm.mutating = true;
        return api( path, { method: method || 'POST', body: body, revision: vm.revision } ).then( function ( data ) {
          notify( 'success', data.message || ( method === 'DELETE' ? i18n.deleted : i18n.bulk_done ) );
          vm.selected = [];
          return vm.load();
        }, function ( error ) {
          notify( 'error', error.message );
          if ( error.status === 409 ) { vm.loadError = error.message; }
        } ).finally( function () { vm.mutating = false; } );
      },
      onRowCommand: function ( command ) {
        var vm  = this;
        var row = command.row;
        if ( command.action === 'edit' ) {
          vm.openEditor( row );
          return;
        }
        if ( command.action === 'delete' ) {
          vm.remove( row );
          return;
        }
        var action = row.status === 'active' ? 'deactivate' : 'activate';
        vm.write( '/rules/bulk', { action: action, ids: [ row.id ] } );
      },
      badge: function ( row ) {
        return H.badgeFor( row, this.shadow, this.tie, this.byId, i18n );
      },
      statusClass: function ( row ) {
        if ( row.readonly ) {
          return 'woo';
        }
        var status = H.statusOf( row, TODAY );
        if ( status === 'scheduled' ) {
          return 'pending';
        }
        if ( status === 'expired' ) {
          return 'expired';
        }
        if ( status === 'inactive' ) {
          return 'inactive';
        }
        return this.badge( row ).kind ? 'warning' : 'success';
      },
      statusText: function ( row ) {
        if ( row.readonly ) {
          return i18n.status_global;
        }
        var status = H.statusOf( row, TODAY );
        if ( status === 'scheduled' ) {
          return i18n.status_scheduled || i18n.status_effective;
        }
        if ( status === 'expired' ) {
          return i18n.status_expired || i18n.status_effective;
        }
        return status === 'inactive' ? i18n.status_inactive : i18n.status_effective;
      },
      remove: function ( row ) {
        var vm = this;
        confirm( H.sprintf( i18n.confirm_delete_one, vm.defaultRate ) ).then( function ( ok ) {
          if ( ! ok ) {
            return;
          }
          vm.write( '/rules/' + encodeURIComponent( row.id ), undefined, 'DELETE' );
        } );
      },
      bulk: function ( action ) {
        var vm  = this;
        var ids = vm.selected.map( function ( row ) {
          return row.id;
        } );
        if ( ! ids.length ) {
          return;
        }
        var ask = Promise.resolve( true );
        if ( action === 'delete' ) {
          ask = confirm( H.sprintf( i18n.confirm_delete_many, vm.defaultRate ) );
        } else if ( action === 'deactivate' ) {
          ask = confirm( H.sprintf( i18n.confirm_deactivate, vm.defaultRate ) );
        }
        ask.then( function ( ok ) {
          if ( ! ok ) {
            return;
          }
          vm.write( '/rules/bulk', { action: action, ids: ids } );
        } );
      },
      openEditor: function ( rule ) {
        if ( this.loading || this.mutating || this.editor.saving || !this.optionsReady ) { return; }
        var editor = this.editor;
        // A fresh opening (or a reopen after cancel) starts its own session,
        // so a save or product search still in flight from a previous
        // opening can no longer act on this one.
        this.editorSeq++;
        this.productSearchSeq++;
        editor.errors         = {};
        editor.submitError = '';
        editor.conflict = false;
        editor.draft = null;
        editor.productQuery   = '';
        editor.productLoading = false;
        editor.revision = this.revision;
        editor.productOptions = [];
        editor.isEdit         = !! ( rule && rule.id );
        // Full width on a phone, a side panel on a desktop; decided per open, not per resize.
        editor.size = window.innerWidth < 640 ? '100%' : '520px';
        if ( editor.isEdit ) {
          editor.form = {
            id: rule.id,
            status: rule.status,
            scope_type: rule.scope_type,
            scope_id: rule.scope_type === 'all' ? null : rule.scope_id,
            customer_type: rule.customer_type || 'all',
            target_type: rule.target_type,
            category_ids: rule.target_type === 'category' ? rule.target_ids.slice() : [],
            product_ids: rule.target_type === 'product' ? rule.target_ids.slice() : [],
            rate: rule.rate,
            rate_type: rule.rate_type,
            starts_at: rule.starts_at || '',
            ends_at: rule.ends_at || '',
            note: rule.note || ''
          };
          // The server sends {id,label} for every target, so the product select
          // can show names for ids the remote search has not returned yet.
          editor.productOptions = rule.target_type === 'product' ? ( rule.target_options || [] ).slice() : [];
        } else {
          editor.form = blankForm();
        }
        editor.initial = JSON.stringify( editor.form );
        editor.open = true;
      },
      reviewConflict: function () {
        var vm = this;
        var editor = vm.editor;
        if ( editor.saving ) { return; }
        var draft = { form: JSON.parse( JSON.stringify( editor.form ) ), products: editor.productOptions.slice() };
        editor.saving = true;
        return vm.load().then( function ( data ) {
          editor.saving = false;
          if ( !data ) { editor.submitError = vm.loadError; return; }
          var latest = draft.form.id ? vm.byId[ draft.form.id ] : null;
          if ( draft.form.id && !latest ) {
            editor.submitError = i18n.deleted_draft;
            return confirm( i18n.deleted_draft ).then( function ( ok ) {
              if ( !ok ) { return; }
              vm.openEditor();
              draft.form.id = editor.form.id;
              editor.draft = draft;
              vm.restoreDraft();
            } );
          }
          vm.openEditor( latest );
          editor.draft = draft;
          if ( !latest ) { vm.restoreDraft(); }
        } );
      },
      restoreDraft: function () {
        var editor = this.editor;
        if ( editor.saving || !editor.draft ) { return; }
        editor.form = editor.draft.form;
        editor.productOptions = editor.draft.products;
        editor.draft = null;
      },
      closeEditor: function () {
        var vm = this;
        if ( vm.editor.saving ) { return; }
        var discard = vm.isDirty ? EP.ElMessageBox.confirm( i18n.discard_body, i18n.discard_title, {
          confirmButtonText: i18n.discard,
          cancelButtonText: i18n.keep_editing,
          type: 'warning'
        } ).then( function () { return true; }, function () { return false; } ) : Promise.resolve( true );
        return discard.then( function ( ok ) {
          if ( !ok ) { return; }
          vm.editorSeq++;
          vm.productSearchSeq++;
          vm.editor.productLoading = false;
          vm.editor.open = false;
        } );
      },
      searchProducts: function ( query ) {
        var vm     = this;
        var editor = this.editor;
        var form   = this.editor.form;
        var seq = ++vm.productSearchSeq;
        editor.productQuery = String( query || '' ).trim();
        editor.productLoading = false;
        // Keep the options for everything already selected, or the tags lose their labels.
        var keep = editor.productOptions.filter( function ( product ) {
          return form.product_ids.indexOf( product.id ) !== -1;
        } );
        if ( editor.productQuery.length < 2 ) {
          editor.productOptions = keep;
          return;
        }
        editor.productLoading = true;
        return api( '/products', { query: { search: editor.productQuery } } ).then( function ( found ) {
          if ( seq !== vm.productSearchSeq || ! editor.open ) {
            return; // a newer search already landed, or the drawer closed; a slow reply must not overwrite it
          }
          var seen = {};
          keep.forEach( function ( product ) {
            seen[ product.id ] = true;
          } );
          ( found || [] ).forEach( function ( product ) {
            if ( ! seen[ product.id ] ) {
              keep.push( product );
              seen[ product.id ] = true;
            }
          } );
          editor.productOptions = keep;
          editor.productLoading = false;
        }, function ( error ) {
          if ( seq !== vm.productSearchSeq || ! editor.open ) {
            return;
          }
          editor.productLoading = false;
          notify( 'error', error.message );
        } );
      },
      presetYear: function () {
        var preset = H.presetFirst12Months( H.parseYmd( TODAY ) );
        this.editor.form.starts_at = preset.starts_at;
        this.editor.form.ends_at   = preset.ends_at;
      },
      save: function () {
        var vm     = this;
        var editor = vm.editor;
        var form   = editor.form;
        if ( editor.saving ) {
          return;
        }
        // Do not close a different editor session when this write finishes.
        var session = vm.editorSeq;
        editor.saving = true;
        editor.errors = {};
        editor.submitError = '';
        var body = {
          id: form.id,
          status: form.status,
          scope_type: form.scope_type,
          scope_id: form.scope_type === 'all' || form.scope_id === null ? 0 : form.scope_id,
          customer_type: form.customer_type,
          target_type: form.target_type,
          // Only the picker the chosen target type owns is sent, so a stale
          // picker can never smuggle ids into the saved rule.
          target_ids: form.target_type === 'category' ? form.category_ids : ( form.target_type === 'product' ? form.product_ids : [] ),
          rate: form.rate === null ? '' : form.rate,
          rate_type: form.rate_type,
          starts_at: form.starts_at || '',
          ends_at: form.ends_at || '',
          note: form.note
        };
        return api( '/rules', { method: 'POST', body: body, revision: editor.revision } ).then( function ( data ) {
          editor.saving = false;
          // A 200 with no rule.id is not a save, whatever the body looks like —
          // never close the drawer or refresh the list on its behalf.
          if ( ! data || ! data.rule || ! data.rule.id ) {
            notify( 'error', i18n.error_generic );
            return;
          }
          notify( 'success', H.sprintf( i18n.rule_saved, data.rule.labels ? data.rule.labels.sentence : '' ) );
          if ( data.tie && data.tie.length ) {
            EP.ElMessageBox.alert( i18n.tie_warning, i18n.tie_title, { type: 'warning', confirmButtonText: i18n.confirm_ok } ).catch( function () {} );
          }
          if ( session === vm.editorSeq ) {
            editor.open = false;
          }
          vm.load();
        }, function ( error ) {
          editor.saving = false;
          // Same guard as the success path: a stale request's errors must not
          // overwrite a newer editor session's form errors or pop its toast.
          if ( session !== vm.editorSeq ) {
            return;
          }
          if ( error.status === 422 && error.data && error.data.errors ) {
            editor.errors = error.data.errors;
            return;
          }
          editor.submitError = error.message;
          if ( error.status === 409 ) { editor.conflict = true; vm.loadError = error.message; }
          notify( 'error', error.message );
        } );
      }
    }
  } );

  var locale = cfg.locale === 'ro' ? window.ElementPlusLocaleRo : undefined;
  if ( locale ) {
    // Supplement missing accessibility keys without modifying the vendored file.
    locale = Object.assign( {}, locale, { el: Object.assign( {}, locale.el, {
      drawer: { close: i18n.close_dialog },
      dialog: { close: i18n.close_dialog },
      messagebox: Object.assign( {}, locale.el.messagebox, { close: i18n.close_dialog } ),
      dropdown: { toggleDropdown: i18n.toggle_dropdown },
      inputNumber: { decrease: i18n.decrease, increase: i18n.increase },
      datepicker: Object.assign( {}, locale.el.datepicker, {
        dateTablePrompt: i18n.date_day_hint,
        monthTablePrompt: i18n.date_month_hint,
        yearTablePrompt: i18n.date_year_hint,
        selectedDate: i18n.selected_date,
        week: i18n.calendar_week,
        weeksFull: { sun: i18n.sunday, mon: i18n.monday, tue: i18n.tuesday, wed: i18n.wednesday, thu: i18n.thursday, fri: i18n.friday, sat: i18n.saturday }
      } )
    } ) } );
  }
  app.use( EP, { locale: locale } );
  app.mount( mount );
} )();

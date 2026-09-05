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
    '        <el-select v-model="filters.scope" :placeholder="i18n.filter_all_audiences" style="width:150px">',
    '          <el-option value="" :label="i18n.filter_all_audiences"></el-option>',
    '          <el-option value="affiliate" :label="i18n.filter_affiliate"></el-option>',
    '          <el-option value="group" :label="i18n.filter_group"></el-option>',
    '          <el-option value="all" :label="i18n.filter_everyone"></el-option>',
    '        </el-select>',
    '        <el-select v-model="filters.target" :placeholder="i18n.filter_any_target" style="width:150px">',
    '          <el-option value="" :label="i18n.filter_any_target"></el-option>',
    '          <el-option value="product" :label="i18n.filter_product"></el-option>',
    '          <el-option value="category" :label="i18n.filter_category"></el-option>',
    '          <el-option value="all" :label="i18n.filter_all_products"></el-option>',
    '        </el-select>',
    '        <el-select v-model="filters.status" :placeholder="i18n.filter_any_status" style="width:130px">',
    '          <el-option value="" :label="i18n.filter_any_status"></el-option>',
    '          <el-option value="active" :label="i18n.filter_active"></el-option>',
    '          <el-option value="inactive" :label="i18n.filter_inactive"></el-option>',
    '        </el-select>',
    '        <el-input v-model="filters.q" clearable :placeholder="i18n.search_placeholder" style="width:220px"></el-input>',
    '      </div>',
    '      <el-button type="primary" @click="openEditor()">{{ i18n.add_rule }}</el-button>',
    '    </div>',
    '    <div class="fa-affiliate-body-actions-bar facr-bulk-bar" v-if="selected.length">',
    '      <span>{{ selectedText }}</span>',
    '      <span>',
    '        <el-button size="small" @click="bulk(\'activate\')">{{ i18n.activate }}</el-button>',
    '        <el-button size="small" @click="bulk(\'deactivate\')">{{ i18n.deactivate }}</el-button>',
    '        <el-button size="small" type="danger" plain @click="bulk(\'delete\')">{{ i18n.delete }}</el-button>',
    '      </span>',
    '    </div>',
    '    <div class="fa_empty_state" v-if="!loading && loadError">',
    '      <el-alert type="error" :closable="false" show-icon :title="loadError"></el-alert>',
    '    </div>',
    '    <div class="fa_empty_state" v-else-if="!loading && !rules.length">',
    '      <el-empty :description="emptyText">',
    '        <el-button type="primary" @click="openEditor()">{{ i18n.add_first }}</el-button>',
    '      </el-empty>',
    '    </div>',
    '    <div class="fa_table_wrap" v-else>',
    '      <el-table :data="visibleRules" row-key="id" :empty-text="i18n.no_match" @selection-change="onSelect" style="width:100%">',
    '        <el-table-column type="selection" width="44" :selectable="selectable"></el-table-column>',
    '        <el-table-column :label="i18n.col_who" prop="labels.scope" min-width="180"></el-table-column>',
    '        <el-table-column :label="i18n.col_what" prop="labels.target" min-width="200"></el-table-column>',
    '        <el-table-column :label="i18n.col_rate" prop="labels.rate" width="110"></el-table-column>',
    '        <el-table-column :label="i18n.col_window" prop="labels.window" min-width="150"></el-table-column>',
    '        <el-table-column :label="i18n.col_status" min-width="220">',
    '          <template #default="{ row }">',
    '            <span class="fa_badge" :class="statusClass(row)">{{ statusText(row) }}</span>',
    '            <div v-if="badge(row).kind" class="facr-badge-note">{{ badge(row).text }}</div>',
    '          </template>',
    '        </el-table-column>',
    '        <el-table-column :label="i18n.col_note" prop="note" min-width="160"></el-table-column>',
    '        <el-table-column :label="i18n.col_actions" width="150" align="right">',
    '          <template #default="{ row }">',
    '            <template v-if="!row.readonly">',
    '              <el-button link type="primary" size="small" @click="openEditor(row)">{{ i18n.edit }}</el-button>',
    '              <el-button link type="danger" size="small" @click="remove(row)">{{ i18n.delete }}</el-button>',
    '            </template>',
    '            <el-tooltip v-else :content="i18n.readonly_hint" placement="top">',
    '              <span class="facr-readonly">{{ i18n.readonly_short }}</span>',
    '            </el-tooltip>',
    '          </template>',
    '        </el-table-column>',
    '      </el-table>',
    '    </div>',
    '  </div>',
    '  <el-drawer v-model="editor.open" :title="editorTitle" :size="editor.size" class="fa_common_drawer" :close-on-click-modal="false" :destroy-on-close="true">',
    '    <el-form label-position="top" @submit.prevent="save">',
    '      <el-divider content-position="left">{{ i18n.section_audience }}</el-divider>',
    '      <el-form-item>',
    '        <el-radio-group v-model="editor.form.scope_type" @change="editor.form.scope_id = null">',
    '          <el-radio-button value="all">{{ i18n.scope_all }}</el-radio-button>',
    '          <el-radio-button v-if="options.has_pro" value="group">{{ i18n.scope_group }}</el-radio-button>',
    '          <el-radio-button value="affiliate">{{ i18n.scope_affiliate }}</el-radio-button>',
    '        </el-radio-group>',
    '      </el-form-item>',
    '      <el-form-item v-if="editor.form.scope_type !== \'all\'" :error="editor.errors.scope_id">',
    '        <el-select v-model="editor.form.scope_id" filterable :placeholder="editor.form.scope_type === \'group\' ? i18n.choose_group : i18n.choose_affiliate" style="width:100%">',
    '          <el-option v-for="choice in scopeChoices" :key="choice.id" :value="choice.id" :label="choice.label"></el-option>',
    '        </el-select>',
    '      </el-form-item>',
    '      <el-divider content-position="left">{{ i18n.section_target }}</el-divider>',
    '      <el-form-item :error="editor.form.target_type === \'all\' ? editor.errors.target_ids : \'\'">',
    '        <el-radio-group v-model="editor.form.target_type">',
    '          <el-radio-button value="all">{{ i18n.target_all }}</el-radio-button>',
    '          <el-radio-button v-if="options.has_woo" value="category">{{ i18n.target_category }}</el-radio-button>',
    '          <el-radio-button v-if="options.has_woo" value="product">{{ i18n.target_product }}</el-radio-button>',
    '        </el-radio-group>',
    '      </el-form-item>',
    '      <el-form-item v-if="editor.form.target_type === \'category\'" :error="editor.errors.target_ids">',
    '        <el-select v-model="editor.form.category_ids" multiple filterable :placeholder="i18n.choose_categories" style="width:100%">',
    '          <el-option v-for="cat in options.categories" :key="cat.id" :value="cat.id" :label="cat.label"></el-option>',
    '        </el-select>',
    '      </el-form-item>',
    '      <el-form-item v-if="editor.form.target_type === \'product\'" :error="editor.errors.target_ids">',
    '        <el-select v-model="editor.form.product_ids" multiple filterable remote reserve-keyword :remote-method="searchProducts" :loading="editor.productLoading" :placeholder="i18n.search_products" :no-data-text="editor.productQuery.length < 2 ? i18n.search_min : i18n.search_none" style="width:100%">',
    '          <el-option v-for="product in editor.productOptions" :key="product.id" :value="product.id" :label="product.label"></el-option>',
    '        </el-select>',
    '      </el-form-item>',
    '      <el-divider content-position="left">{{ i18n.section_money }}</el-divider>',
    '      <el-form-item :error="editor.errors.rate">',
    '        <div class="facr-money">',
    '          <el-input-number v-model="editor.form.rate" :min="0" :max="editor.form.rate_type === \'percentage\' ? 100 : Infinity" :precision="2" :step="1" :controls="false" style="width:140px"></el-input-number>',
    '          <el-radio-group v-model="editor.form.rate_type">',
    '            <el-radio-button value="percentage">{{ i18n.rate_percentage }}</el-radio-button>',
    '            <el-radio-button value="flat">{{ i18n.rate_flat }}</el-radio-button>',
    '          </el-radio-group>',
    '        </div>',
    '        <div class="facr-help">{{ i18n.rate_help }}</div>',
    '      </el-form-item>',
    '      <el-divider content-position="left">{{ i18n.section_time }}</el-divider>',
    '      <el-form-item :error="editor.errors.starts_at || editor.errors.ends_at">',
    '        <div class="facr-dates">',
    '          <el-date-picker v-model="editor.form.starts_at" type="date" value-format="YYYY-MM-DD" :placeholder="i18n.starts_at" style="width:160px"></el-date-picker>',
    '          <el-date-picker v-model="editor.form.ends_at" type="date" value-format="YYYY-MM-DD" :placeholder="i18n.ends_at" style="width:160px"></el-date-picker>',
    '          <el-button link type="primary" @click="presetYear">{{ i18n.preset_year }}</el-button>',
    '        </div>',
    '        <div class="facr-help">{{ i18n.time_help }}</div>',
    '      </el-form-item>',
    '      <el-divider content-position="left">{{ i18n.section_note }}</el-divider>',
    '      <el-form-item>',
    '        <el-input v-model="editor.form.note" type="textarea" :rows="2" maxlength="200"></el-input>',
    '        <div class="facr-help">{{ i18n.note_help }}</div>',
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
    '      <el-button @click="closeEditor">{{ i18n.cancel }}</el-button>',
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
        loadError: '',
        productSearchSeq: 0,
        editorSeq: 0,
        rules: [],
        shadow: {},
        tie: {},
        defaultRate: cfg.default_rate || '',
        options: { affiliates: [], groups: [], categories: [], has_pro: flag( cfg.has_pro ), has_woo: flag( cfg.has_woo ) },
        filters: { scope: '', target: '', status: '', q: '' },
        selected: [],
        editor: {
          open: false,
          saving: false,
          isEdit: false,
          size: '520px',
          errors: {},
          productQuery: '',
          productLoading: false,
          productOptions: [],
          form: blankForm()
        }
      };
    },

    computed: {
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
      vm.load();
      vm.loadOptions().then( function () {
        // ?action=add&affiliate_id=N from the affiliate profile card: open the
        // editor already scoped to that affiliate once the picker has its options.
        if ( params.get( 'action' ) !== 'add' ) {
          return;
        }
        vm.openEditor();
        var affiliateId = parseInt( params.get( 'affiliate_id' ) || '0', 10 );
        if ( affiliateId > 0 ) {
          vm.editor.form.scope_type = 'affiliate';
          vm.editor.form.scope_id   = affiliateId;
        }
      } );
    },

    methods: {
      load: function () {
        var vm = this;
        vm.loading = true;
        return api( '/rules' ).then( function ( data ) {
          vm.rules       = data.rules || [];
          vm.shadow      = data.shadow || {};
          vm.tie         = data.tie || {};
          vm.defaultRate = data.default_rate || vm.defaultRate;
          vm.loading     = false;
          vm.loadError   = '';
        }, function ( error ) {
          vm.loading   = false;
          vm.loadError = error.message || i18n.error_generic;
          notify( 'error', error.message );
        } );
      },
      loadOptions: function () {
        var vm = this;
        return api( '/options' ).then( function ( data ) {
          vm.options = {
            affiliates: data.affiliates || [],
            groups: data.groups || [],
            categories: data.categories || [],
            has_pro: !! data.has_pro,
            has_woo: !! data.has_woo
          };
          vm.defaultRate = data.default_rate || vm.defaultRate;
        }, function ( error ) {
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
          api( '/rules/' + encodeURIComponent( row.id ), { method: 'DELETE' } ).then( function () {
            notify( 'success', i18n.deleted );
            vm.load();
          }, function ( error ) {
            notify( 'error', error.message );
          } );
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
          api( '/rules/bulk', { method: 'POST', body: { action: action, ids: ids } } ).then( function ( data ) {
            notify( 'success', data.message || i18n.bulk_done );
            vm.selected = [];
            vm.load();
          }, function ( error ) {
            notify( 'error', error.message );
          } );
        } );
      },
      openEditor: function ( rule ) {
        var editor = this.editor;
        // A fresh opening (or a reopen after cancel) starts its own session,
        // so a save or product search still in flight from a previous
        // opening can no longer act on this one.
        this.editorSeq++;
        this.productSearchSeq++;
        editor.errors         = {};
        editor.productQuery   = '';
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
        editor.open = true;
      },
      closeEditor: function () {
        this.editorSeq++;
        this.productSearchSeq++;
        this.editor.open = false;
      },
      searchProducts: function ( query ) {
        var vm     = this;
        var editor = this.editor;
        var form   = this.editor.form;
        editor.productQuery = String( query || '' ).trim();
        // Keep the options for everything already selected, or the tags lose their labels.
        var keep = editor.productOptions.filter( function ( product ) {
          return form.product_ids.indexOf( product.id ) !== -1;
        } );
        if ( editor.productQuery.length < 2 ) {
          editor.productOptions = keep;
          return;
        }
        editor.productLoading = true;
        var seq = ++vm.productSearchSeq;
        api( '/products', { query: { search: editor.productQuery } } ).then( function ( found ) {
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
        // Captured now: if the drawer is closed and reopened (or closed for
        // good) before this request lands, the response below must not close
        // or reload on behalf of a session that is no longer current.
        var session = vm.editorSeq;
        editor.saving = true;
        editor.errors = {};
        var body = {
          id: form.id,
          status: form.status,
          scope_type: form.scope_type,
          scope_id: form.scope_type === 'all' || form.scope_id === null ? 0 : form.scope_id,
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
        api( '/rules', { method: 'POST', body: body } ).then( function ( data ) {
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
            vm.load();
          }
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
          notify( 'error', error.message );
        } );
      }
    }
  } );

  app.use( EP );
  app.mount( mount );
} )();
